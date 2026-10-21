<?php

namespace App\Support;

use App\Http\Controllers\Auth\PhilippineAddressController;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class PhilippineAddressValidator
{
    /**
     * Rules for address forms that submit PSGC labels and codes together.
     * Region labels are validated against PSGC and persisted when the additive
     * region columns are installed. The province, municipality, and barangay
     * identifiers continue to use the existing address columns.
     */
    public static function rules(bool $requireStreet = true): array
    {
        return [
            'region_code' => ['required', 'string', 'max:50'],
            'region_name' => ['required', 'string', 'max:150'],
            'province_code' => ['required', 'string', 'max:50'],
            'province_name' => ['required', 'string', 'max:150'],
            'municipality_code' => ['required', 'string', 'max:50'],
            'municipality_name' => ['required', 'string', 'max:150'],
            'barangay_code' => ['required', 'string', 'max:50'],
            'barangay_name' => ['required', 'string', 'max:150'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'house_number' => ['nullable', 'string', 'max:100'],
            'street_address' => $requireStreet
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function assertValid(array $data): void
    {
        $isValid = PhilippineAddressController::selectionIsValid(
            (string) $data['region_code'],
            (string) $data['region_name'],
            (string) $data['province_code'],
            (string) $data['province_name'],
            (string) $data['municipality_code'],
            (string) $data['municipality_name'],
            (string) $data['barangay_code'],
            (string) $data['barangay_name'],
        );

        if (! $isValid) {
            throw ValidationException::withMessages([
                'barangay_code' => 'Select a matching region, province, municipality, and barangay from the Philippine address list.',
            ]);
        }

        $expectedPostalCode = PhilippineAddressController::expectedPostalCodeFor(
            (string) $data['province_code'],
            (string) $data['municipality_code'],
            (string) $data['province_name'],
            (string) $data['municipality_name'],
        );

        if ($expectedPostalCode !== null
            && filled($data['postal_code'] ?? null)
            && (string) $data['postal_code'] !== $expectedPostalCode) {
            throw ValidationException::withMessages([
                'postal_code' => 'The postal code does not match the selected Philippine address.',
            ]);
        }
    }

    public static function storageAttributes(array $data): array
    {
        $attributes = [
            'province_code' => $data['province_code'],
            'province_name' => $data['province_name'],
            'municipality_code' => $data['municipality_code'],
            'municipality_name' => $data['municipality_name'],
            'barangay_code' => $data['barangay_code'],
            'barangay_name' => $data['barangay_name'],
            'postal_code' => $data['postal_code'] ?? null,
            'house_number' => $data['house_number'] ?? null,
            'street_address' => $data['street_address'] ?? null,
            'landmark' => $data['landmark'] ?? null,
        ];

        if (Schema::hasColumn('addresses', 'region_code')) {
            $attributes['region_code'] = $data['region_code'];
        }
        if (Schema::hasColumn('addresses', 'region_name')) {
            $attributes['region_name'] = $data['region_name'];
        }

        return $attributes;
    }
}
