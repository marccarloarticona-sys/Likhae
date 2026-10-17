<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Order;
use App\Models\Seller\SellerOrder;
use Illuminate\Database\Eloquent\Builder;

class BuyerOrderTabService
{
    public const LABELS = [
        'ALL' => 'All',
        'TO_PAY' => 'To Pay',
        'TO_SHIP' => 'To Ship',
        'TO_RECEIVE' => 'To Receive',
        'COMPLETED' => 'Completed',
        'RETURNS' => 'Return / Refund',
        'CANCELLED' => 'Cancelled',
    ];

    private const SELLER_ACCEPTED_STATES = ['CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'];

    private const SHIPPED_SELLER_STATES = ['PICKED_UP', 'COMPLETED'];

    private const RECEIVING_SHIPMENT_STATES = [
        'PICKED_UP',
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
        'DELIVERY_FAILED',
    ];

    public function normalize(mixed $status): string
    {
        $status = strtoupper(trim((string) $status));
        $status = str_replace([' ', '-', '/'], '_', $status);

        return match ($status) {
            '', 'ALL' => 'ALL',
            'RETURN', 'RETURN_REFUND', 'RETURN_REFUNDS' => 'RETURNS',
            default => $status,
        };
    }

    public function apply(Builder $query, string $tab, bool $returnRefundReady): Builder
    {
        return match ($tab) {
            'TO_PAY' => $this->toPay($query, $returnRefundReady),
            'TO_SHIP' => $this->toShip($query, $returnRefundReady),
            'TO_RECEIVE' => $this->toReceive($query, $returnRefundReady),
            'COMPLETED' => $query
                ->where('status', 'COMPLETED')
                ->when($returnRefundReady, fn (Builder $query): Builder => $query->whereDoesntHave('returnRefundRequests')),
            'CANCELLED' => $query->whereIn('status', ['CANCELLED', 'PARTIALLY_CANCELLED']),
            'RETURNS' => $this->returns($query, $returnRefundReady),
            // Keep existing saved URLs usable while the buyer tabs use the new mapping.
            'PLACED' => $query->where('status', 'PLACED'),
            'PROCESSING' => $this->legacyProcessing($query),
            'DELIVERED' => $this->legacyDelivered($query),
            default => $query,
        };
    }

    /** @return array<string, int> */
    public function counts(Builder $buyerOrders, bool $returnRefundReady): array
    {
        $counts = [];
        foreach (array_keys(self::LABELS) as $tab) {
            $counts[$tab] = $tab === 'ALL'
                ? (clone $buyerOrders)->count()
                : $this->apply(clone $buyerOrders, $tab, $returnRefundReady)->count();
        }

        return $counts;
    }

    public function forOrder(Order $order, bool $returnRefundReady): string
    {
        if (in_array($order->status, ['CANCELLED', 'PARTIALLY_CANCELLED'], true)) {
            return 'CANCELLED';
        }
        $sellerOrders = $order->sellerOrders;
        $shipments = $sellerOrders->flatMap->shipments;
        $payments = $order->payments;

        if ($returnRefundReady && $order->relationLoaded('returnRefundRequests') && $order->returnRefundRequests->isNotEmpty()) {
            return 'RETURNS';
        }
        if ($order->status === 'COMPLETED') {
            return 'COMPLETED';
        }

        if ($this->awaitingOnlinePayment($order, $payments)) {
            return 'TO_PAY';
        }

        $sellerHasShipped = $sellerOrders->contains(fn (SellerOrder $sellerOrder): bool => in_array($sellerOrder->status, self::SHIPPED_SELLER_STATES, true));
        $shipmentHasShipped = $shipments->contains(fn ($shipment): bool => in_array($shipment->current_status, self::RECEIVING_SHIPMENT_STATES, true));
        if ($sellerHasShipped || $shipmentHasShipped) {
            return 'TO_RECEIVE';
        }

        if (in_array($order->status, ['PLACED', 'PROCESSING'], true)
            && $sellerOrders->isNotEmpty()
            && $sellerOrders->every(fn (SellerOrder $sellerOrder): bool => $sellerOrder->status === 'PLACED')) {
            return 'TO_PAY';
        }

        if (in_array($order->status, ['PLACED', 'PROCESSING'], true)
            && $sellerOrders->contains(fn (SellerOrder $sellerOrder): bool => in_array($sellerOrder->status, self::SELLER_ACCEPTED_STATES, true))) {
            return 'TO_SHIP';
        }

        return $order->status;
    }

