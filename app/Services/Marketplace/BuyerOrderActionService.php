<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Order;
use Carbon\CarbonInterface;

class BuyerOrderActionService
{
    public const ACTIVE_DISPUTE_STATUSES = ['OPEN', 'UNDER_REVIEW'];

    /** @return array<string, mixed> */
    public function state(Order $order, bool $returnRefundStorageReady = true): array
    {
        $order->loadMissing('sellerOrders.shipment.events', 'disputes');

        if ($returnRefundStorageReady) {
            $order->loadMissing('returnRefundRequests.sellerRequests');
        }

        $allDelivered = $order->sellerOrders->isNotEmpty()
            && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipment
                && in_array($sellerOrder->shipment->current_status, ['DELIVERED', 'COMPLETED'], true));
        $allAwaitingReceipt = $order->sellerOrders->isNotEmpty()
            && $order->sellerOrders->every(fn ($sellerOrder): bool => $sellerOrder->shipment?->current_status === 'DELIVERED');
        $deliveredAt = $order->sellerOrders
            ->flatMap(fn ($sellerOrder) => $sellerOrder->shipment?->events ?? collect())
            ->where('status', 'DELIVERED')
            ->max('occurred_at');
        $returnDeadline = $deliveredAt?->copy()->addDays(5);
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
            'active_dispute' => $activeCase,
            'latest_request' => $latestRequest,
            'can_confirm_received' => $order->status === 'PROCESSING' && $allAwaitingReceipt && ! $activeCase,
            'can_request_return' => $returnRefundStorageReady
                && in_array($order->status, ['PROCESSING', 'COMPLETED'], true)
                && $allDelivered
                && $returnDeadline instanceof CarbonInterface
                && now()->lte($returnDeadline)
                && ! $activeCase,
        ];
    }
}
