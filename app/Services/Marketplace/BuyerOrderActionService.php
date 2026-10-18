<?php

namespace App\Services\Marketplace;

use App\Events\ShipmentTrackingUpdated;
use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Logistics\ShipmentEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuyerOrderActionService
{
    public const ACTIVE_DISPUTE_STATUSES = ['OPEN', 'UNDER_REVIEW'];

    public const AUTOMATIC_RECEIPT_NOTE = 'Order automatically confirmed received after 3 days without Buyer confirmation.';

    /** @return array<string, mixed> */
    public function state(Order $order, bool $returnRefundStorageReady = true): array
    {
        $order->loadMissing('sellerOrders.shipments.events', 'disputes', 'payments');

        if ($returnRefundStorageReady) {
            $order->loadMissing('returnRefundRequests.sellerRequests');
        }

        $shipments = $order->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->shipments);
        $deliveryStates = $shipments->map(function ($shipment): array {
            $deliveredEvents = $shipment->events
                ->where('status', 'DELIVERED')
                ->filter(fn ($event): bool => $event->occurred_at instanceof CarbonInterface);

            return [
                'delivered' => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true),
                'has_delivery_timestamp' => $deliveredEvents->isNotEmpty(),
            ];
        });
        $allSellerOrdersHaveParcels = $order->sellerOrders->isNotEmpty()
            && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipments->isNotEmpty());
        $allDelivered = $allSellerOrdersHaveParcels
            && $shipments->isNotEmpty()
            && $deliveryStates->every(fn (array $state): bool => $state['delivered']);
        $allDeliveredWithTimestamps = $allDelivered
            && $deliveryStates->every(fn (array $state): bool => $state['has_delivery_timestamp']);
        $allAwaitingReceipt = $allSellerOrdersHaveParcels
            && $shipments->isNotEmpty()
            && $shipments->every(fn ($shipment): bool => $shipment->current_status === 'DELIVERED');
        $deliveredAt = $shipments
            ->flatMap(fn ($shipment) => $shipment->events)
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        $returnDeadline = $deliveredAt?->copy()->addDays(5);
        $autoConfirmAt = $allDeliveredWithTimestamps && $deliveredAt
            ? $deliveredAt->copy()->addDays(3)
            : null;
        $activeDispute = $order->disputes
            ->where('type', 'RETURN_REFUND')
            ->whereIn('status', self::ACTIVE_DISPUTE_STATUSES)
            ->sortByDesc('opened_at')
            ->first();
        $latestRequest = $returnRefundStorageReady
            ? $order->returnRefundRequests->sortByDesc('submitted_at')->first()
            : null;
        $activeRequest = $returnRefundStorageReady
            ? $order->returnRefundRequests
                ->whereNotIn('status', ['COMPLETED', 'REJECTED', 'RESOLVED'])
                ->sortByDesc('submitted_at')
                ->first()
            : null;
        $activeCase = $activeDispute ?: $activeRequest;
        $activeSellerOrders = $order->sellerOrders->where('status', '!=', 'CANCELLED');
        $canCancel = in_array($order->status, ['PLACED', 'PROCESSING', 'PARTIALLY_CANCELLED'], true)
            && $activeSellerOrders->isNotEmpty()
            && $activeSellerOrders->every(fn ($sellerOrder): bool => in_array($sellerOrder->status, ['PLACED', 'CONFIRMED', 'PREPARING'], true)
                && $sellerOrder->shipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['PLACED', 'CONFIRMED', 'PREPARING'], true)))
            && ! $order->payments->contains(fn ($payment): bool => $payment->method === 'ONLINE' && $payment->status === 'PAID')
            && ! $activeCase;

        return [
            'all_delivered' => $allDelivered,
            'delivered_at' => $deliveredAt,
            'return_deadline' => $returnDeadline,
            'auto_confirm_at' => $autoConfirmAt,
            'can_cancel' => $canCancel,
            'active_dispute' => $activeCase,
            'latest_request' => $latestRequest,
            'can_confirm_received' => $order->status === 'PROCESSING' && $allAwaitingReceipt && ! $activeCase,
            'can_auto_confirm_received' => $order->status === 'PROCESSING'
                && $allDeliveredWithTimestamps
                && $autoConfirmAt instanceof CarbonInterface
                && now()->greaterThanOrEqualTo($autoConfirmAt)
                && ! $activeCase,
            'can_request_return' => $returnRefundStorageReady
                && in_array($order->status, ['PROCESSING', 'COMPLETED'], true)
                && $allDelivered
                && $returnDeadline instanceof CarbonInterface
                && now()->lte($returnDeadline)
                && ! $activeCase,
        ];
    }

    public function returnRefundStorageReady(): bool
    {
        return Schema::hasTable('return_refund_requests')
            && Schema::hasTable('return_refund_request_items')
            && Schema::hasTable('seller_return_refund_requests')
            && Schema::hasTable('return_refund_evidence')
            && Schema::hasTable('refund_payouts')
            && Schema::hasTable('return_refund_events');
    }

    public function confirmReceipt(Order $order, User $buyer, bool $returnRefundStorageReady = true): bool
    {
        return DB::transaction(function () use ($order, $buyer, $returnRefundStorageReady): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->buyer_user_id !== (int) $buyer->id) {
                return false;
            }
            $locked->load('sellerOrders.shipments.events', 'disputes');

            if ($locked->status === 'COMPLETED') {
                return $locked->sellerOrders->isNotEmpty()
                    && $locked->sellerOrders->every(fn ($sellerOrder): bool =>
                        $sellerOrder->status === 'COMPLETED'
                        && $sellerOrder->shipments->isNotEmpty()
                        && $sellerOrder->shipments->every(fn ($shipment): bool => $shipment->current_status === 'COMPLETED'));
            }

            $state = $this->state($locked, $returnRefundStorageReady);
            if ($state['active_dispute'] || ! $state['can_confirm_received']) {
                return false;
            }

            return $this->completeReceipt($locked, $buyer, 'Buyer confirmed receipt.');
        });
    }

    /**
     * Apply the shared receipt transition. The caller must hold a transaction
     * and have rechecked the applicable receipt eligibility while locking the order.
     */
    public function completeReceipt(Order $order, ?User $actor, string $reason): bool
    {
        $order->loadMissing('sellerOrders.shipments.events');

        if ($order->status === 'COMPLETED') {
            return $order->sellerOrders->isNotEmpty()
                && $order->sellerOrders->every(fn ($sellerOrder): bool =>
                    $sellerOrder->status === 'COMPLETED'
                    && $sellerOrder->shipments->isNotEmpty()
                    && $sellerOrder->shipments->every(fn ($shipment): bool => $shipment->current_status === 'COMPLETED'));
        }

        if ($order->status !== 'PROCESSING' || $order->sellerOrders->isEmpty()
            || $order->sellerOrders->contains(fn ($sellerOrder): bool => $sellerOrder->shipments->isEmpty()
                || $sellerOrder->shipments->contains(fn ($shipment): bool => $shipment->current_status !== 'DELIVERED'))) {
            return false;
        }

        $completedAt = now();
        $order->update([
            'status' => 'COMPLETED',
            'completed_at' => $completedAt,
        ]);

        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerOrder->update(['status' => 'COMPLETED']);
            foreach ($sellerOrder->shipments as $shipment) {
                $shipment->update(['current_status' => 'COMPLETED']);

                if (! $shipment->events->contains('status', 'COMPLETED')) {
                    ShipmentEvent::query()->create([
                        'shipment_id' => $shipment->id,
                        'status' => 'COMPLETED',
                        'actor_user_id' => $actor?->id,
                        'notes' => $reason,
                        'occurred_at' => $completedAt,
                    ]);
                }

                event(ShipmentTrackingUpdated::fromShipment($shipment));
            }
        }

        if ($actor === null) {
            Notification::query()->create([
                'user_id' => $order->buyer_user_id,
                'type' => 'ORDER_COMPLETED',
                'title' => 'Order automatically completed',
                'message' => 'Your order '.$order->order_number.' was automatically marked as received 3 days after all parcels were delivered. Return and refund eligibility remains available through the existing deadline when applicable.',
                'reference_type' => Order::class,
                'reference_id' => $order->id,
                'action_url' => route('buyer.orders.show', $order),
            ]);
        }

        return true;
    }
}
