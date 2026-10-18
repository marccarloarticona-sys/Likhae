<?php

namespace App\Services\Seller;

use App\Models\Seller\SellerOrder;
use Illuminate\Database\Eloquent\Builder;

final class SellerDashboardOrderStatus
{
    private const IN_TRANSIT_SHIPMENT_STATUSES = [
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERY_FAILED',
    ];

    private const DELIVERED_SHIPMENT_STATUSES = ['DELIVERED', 'COMPLETED'];

    /** @return array<int|string, string|\Closure(Builder): Builder> */
    public function shipmentCountRelations(): array
    {
        return [
            'shipments',
            'shipments as dashboard_ready_shipments_count' => fn (Builder $query) => $query->whereIn('current_status', ['READY_FOR_PICKUP', 'PICKUP_ASSIGNED']),
            'shipments as dashboard_picked_up_shipments_count' => fn (Builder $query) => $query->where('current_status', 'PICKED_UP'),
            'shipments as dashboard_in_transit_shipments_count' => fn (Builder $query) => $query->whereIn('current_status', self::IN_TRANSIT_SHIPMENT_STATUSES),
            'shipments as dashboard_delivered_shipments_count' => fn (Builder $query) => $query->whereIn('current_status', self::DELIVERED_SHIPMENT_STATUSES),
        ];
    }

    public function key(SellerOrder $order): string
    {
        $status = strtoupper((string) $order->status);

        if ($status === 'COMPLETED') {
            return 'completed';
        }

        if (in_array($status, ['CANCELLED', 'PARTIALLY_CANCELLED'], true)) {
            return 'cancelled';
        }

        $shipmentCount = (int) $order->getAttribute('shipments_count');
        if ($shipmentCount > 0) {
            $deliveredCount = (int) $order->getAttribute('dashboard_delivered_shipments_count');

            if ($deliveredCount === $shipmentCount) {
                return 'delivered';
            }

            if ((int) $order->getAttribute('dashboard_in_transit_shipments_count') > 0) {
                return 'in-transit';
            }

            if ((int) $order->getAttribute('dashboard_picked_up_shipments_count') > 0) {
                return 'picked-up';
            }

            if ((int) $order->getAttribute('dashboard_ready_shipments_count') > 0) {
                return 'ready-for-pickup';
            }
        }

        return match ($status) {
            'PLACED' => 'placed',
            'CONFIRMED' => 'confirmed',
            'PREPARING' => 'preparing',
            'READY_FOR_PICKUP' => 'ready-for-pickup',
            'PICKED_UP' => 'picked-up',
            'SHIPPED' => 'in-transit',
            'DELIVERED' => 'delivered',
            default => 'other',
        };
    }

    public function label(SellerOrder $order): string
    {
        return match ($this->key($order)) {
            'placed' => 'Placed',
            'confirmed' => 'Accepted',
            'preparing' => 'Preparing',
            'ready-for-pickup' => 'Ready for Pickup',
            'picked-up' => 'Picked Up',
            'in-transit' => 'In Transit',
            'delivered' => 'Delivered, awaiting completion',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => str((string) $order->status)->replace('_', ' ')->title()->toString(),
        };
    }
}
