<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
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

    private const TO_SHIP_SELLER_STATES = ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'];

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

    public function apply(
        Builder $query,
        string $tab,
        bool $returnRefundReady,
        bool $shipmentReturnsReady = false,
        bool $returnDispositionReady = false,
    ): Builder
    {
        return match ($tab) {
            'TO_PAY' => $this->toPay($query, $returnRefundReady),
            'TO_SHIP' => $this->toShip($query, $returnRefundReady),
            'TO_RECEIVE' => $this->toReceive($query, $returnRefundReady, $shipmentReturnsReady, $returnDispositionReady),
            'COMPLETED' => $this->completed($query, $returnRefundReady),
            'CANCELLED' => $query->whereIn('status', ['CANCELLED', 'PARTIALLY_CANCELLED']),
            'RETURNS' => $this->returns($query, $returnRefundReady, $shipmentReturnsReady, $returnDispositionReady),
            // Keep existing saved URLs usable while the buyer tabs use the new mapping.
            'PLACED' => $query->where('status', 'PLACED'),
            'PROCESSING' => $this->legacyProcessing($query),
            'DELIVERED' => $this->legacyDelivered($query),
            default => $query,
        };
    }

    /** @return array<string, int> */
    public function counts(
        Builder $buyerOrders,
        bool $returnRefundReady,
        bool $shipmentReturnsReady = false,
        bool $returnDispositionReady = false,
    ): array
    {
        $counts = [];
        foreach (array_keys(self::LABELS) as $tab) {
            $counts[$tab] = $tab === 'ALL'
                ? (clone $buyerOrders)->count()
                : $this->apply(clone $buyerOrders, $tab, $returnRefundReady, $shipmentReturnsReady, $returnDispositionReady)->count();
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
            && $sellerOrders->contains(fn (SellerOrder $sellerOrder): bool => in_array($sellerOrder->status, self::TO_SHIP_SELLER_STATES, true))) {
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

        if ($sellerOrder->status === 'PLACED') {
            return $this->awaitingOnlinePayment($order, $order->payments) ? 'TO_PAY' : 'TO_SHIP';
        }

        if (in_array($sellerOrder->status, self::SELLER_ACCEPTED_STATES, true)) {
            return 'TO_SHIP';
        }

        return $sellerOrder->status;
    }

    public function sellerOrderDisplayStatus(Order $order, SellerOrder $sellerOrder): string
    {
        $statusKey = $this->forSellerOrder($order, $sellerOrder);
        if (in_array($statusKey, ['CANCELLED', 'COMPLETED'], true)) {
            return self::LABELS[$statusKey];
        }

        $parcelStatusCounts = $sellerOrder->shipments
            ->map(fn ($shipment): string => match ((string) $shipment->current_status) {
                'DELIVERED', 'COMPLETED' => 'delivered',
                'DELIVERY_FAILED' => 'failed delivery attempt',
                'PICKED_UP' => 'picked up',
                'AT_SORTING_CENTER' => 'at sorting center',
                'SORTED' => 'sorted',
                'ASSIGNED_TO_RIDER' => 'assigned to delivery rider',
                'OUT_FOR_DELIVERY' => 'out for delivery',
                'PICKUP_ASSIGNED' => 'pickup rider assigned',
                'READY_FOR_PICKUP' => 'ready for pickup',
                'PLACED' => 'waiting for seller',
                'CONFIRMED' => 'confirmed',
                'PREPARING' => 'preparing',
                'RETURNED' => 'returned',
                'CANCELLED' => 'cancelled',
                default => str($shipment->current_status)->replace('_', ' ')->lower()->toString(),
            })
            ->filter()
            ->countBy();

        if ($parcelStatusCounts->count() > 1) {
            $summary = $parcelStatusCounts->map(fn (int $count, string $label): string => $count.' '.match ($label) {
                'failed delivery attempt' => $count === 1 ? 'failed delivery attempt' : 'failed delivery attempts',
                default => $label,
            })->implode(' · ');

            return 'Mixed: '.$summary;
        }

        return self::LABELS[$statusKey] ?? str($statusKey)->replace('_', ' ')->title()->toString();
    }

    public function forShipment(Order $order, SellerOrder $sellerOrder, Shipment $shipment, bool $returnRefundReady): string
    {
        if ($order->status === 'CANCELLED'
            || ($order->status !== 'PARTIALLY_CANCELLED' && $sellerOrder->status === 'CANCELLED')
            || $shipment->current_status === 'CANCELLED') {
            return 'CANCELLED';
        }

        if ($this->shipmentHasReturnRequest($order, $shipment, $returnRefundReady)
            || $shipment->current_status === 'RETURNED'
            || $this->shipmentHasSellerReturnWorkflow($shipment)) {
            return 'RETURNS';
        }

        if ($shipment->current_status === 'COMPLETED') {
            return 'COMPLETED';
        }

        if (in_array($shipment->current_status, self::RECEIVING_SHIPMENT_STATES, true)) {
            return 'TO_RECEIVE';
        }

        if (in_array($shipment->current_status, self::SELLER_ACCEPTED_STATES, true)) {
            return 'TO_SHIP';
        }

        if ($shipment->current_status === 'PLACED') {
            return $this->awaitingOnlinePayment($order, $order->payments) ? 'TO_PAY' : 'TO_SHIP';
        }

        return $this->forSellerOrder($order, $sellerOrder);
    }

    private function toPay(Builder $query, bool $returnRefundReady): Builder
    {
        return $query
            ->where('orders.status', 'PLACED')
            ->where('orders.payment_status', 'PENDING')
            ->whereHas('payments', fn (Builder $payment): Builder => $payment->where('method', 'ONLINE')->where('status', 'PENDING'))
            ->whereDoesntHave('payments', fn (Builder $payment): Builder => $payment->where('method', 'ONLINE')->where('status', 'PAID'))
            ->when($returnRefundReady, fn (Builder $query): Builder => $query->whereDoesntHave('returnRefundRequests'));
    }

    private function toShip(Builder $query, bool $returnRefundReady): Builder
    {
        return $this->activeFulfillmentOrders($query, $returnRefundReady)
            ->whereHas('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereIn('status', self::TO_SHIP_SELLER_STATES))
            ->whereDoesntHave('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder->whereIn('status', self::SHIPPED_SELLER_STATES))
            ->whereDoesntHave('sellerOrders.shipments', fn (Builder $shipment): Builder => $shipment->whereIn('current_status', self::RECEIVING_SHIPMENT_STATES));
    }

    private function toReceive(Builder $query, bool $returnRefundReady, bool $shipmentReturnsReady, bool $returnDispositionReady): Builder
    {
        return $this->activeFulfillmentOrders($query, false)
            ->when($returnRefundReady, fn (Builder $query): Builder => $query->whereDoesntHave('returnRefundRequests', fn (Builder $request): Builder => $request
                ->whereDoesntHave('items')
                ->whereDoesntHave('sellerRequests.items')))
            ->where(function (Builder $query) use ($returnRefundReady, $shipmentReturnsReady, $returnDispositionReady): void {
                $query->whereHas('sellerOrders.shipments', function (Builder $shipment) use ($returnRefundReady, $shipmentReturnsReady, $returnDispositionReady): void {
                    $shipment->whereIn('current_status', self::RECEIVING_SHIPMENT_STATES);
                    if ($shipmentReturnsReady) {
                        $shipment->whereDoesntHave('returnWorkflow', function (Builder $workflow) use ($returnDispositionReady): void {
                            $workflow->whereIn('status', [...ShipmentReturnWorkflow::OPEN_STATUSES, ShipmentReturnWorkflow::SELLER_RECEIVED]);
                            if ($returnDispositionReady) {
                                $workflow->where(fn (Builder $workflow): Builder => $workflow
                                    ->whereNull('return_disposition')
                                    ->orWhere('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN));
                            }
                        });
                    }
                    if ($returnRefundReady) {
                        $shipment->whereDoesntHave('orderItem.returnRefundRequestItems');
                    }
                })->orWhereHas('sellerOrders', fn (Builder $sellerOrder): Builder => $sellerOrder
                    ->whereIn('status', self::SHIPPED_SELLER_STATES)
                    ->whereDoesntHave('shipments'));
            });
    }

    private function completed(Builder $query, bool $returnRefundReady): Builder
    {
        return $query->where(function (Builder $query) use ($returnRefundReady): void {
            if ($returnRefundReady) {
                $query->whereDoesntHave('returnRefundRequests', fn (Builder $request): Builder => $request
                    ->whereDoesntHave('items')
                    ->whereDoesntHave('sellerRequests.items'));
            }

            $query->whereHas('sellerOrders.shipments', function (Builder $shipment) use ($returnRefundReady): void {
                $shipment->where('current_status', 'COMPLETED');
                if ($returnRefundReady) {
                    $shipment->whereDoesntHave('orderItem.returnRefundRequestItems');
                }
            })->orWhere(function (Builder $query) use ($returnRefundReady): void {
                $query->where('status', 'COMPLETED')->whereDoesntHave('sellerOrders.shipments');
                if ($returnRefundReady) {
                    $query->whereDoesntHave('returnRefundRequests');
                }
            });
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

    private function returns(
        Builder $query,
        bool $returnRefundReady,
        bool $shipmentReturnsReady,
        bool $returnDispositionReady,
    ): Builder
    {
        return $query
            ->whereNotIn('orders.status', ['CANCELLED', 'PARTIALLY_CANCELLED'])
            ->where(function (Builder $query) use ($returnRefundReady, $shipmentReturnsReady, $returnDispositionReady): void {
                if ($returnRefundReady) {
                    $query->whereHas('returnRefundRequests');
                }
                if ($shipmentReturnsReady) {
                    $query->orWhereHas('sellerOrders.shipments', function (Builder $shipment) use ($returnDispositionReady): void {
                        $shipment->where('current_status', 'RETURNED')
                            ->orWhereHas('returnWorkflow', function (Builder $workflow) use ($returnDispositionReady): void {
                                $workflow->whereIn('status', [...ShipmentReturnWorkflow::OPEN_STATUSES, ShipmentReturnWorkflow::SELLER_RECEIVED]);
                                if ($returnDispositionReady) {
                                    $workflow->where(fn (Builder $workflow): Builder => $workflow
                                        ->whereNull('return_disposition')
                                        ->orWhere('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN));
                                }
                            });
                    });
                }
                if (! $returnRefundReady && ! $shipmentReturnsReady) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    private function shipmentHasReturnRequest(Order $order, Shipment $shipment, bool $returnRefundReady): bool
    {
        if (! $returnRefundReady || ! $order->relationLoaded('returnRefundRequests') || $order->returnRefundRequests->isEmpty()) {
            return false;
        }

        $itemIds = $order->returnRefundRequests->flatMap(function ($request): array {
            $requestItems = $request->relationLoaded('items') ? $request->items : collect();
            $sellerRequestItems = $request->relationLoaded('sellerRequests')
                ? $request->sellerRequests->flatMap(fn ($sellerRequest) => $sellerRequest->items)
                : collect();

            return $requestItems->pluck('order_item_id')->merge($sellerRequestItems->pluck('order_item_id'))->filter()->map(fn ($id): int => (int) $id)->all();
        })->unique()->values();

        return $itemIds->isEmpty() || $itemIds->contains((int) $shipment->order_item_id);
    }

    private function shipmentHasSellerReturnWorkflow(Shipment $shipment): bool
    {
        if (! $shipment->relationLoaded('returnWorkflow') || ! ($workflow = $shipment->getRelation('returnWorkflow'))) {
            return false;
        }

        $isOpen = in_array($workflow->status, [...ShipmentReturnWorkflow::OPEN_STATUSES, ShipmentReturnWorkflow::SELLER_RECEIVED], true);
        $isSellerReturn = ($workflow->return_disposition ?: ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN)
            === ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN;

        return $isOpen && $isSellerReturn;
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
