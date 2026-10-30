<?php

namespace App\Services\Finance;

use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Logistics\Shipment;
use App\Support\FinancialRules;
use RuntimeException;

/**
 * Computes the transfer amount, earned amount, and Rider reserve for one
 * Logistics center without changing the immutable shipment allocation.
 */
final class DeliveryEarningsBreakdown
{
    /** @return array{is_inter_hub:bool,total_cents:int,rider_cents:int,origin_logistics_cents:int,destination_logistics_cents:int} */
    public static function forShipment(Shipment $shipment, ShipmentFinancialAllocation $allocation): array
    {
        $totalCents = FinancialRules::deliveryEarningsCents(
            $allocation->shipping_fee,
            $allocation->protection_amount ?? 0,
        );
        $originId = (int) ($shipment->origin_logistics_center_id ?? 0);
        $destinationId = (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id ?? 0);
        $isInterHub = $originId > 0 && $destinationId > 0 && $originId !== $destinationId;

        if ($isInterHub) {
            [$riderCents, $originLogisticsCents, $destinationLogisticsCents] = FinancialRules::interHubDeliverySplitCents($totalCents);

            return [
                'is_inter_hub' => true,
                'total_cents' => $totalCents,
                'rider_cents' => $riderCents,
                'origin_logistics_cents' => $originLogisticsCents,
                'destination_logistics_cents' => $destinationLogisticsCents,
            ];
        }

        $riderCents = FinancialRules::cents($allocation->rider_allocation);

        return [
            'is_inter_hub' => false,
            'total_cents' => $totalCents,
            'rider_cents' => $riderCents,
            'origin_logistics_cents' => 0,
            'destination_logistics_cents' => FinancialRules::cents($allocation->logistics_allocation),
        ];
    }

    /** @return array{role:string,net_share_cents:int,transfer_cents:int,rider_reserve_cents:int} */
    public static function forLogisticsCenter(
        Shipment $shipment,
        ShipmentFinancialAllocation $allocation,
        int $centerId,
    ): array {
        $split = self::forShipment($shipment, $allocation);
        $destinationId = (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id ?? 0);
        $originId = (int) ($shipment->origin_logistics_center_id ?? 0);

        if ($split['is_inter_hub']) {
            if ($centerId === $originId) {
                return [
                    'role' => 'ORIGIN',
                    'net_share_cents' => $split['origin_logistics_cents'],
                    'transfer_cents' => $split['origin_logistics_cents'],
                    'rider_reserve_cents' => 0,
                ];
            }
            if ($centerId === $destinationId) {
                return [
                    'role' => 'DESTINATION',
                    'net_share_cents' => $split['destination_logistics_cents'],
                    'transfer_cents' => $split['destination_logistics_cents'] + $split['rider_cents'],
                    'rider_reserve_cents' => $split['rider_cents'],
                ];
            }

            throw new RuntimeException('This Logistics pool does not belong to either center on the shipment route.');
        }

        if ($centerId !== $destinationId && $centerId !== (int) $shipment->logistics_center_id) {
            throw new RuntimeException('This Logistics pool does not belong to the shipment center.');
        }

        $netShareCents = FinancialRules::cents($allocation->logistics_allocation);

        return [
            'role' => 'SINGLE_HUB',
            'net_share_cents' => $netShareCents,
            'transfer_cents' => $split['total_cents'],
            'rider_reserve_cents' => max(0, $split['total_cents'] - $netShareCents),
        ];
    }
}
