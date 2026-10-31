<?php

namespace App\Support;

use App\Models\Logistics\Shipment;

final class ParcelWeightEstimator
{
    private const MAX_WEIGHT_KG = 9999999.999;

    public static function recordedWeight(?Shipment $shipment): ?float
    {
        $weight = $shipment?->parcel_weight_kg;

        return is_numeric($weight) && is_finite((float) $weight) && (float) $weight > 0
            ? (float) $weight
            : null;
    }

    public static function productEstimate(?Shipment $shipment): ?float
    {
        $unitWeight = $shipment?->orderItem?->productVariant?->weight_kg;
        $quantity = (int) ($shipment?->orderItem?->quantity ?? 0);

        if (! is_numeric($unitWeight) || ! is_finite((float) $unitWeight) || (float) $unitWeight <= 0 || $quantity <= 0) {
            return null;
        }

        $estimate = round((float) $unitWeight * $quantity, 3, PHP_ROUND_HALF_UP);

        return $estimate > 0 && $estimate <= self::MAX_WEIGHT_KG ? $estimate : null;
    }

    public static function requiresManualWeight(?Shipment $shipment): bool
    {
        return self::recordedWeight($shipment) === null && self::productEstimate($shipment) === null;
    }
}
