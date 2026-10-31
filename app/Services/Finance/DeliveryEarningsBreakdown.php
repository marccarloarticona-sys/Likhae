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
    /** @return array{is_inter_hub:bool,total_cents:int,rider_cents:int,pickup_rider_cents:int,delivery_rider_cents:int,origin_logistics_cents:int,destination_logistics_cents:int} */
    public static function forShipment(Shipment $shipment, ShipmentFinancialAllocation $allocation): array
    {
        $shippingCents = FinancialRules::cents($allocation->shipping_fee);
        $additionalCareCents = max(0, FinancialRules::cents($allocation->protection_amount ?? 0));
        $totalCents = $shippingCents + $additionalCareCents;
        $originId = (int) ($shipment->origin_logistics_center_id ?? 0);
        $destinationId = (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id ?? 0);
        $isInterHub = $originId > 0 && $destinationId > 0 && $originId !== $destinationId;

        if ($isInterHub) {
            // Route percentages apply to shipping only. Additional Care keeps
            // the existing 60/40 Rider/Logistics split; split its Logistics
            // portion across the route using the configured 30:10 ratio.
            [$riderShippingCents, $originShippingCents, $destinationShippingCents] = FinancialRules::interHubDeliverySplitCents($shippingCents);
            $additionalCareAllocation = FinancialRules::shippingAllocationCents(
                $additionalCareCents,
                FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE,
            );
            [$originAdditionalCareCents, $destinationAdditionalCareCents] = FinancialRules::allocateCents(
                $additionalCareAllocation['logistics_cents'],
                [30, 10],
            );
            [$pickupShippingRiderCents, $deliveryShippingRiderCents] = FinancialRules::allocateCents($riderShippingCents, [1, 2]);
            [$pickupCareRiderCents, $deliveryCareRiderCents] = FinancialRules::allocateCents($additionalCareAllocation['rider_cents'], [1, 2]);
            $pickupRiderCents = $pickupShippingRiderCents + $pickupCareRiderCents;
            $deliveryRiderCents = $deliveryShippingRiderCents + $deliveryCareRiderCents;
            $riderCents = $pickupRiderCents + $deliveryRiderCents;
            $originLogisticsCents = $originShippingCents + $originAdditionalCareCents;
            $destinationLogisticsCents = $destinationShippingCents + $destinationAdditionalCareCents;

            return [
                'is_inter_hub' => true,
                'total_cents' => $totalCents,
                'rider_cents' => $riderCents,
                'pickup_rider_cents' => $pickupRiderCents,
                'delivery_rider_cents' => $deliveryRiderCents,
                'origin_logistics_cents' => $originLogisticsCents,
                'destination_logistics_cents' => $destinationLogisticsCents,
            ];
        }

        $riderCents = FinancialRules::cents($allocation->rider_allocation);
        [$pickupRiderCents, $deliveryRiderCents] = FinancialRules::allocateCents($riderCents, [1, 2]);

        return [
            'is_inter_hub' => false,
            'total_cents' => $totalCents,
            'rider_cents' => $riderCents,
            'pickup_rider_cents' => $pickupRiderCents,
            'delivery_rider_cents' => $deliveryRiderCents,
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
                    'transfer_cents' => $split['origin_logistics_cents'] + $split['pickup_rider_cents'],
                    'rider_reserve_cents' => $split['pickup_rider_cents'],
                ];
            }
            if ($centerId === $destinationId) {
                return [
                    'role' => 'DESTINATION',
                    'net_share_cents' => $split['destination_logistics_cents'],
                    'transfer_cents' => $split['destination_logistics_cents'] + $split['delivery_rider_cents'],
                    'rider_reserve_cents' => $split['delivery_rider_cents'],
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

    /**
     * Reconstructs the original combined-fee split only for historical pools
     * that do not have a saved LOGISTICS_ALLOCATION snapshot.
     *
     * @return array{role:string,net_share_cents:int,transfer_cents:int,rider_reserve_cents:int}
     */
    public static function forLegacyLogisticsCenter(
        Shipment $shipment,
        ShipmentFinancialAllocation $allocation,
        int $centerId,
    ): array {
        $totalCents = FinancialRules::deliveryEarningsCents(
            $allocation->shipping_fee,
            $allocation->protection_amount ?? 0,
        );
        $destinationId = (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id ?? 0);
        $originId = (int) ($shipment->origin_logistics_center_id ?? 0);
        $isInterHub = $originId > 0 && $destinationId > 0 && $originId !== $destinationId;

        if ($isInterHub) {
            $riderShare = FinancialRules::shippingAllocationCents($totalCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE)['rider_cents'];
            [$pickupRiderShare, $deliveryRiderShare] = FinancialRules::allocateCents($riderShare, [1, 2]);
            $logisticsShares = FinancialRules::allocateCents($totalCents - $riderShare, [30, 10]);
            if ($centerId === $originId) {
                return [
                    'role' => 'ORIGIN',
                    'net_share_cents' => $logisticsShares[0],
                    'transfer_cents' => $logisticsShares[0] + $pickupRiderShare,
                    'rider_reserve_cents' => $pickupRiderShare,
                ];
            }
            if ($centerId === $destinationId) {
                return [
                    'role' => 'DESTINATION',
                    'net_share_cents' => $logisticsShares[1],
                    'transfer_cents' => $logisticsShares[1] + $deliveryRiderShare,
                    'rider_reserve_cents' => $deliveryRiderShare,
                ];
            }

            throw new RuntimeException('This Logistics pool does not belong to either center on the shipment route.');
        }

        if ($centerId !== $destinationId && $centerId !== (int) $shipment->logistics_center_id) {
            throw new RuntimeException('This Logistics pool does not belong to the shipment center.');
        }
        $netShareCents = FinancialRules::cents($allocation->logistics_allocation);
        $riderReserveCents = max(0, $totalCents - $netShareCents);

        return [
            'role' => 'SINGLE_HUB',
            'net_share_cents' => $netShareCents,
            'transfer_cents' => $totalCents,
            'rider_reserve_cents' => $riderReserveCents,
        ];
    }
}
