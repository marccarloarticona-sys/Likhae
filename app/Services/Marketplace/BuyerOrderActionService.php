<?php

namespace App\Services\Marketplace;

use App\Events\ShipmentTrackingUpdated;
use App\Models\Admin\Notification;
use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
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
            $order->loadMissing('returnRefundRequests.items', 'returnRefundRequests.sellerRequests.items');
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
            && $shipments->every(fn ($shipment): bool => in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true))
            && $shipments->contains(fn ($shipment): bool => $shipment->current_status === 'DELIVERED');
        $deliveredAt = $shipments
            ->flatMap(fn ($shipment) => $shipment->events)
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        $returnDeadline = $deliveredAt?->copy()->addDays(5);
        $autoConfirmAt = $allDeliveredWithTimestamps && $deliveredAt
            ? $deliveredAt->copy()->addDays(3)
            : null;
        $activeDisputes = $order->disputes
            ->where('type', 'RETURN_REFUND')
            ->whereIn('status', self::ACTIVE_DISPUTE_STATUSES);
        $activeDispute = $activeDisputes
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
        $activeRequests = $returnRefundStorageReady
            ? $order->returnRefundRequests->whereNotIn('status', ['COMPLETED', 'REJECTED', 'RESOLVED'])
            : collect();
        $activeCase = $activeDispute ?: $activeRequest;
        $confirmableShipmentIds = $this->confirmableShipmentIdsFor($order, $shipments, $activeDisputes, $activeRequests);
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
            'confirmable_shipment_ids' => $confirmableShipmentIds,
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

    /** @return list<int> */
    public function confirmableShipmentIds(Order $order, bool $returnRefundStorageReady = true): array
    {
        $order->loadMissing('sellerOrders.shipments', 'disputes');
        if ($returnRefundStorageReady) {
            $order->loadMissing('returnRefundRequests.items', 'returnRefundRequests.sellerRequests.items');
        }

        $activeDisputes = $order->disputes
            ->where('type', 'RETURN_REFUND')
            ->whereIn('status', self::ACTIVE_DISPUTE_STATUSES);
        $activeRequests = $returnRefundStorageReady
            ? $order->returnRefundRequests->whereNotIn('status', ['COMPLETED', 'REJECTED', 'RESOLVED'])
            : collect();

        return $this->confirmableShipmentIdsFor(
            $order,
            $order->sellerOrders->flatMap(fn ($sellerOrder) => $sellerOrder->shipments),
            $activeDisputes,
            $activeRequests,
        );
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

    public function confirmShipmentReceipt(Order $order, Shipment $shipment, User $buyer, bool $returnRefundStorageReady = true): bool
    {
        return DB::transaction(function () use ($order, $shipment, $buyer, $returnRefundStorageReady): bool {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedOrder->buyer_user_id !== (int) $buyer->id) {
                return false;
            }

            $lockedShipment = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->first();
            if (! $lockedShipment || $lockedOrder->sellerOrders()->whereKey($lockedShipment->seller_order_id)->doesntExist()) {
                return false;
            }

            $lockedOrder->load('sellerOrders.shipments.events', 'disputes', 'payments');
            if ($returnRefundStorageReady) {
                $lockedOrder->load('returnRefundRequests.items', 'returnRefundRequests.sellerRequests.items');
            }
            $sellerOrder = $lockedOrder->sellerOrders->firstWhere('id', (int) $lockedShipment->seller_order_id);
            $lockedShipment = $sellerOrder?->shipments->firstWhere('id', (int) $lockedShipment->id);
            if (! $lockedShipment) {
                return false;
            }
            if ($lockedShipment->current_status === 'COMPLETED') {
                return true;
            }
            if ($lockedOrder->status !== 'PROCESSING') {
                return false;
            }

            $state = $this->state($lockedOrder, $returnRefundStorageReady);
            if (! in_array((int) $lockedShipment->id, $state['confirmable_shipment_ids'], true)) {
                return false;
            }

            $completedAt = now();
            $lockedShipment->update(['current_status' => 'COMPLETED']);
            if (! $lockedShipment->events->contains('status', 'COMPLETED')) {
                ShipmentEvent::query()->create([
                    'shipment_id' => $lockedShipment->id,
                    'status' => 'COMPLETED',
                    'actor_user_id' => $buyer->id,
                    'notes' => 'Buyer confirmed receipt for this parcel.',
                    'occurred_at' => $completedAt,
                ]);
            }

            event(ShipmentTrackingUpdated::fromShipment($lockedShipment));

            if ($sellerOrder->shipments->every(fn (Shipment $parcel): bool => $parcel->current_status === 'COMPLETED')) {
                $sellerOrder->update(['status' => 'COMPLETED']);
            }
            $this->completeOrderIfEveryShipmentReceived($lockedOrder, $completedAt);

            return true;
        }, attempts: 3);
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
                || $sellerOrder->shipments->contains(fn ($shipment): bool => ! in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true)))) {
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
                if ($shipment->current_status === 'COMPLETED') {
                    continue;
                }
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

    private function shipmentHasActiveCase(Shipment $shipment, Collection $activeDisputes, Collection $activeRequests): bool
    {
        if ($activeDisputes->contains(fn ($dispute): bool => $dispute->shipment_id
            ? (int) $dispute->shipment_id === (int) $shipment->id
            : ($dispute->seller_order_id
                ? (int) $dispute->seller_order_id === (int) $shipment->seller_order_id
                : true))) {
            return true;
        }

        foreach ($activeRequests as $request) {
            $requestItemIds = $request->items->pluck('order_item_id')
                ->merge($request->sellerRequests->flatMap(fn ($sellerRequest) => $sellerRequest->items->pluck('order_item_id')))
                ->filter()
                ->map(fn ($id): int => (int) $id);
            if ($requestItemIds->isEmpty() || $requestItemIds->contains((int) $shipment->order_item_id)) {
                return true;
            }
        }

        return false;
    }

    private function confirmableShipmentIdsFor(Order $order, Collection $shipments, Collection $activeDisputes, Collection $activeRequests): array
    {
        return $shipments
            ->filter(fn (Shipment $shipment): bool => $order->status === 'PROCESSING'
                && $shipment->current_status === 'DELIVERED'
                && ! $this->shipmentHasActiveCase($shipment, $activeDisputes, $activeRequests))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function completeOrderIfEveryShipmentReceived(Order $order, CarbonInterface $completedAt): void
    {
        $order->loadMissing('sellerOrders.shipments');
        if ($order->sellerOrders->isEmpty()
            || $order->sellerOrders->contains(fn ($sellerOrder): bool => $sellerOrder->shipments->isEmpty()
                || $sellerOrder->shipments->contains(fn (Shipment $shipment): bool => $shipment->current_status !== 'COMPLETED'))) {
            return;
        }

        foreach ($order->sellerOrders as $sellerOrder) {
            if ($sellerOrder->status !== 'COMPLETED') {
                $sellerOrder->update(['status' => 'COMPLETED']);
            }
        }

        if ($order->status !== 'COMPLETED') {
            $order->update(['status' => 'COMPLETED', 'completed_at' => $completedAt]);
        }
    }
}
