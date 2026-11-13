<?php

namespace App\Support;

use App\Models\Logistics\Shipment;

final class ParcelVolumeEstimator
{
    private const MAX_VOLUME_LITERS = 999999999.999;

    public static function estimate(?Shipment $shipment): ?float
    {
        return self::recordedVolume($shipment) ?? self::packedDimensionsEstimate($shipment);
    }

    public static function recordedVolume(?Shipment $shipment): ?float
    {
        $volume = $shipment?->parcel_volume_liters;

        return is_numeric($volume) && is_finite((float) $volume) && (float) $volume > 0
            ? (float) $volume
            : null;
    }

    public static function packedDimensionsEstimate(?Shipment $shipment): ?float
    {
        $dimensions = [
            $shipment?->parcel_length_cm,
            $shipment?->parcel_width_cm,
            $shipment?->parcel_height_cm,
        ];
        foreach ($dimensions as $dimension) {
            if (! is_numeric($dimension) || ! is_finite((float) $dimension) || (float) $dimension <= 0) {
                return null;
            }
        }

        $volume = round(
            (float) $dimensions[0] * (float) $dimensions[1] * (float) $dimensions[2] / 1000,
            3,
            PHP_ROUND_HALF_UP,
        );

        return $volume > 0 && is_finite($volume) && $volume <= self::MAX_VOLUME_LITERS
            ? $volume
            : null;
    }
}