    public function forSellerOrder(Order $order, SellerOrder $sellerOrder): string
    {
        if ($sellerOrder->status === 'CANCELLED' || $order->status === 'CANCELLED') {
            return 'CANCELLED';
        }
        if ($order->status === 'COMPLETED' && $sellerOrder->status === 'COMPLETED') {
            return 'COMPLETED';
        }

        $sellerShipments = $sellerOrder->shipments;
        $hasShippedParcel = $sellerShipments->contains(
            fn ($shipment): bool => in_array($shipment->current_status, self::RECEIVING_SHIPMENT_STATES, true),
        );

        if (in_array($sellerOrder->status, self::SHIPPED_SELLER_STATES, true) || $hasShippedParcel) {
            return 'TO_RECEIVE';
        }

        if (in_array($sellerOrder->status, self::SELLER_ACCEPTED_STATES, true)) {
            return 'TO_SHIP';
        }

        return $sellerOrder->status;
    }

    private function toPay(Builder $query, bool $returnRefundReady): Builder
    {
        return $query
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query
                        ->where('orders.status', 'PLACED')
                        ->where('orders.payment_status', 'PENDING')
                        ->whereHas('payments', fn (Builder $payment): Builder => $payment->where('method', 'ONLINE')->where('status', 'PENDING'))
                        ->whereDoesntHave('payments', fn (Builder $payment): Builder => $payment->where('method', 'ONLINE')->where('status', 'PAID'));
                })->orWhere(function (Builder $query): void {
                    $query
                        ->whereIn('orders.status', ['PLACED', 'PROCESSING'])
                        ->whereHas('sellerOrders')
                        ->whereDoesntHave('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->where('status', '!=', 'PLACED'));
                });
            })
            ->when($returnRefundReady, fn (Builder $query): Builder => $query->whereDoesntHave('returnRefundRequests'));
    }

    private function toShip(Builder $query, bool $returnRefundReady): Builder
    {
        return $this->activeFulfillmentOrders($query, $returnRefundReady)
            ->whereHas('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereIn('status', self::SELLER_ACCEPTED_STATES))
            ->whereDoesntHave('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereIn('status', self::SHIPPED_SELLER_STATES))
            ->whereDoesntHave('sellerOrders.shipments', fn (Builder $shipment): Builder => $shipment->whereIn('current_status', self::RECEIVING_SHIPMENT_STATES));
    }

    private function toReceive(Builder $query, bool $returnRefundReady): Builder
    {
        return $this->activeFulfillmentOrders($query, $returnRefundReady)
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereIn('status', self::SHIPPED_SELLER_STATES))
                    ->orWhereHas('sellerOrders.shipments', fn (Builder $shipment): Builder => $shipment->whereIn('current_status', self::RECEIVING_SHIPMENT_STATES));
            });
    }

    private function awaitingOnlinePayment(Order $order, $payments): bool
    {
        return $order->status === 'PLACED'
            && $order->payment_status === 'PENDING'
            && $payments->contains(fn ($payment): bool => $payment->method === 'ONLINE' && $payment->status === 'PENDING')
            && ! $payments->contains(fn ($payment): bool => $payment->method === 'ONLINE' && $payment->status === 'PAID');
    }

    private function activeFulfillmentOrders(Builder $query, bool $returnRefundReady): Builder
    {
        return $query
            ->whereIn('orders.status', ['PLACED', 'PROCESSING'])
            ->whereDoesntHave('payments', fn (Builder $payment): Builder => $payment->where('method', 'ONLINE')->where('status', 'PENDING'))
            ->when($returnRefundReady, fn (Builder $query): Builder => $query->whereDoesntHave('returnRefundRequests'));
    }

    private function returns(Builder $query, bool $returnRefundReady): Builder
    {
        if (! $returnRefundReady) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereNotIn('orders.status', ['CANCELLED', 'PARTIALLY_CANCELLED'])
            ->whereHas('returnRefundRequests');
    }

    private function legacyProcessing(Builder $query): Builder
    {
        return $query->where('status', 'PROCESSING')->where(function (Builder $query): void {
            $query
                ->whereHas('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereDoesntHave('shipments'))
                ->orWhereHas('sellerOrders.shipments', fn (Builder $shipment): Builder => $shipment->whereNotIn('current_status', ['DELIVERED', 'COMPLETED']));
        });
    }

    private function legacyDelivered(Builder $query): Builder
    {
        return $query
            ->where('status', 'PROCESSING')
            ->whereHas('sellerOrders')
            ->whereDoesntHave('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereDoesntHave('shipments'))
            ->whereDoesntHave('sellerOrders.shipments', fn (Builder $shipment): Builder => $shipment->whereNotIn('current_status', ['DELIVERED', 'COMPLETED']));
    }
}
