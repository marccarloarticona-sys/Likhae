<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Schema;

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

    public static function rules(bool $required = true, bool $allowApproximate = true): array
    {
        $rules = [
            'latitude' => [$required ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$required ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
        ];

        if ($allowApproximate) {
            $rules['coordinate_precision'] = ['nullable', 'in:EXACT,APPROXIMATE'];
        }

        return $rules;
    }

    /**
     * Return a schema-safe precision field for an address or order snapshot.
     * Older deployments can keep saving coordinates before the additive
     * precision migration is installed.
     *
     * @return array<string, string|null>
     */
    public static function precisionAttributes(array $data, ?object $existing = null, string $table = 'addresses'): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'coordinate_precision')) {
            return [];
        }

        $latitude = $data['latitude'] ?? null;
        $longitude = $data['longitude'] ?? null;
        if (! filled($latitude) || ! filled($longitude)) {
            return ['coordinate_precision' => null];
        }

        $submittedPrecision = strtoupper(trim((string) ($data['coordinate_precision'] ?? '')));
        if (in_array($submittedPrecision, ['EXACT', 'APPROXIMATE'], true)) {
            return ['coordinate_precision' => $submittedPrecision];
        }

        $sameCoordinates = $existing
            && is_numeric($existing->latitude ?? null)
            && is_numeric($existing->longitude ?? null)
            && abs((float) $existing->latitude - (float) $latitude) <= 0.0000001
            && abs((float) $existing->longitude - (float) $longitude) <= 0.0000001;

        return ['coordinate_precision' => $sameCoordinates
            ? (($existing->coordinate_precision ?? null) === 'APPROXIMATE' ? 'APPROXIMATE' : 'EXACT')
            : 'EXACT'];
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
                'latitude' => 'Confirm a map pin before saving.',
                'longitude' => 'Confirm a map pin before saving.',
            ]);
        }

        if (! filled($latitude) || ! filled($longitude)) {
            throw ValidationException::withMessages([
                'latitude' => 'Provide both map coordinates, or leave both blank.',
                'longitude' => 'Provide both map coordinates, or leave both blank.',
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
                'latitude' => 'The address changed. Confirm a new map pin before saving.',
                'longitude' => 'The address changed. Confirm a new map pin before saving.',
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
