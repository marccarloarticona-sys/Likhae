<?php

namespace App\Services\Marketplace;

use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Logistics\ShipmentEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class BuyerOrderActionService
{
    public const ACTIVE_DISPUTE_STATUSES = ['OPEN', 'UNDER_REVIEW'];

    public const AUTOMATIC_RECEIPT_NOTE = 'Order automatically confirmed received after 3 days without Buyer confirmation.';

    /** @return array<string, mixed> */
    public function state(Order $order, bool $returnRefundStorageReady = true): array
    {
        $order->loadMissing('sellerOrders.shipment.events', 'disputes');

        if ($returnRefundStorageReady) {
            $order->loadMissing('returnRefundRequests.sellerRequests');
        }

        $deliveryStates = $order->sellerOrders->map(function ($sellerOrder): array {
            $shipment = $sellerOrder->shipment;
            $deliveredEvents = $shipment?->events
                ->where('status', 'DELIVERED')
                ->filter(fn ($event): bool => $event->occurred_at instanceof CarbonInterface)
                ?? collect();

            return [
                'delivered' => $shipment && in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true),
                'has_delivery_timestamp' => $deliveredEvents->isNotEmpty(),
            ];
        });
        $allDelivered = $order->sellerOrders->isNotEmpty()
            && $deliveryStates->every(fn (array $state): bool => $state['delivered']);
        $allDeliveredWithTimestamps = $allDelivered
            && $deliveryStates->every(fn (array $state): bool => $state['has_delivery_timestamp']);
        $allAwaitingReceipt = $order->sellerOrders->isNotEmpty()
            && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipment?->current_status === 'DELIVERED');
        $deliveredAt = $order->sellerOrders
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipment?->events ?? collect())
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        $returnDeadline = $deliveredAt?->copy()->addDays(5);
        $autoConfirmAt = $allDeliveredWithTimestamps && $deliveredAt
            ? $deliveredAt->copy()->addHours(72)
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

        return [
            'all_delivered' => $allDelivered,
            'delivered_at' => $deliveredAt,
            'return_deadline' => $returnDeadline,
            'auto_confirm_at' => $autoConfirmAt,
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

    /**
     * Apply the shared receipt transition. The caller must hold a transaction
     * and have rechecked the applicable receipt eligibility while locking the order.
     */
    public function completeReceipt(Order $order, ?User $actor, string $reason): bool
    {
        $order->loadMissing('sellerOrders.shipment.events');

        if ($order->status !== 'PROCESSING' || $order->sellerOrders->isEmpty()
            || $order->sellerOrders->contains(fn ($sellerOrder): bool => ! $sellerOrder->shipment)) {
            return false;
        }

        $completedAt = now();
        $order->update([
            'status' => 'COMPLETED',
            'completed_at' => $completedAt,
        ]);

        foreach ($order->sellerOrders as $sellerOrder) {
            $sellerOrder->update(['status' => 'COMPLETED']);
            $shipment = $sellerOrder->shipment;
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
