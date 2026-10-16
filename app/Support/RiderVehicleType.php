<?php

namespace App\Support;

final class RiderVehicleType
{
    public const MOTORCYCLE = 'motorcycle';
    public const TRICYCLE = 'tricycle';
    public const VAN = 'van';
    public const CLOSED_VAN = 'closed_van';
    public const TRUCK = 'truck';
    public const LEGACY_CAR = 'car';

    public const ROLE_LOCAL_BARANGAY = 'LOCAL_BARANGAY';
    public const ROLE_INTER_LOGISTICS = 'INTER_LOGISTICS';
    public const ROLE_LEGACY = 'LEGACY';
    public const ROLE_UNCLASSIFIED = 'UNCLASSIFIED';

    /** @return array<string, string> */
    public static function registrationOptions(): array
    {
        return [
            self::MOTORCYCLE => 'Motorcycle',
            self::TRICYCLE => 'Tricycle',
            self::VAN => 'Van',
            self::CLOSED_VAN => 'Closed Van',
            self::TRUCK => 'Truck',
            self::LEGACY_CAR => 'Car (legacy)',
        ];
    }

    /** @return list<string> */
    public static function acceptedValues(): array
    {
        return array_keys(self::registrationOptions());
    }

    /** @return list<string> */
    public static function localValues(): array
    {
        return [self::MOTORCYCLE, self::TRICYCLE];
    }

    /** @return list<string> */
    public static function interLogisticsValues(): array
    {
        return [self::VAN, self::CLOSED_VAN, self::TRUCK];
    }

    public static function role(?string $vehicleType): string
    {
        return match (self::normalize($vehicleType)) {
            self::MOTORCYCLE, self::TRICYCLE => self::ROLE_LOCAL_BARANGAY,
            self::VAN, self::CLOSED_VAN, self::TRUCK => self::ROLE_INTER_LOGISTICS,
            self::LEGACY_CAR => self::ROLE_LEGACY,
            default => self::ROLE_UNCLASSIFIED,
        };
    }

    public static function isLocalBarangay(?string $vehicleType): bool
    {
        return self::role($vehicleType) === self::ROLE_LOCAL_BARANGAY;
    }

    public static function isInterLogistics(?string $vehicleType): bool
    {
        return self::role($vehicleType) === self::ROLE_INTER_LOGISTICS;
    }

    public static function interLogisticsParcelLimit(?string $vehicleType): int
    {
        return match (self::normalize($vehicleType)) {
            self::VAN => 50,
            self::CLOSED_VAN => 75,
            self::TRUCK => 100,
            default => 0,
        };
    }

    private static function normalize(?string $vehicleType): string
    {
        return str((string) $vehicleType)
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();
    }
}
