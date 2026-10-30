<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class AddressCoordinateValidator
{
    private const ADDRESS_FIELDS = [
        'province_code',
        'province_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'house_number',
        'street_address',
        'landmark',
    ];

    public static function rules(bool $required = true): array
    {
        return [
            'latitude' => [$required ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$required ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public static function assertValid(array $data, bool $required = true): void
    {
        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;

        if (! filled($latitude) && ! filled($longitude)) {
            if (! $required) {
                return;
            }

            throw ValidationException::withMessages([
                'latitude' => 'Confirm the exact address pin before saving.',
                'longitude' => 'Confirm the exact address pin before saving.',
            ]);
        }

        if (! filled($latitude) || ! filled($longitude)) {
            throw ValidationException::withMessages([
                'latitude' => 'Provide both coordinates for an exact pin, or leave both blank.',
                'longitude' => 'Provide both coordinates for an exact pin, or leave both blank.',
            ]);
        }

        $latitude = filter_var($latitude, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
        $longitude = filter_var($longitude, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

        if ($latitude === null || $longitude === null
            || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages([
                'latitude' => 'The confirmed latitude is invalid.',
                'longitude' => 'The confirmed longitude is invalid.',
            ]);
        }
    }

    /**
     * Prevent an edit from keeping coordinates that were confirmed for a
     * different address.
     */
    public static function assertFreshForAddress(?object $existing, array $data): void
    {
        if (! $existing || ! self::addressChanged($existing, $data)) {
            return;
        }

        $oldCoordinates = self::coordinatePair($existing->latitude ?? null, $existing->longitude ?? null);
        $newCoordinates = self::coordinatePair($data['latitude'] ?? null, $data['longitude'] ?? null);

        if ($oldCoordinates && $newCoordinates
            && $oldCoordinates['latitude'] === $newCoordinates['latitude']
            && $oldCoordinates['longitude'] === $newCoordinates['longitude']) {
            throw ValidationException::withMessages([
                'latitude' => 'The address changed. Confirm a new exact pin before saving.',
                'longitude' => 'The address changed. Confirm a new exact pin before saving.',
            ]);
        }
    }

    private static function addressChanged(object $existing, array $data): bool
    {
        foreach (self::ADDRESS_FIELDS as $field) {
            if (trim((string) ($existing->{$field} ?? '')) !== trim((string) ($data[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    private static function coordinatePair(mixed $latitude, mixed $longitude): ?array
    {
        if (! filled($latitude) || ! filled($longitude)) {
            return null;
        }

        $latitude = filter_var($latitude, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
        $longitude = filter_var($longitude, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

        if ($latitude === null || $longitude === null
            || ! is_finite((float) $latitude) || ! is_finite((float) $longitude)) {
            return null;
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }
}
