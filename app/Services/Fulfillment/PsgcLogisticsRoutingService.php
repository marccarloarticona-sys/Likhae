<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Buyer\Address;
use App\Models\Buyer\OrderAddress;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Seller\SellerProfile;
use Illuminate\Validation\ValidationException;

final class PsgcLogisticsRoutingService
{
    /**
     * Resolve both ends of one seller's shipment using only canonical PSGC
     * codes. Names are retained as snapshots but never participate in a query.
     *
     * @return array{origin: LogisticsCenter, destination: LogisticsCenter, destination_area: ServiceAreaLocation}
     */
    public function resolve(SellerProfile $seller, Address|OrderAddress $destination): array
    {
        $sellerAddress = $seller->businessAddress;
        $this->assertCompleteAddress($sellerAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');

        $origin = $this->uniqueCenterForMunicipality(
            (string) $sellerAddress->province_code,
            (string) $sellerAddress->municipality_code,
            'seller pickup',
            $sellerAddress,
        );
        $destinationCenter = $this->uniqueCenterForMunicipality(
            (string) $destination->province_code,
            (string) $destination->municipality_code,
            'buyer delivery',
            $destination,
        );

        $this->assertBarangayBelongsToMunicipality($sellerAddress->municipality_code, $sellerAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality($destination->municipality_code, $destination->barangay_code, 'buyer delivery');

        $originCoverage = $this->coverageForAddress($origin, $sellerAddress, 'seller pickup');
        $destinationCoverage = $this->coverageForAddress($destinationCenter, $destination, 'buyer delivery');

        return [
            'origin' => $origin,
            'destination' => $destinationCenter,
            'destination_area' => $destinationCoverage,
            'origin_coverage' => $originCoverage,
        ];
    }

    /** Resolve a parcel after an authorized administrator selects both hubs. */
    public function resolveWithCenters(SellerProfile $seller, Address|OrderAddress $destination, int $originCenterId, int $destinationCenterId): array
    {
        $sellerAddress = $seller->businessAddress;
        $this->assertCompleteAddress($sellerAddress, 'seller pickup');
        $this->assertCompleteAddress($destination, 'buyer delivery');
        $this->assertBarangayBelongsToMunicipality($sellerAddress->municipality_code, $sellerAddress->barangay_code, 'seller pickup');
        $this->assertBarangayBelongsToMunicipality($destination->municipality_code, $destination->barangay_code, 'buyer delivery');

        $origin = $this->selectedCenterForMunicipality($originCenterId, $sellerAddress, 'seller pickup');
        $destinationCenter = $this->selectedCenterForMunicipality($destinationCenterId, $destination, 'buyer delivery');

        return [
            'origin' => $origin,
            'destination' => $destinationCenter,
            'origin_coverage' => $this->coverageForAddress($origin, $sellerAddress, 'seller pickup'),
            'destination_area' => $this->coverageForAddress($destinationCenter, $destination, 'buyer delivery'),
        ];
    }

    private function uniqueCenterForMunicipality(string $provinceCode, string $municipalityCode, string $purpose, Address|OrderAddress $coverageAddress): LogisticsCenter
    {
        $centers = LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('address', fn ($query) => $query
                ->where('province_code', trim($provinceCode))
                ->where('municipality_code', trim($municipalityCode)))
            ->whereHas('serviceAreas', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('locations', fn ($locationQuery) => $locationQuery
                    ->where('province_code', trim((string) $coverageAddress->province_code))
                    ->where('municipality_code', trim((string) $coverageAddress->municipality_code))
                    ->where('barangay_code', trim((string) $coverageAddress->barangay_code))))
            ->orderBy('id')
            ->get();

        if ($centers->isEmpty()) {
            throw ValidationException::withMessages([
                'address_id' => "No active logistics hub is authorized for the {$purpose} municipality PSGC.",
            ]);
        }

        if ($centers->count() !== 1) {
            throw ValidationException::withMessages([
                'address_id' => "Routing is ambiguous: multiple active logistics hubs match the {$purpose} municipality PSGC.",
            ]);
        }

        return $centers->first();
    }

    private function selectedCenterForMunicipality(int $centerId, Address|OrderAddress $address, string $purpose): LogisticsCenter
    {
        $center = LogisticsCenter::query()->approvedActive()->whereKey($centerId)->whereHas('address', fn ($query) => $query
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code)))
            ->first();

        if (! $center) {
            throw ValidationException::withMessages([
                'address_id' => "The selected {$purpose} hub is not active, approved, or authorized for this municipality PSGC.",
            ]);
        }

        return $center;
    }

    private function coverageForAddress(LogisticsCenter $center, Address|OrderAddress $address, string $purpose): ServiceAreaLocation
    {
        $locations = ServiceAreaLocation::query()
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code))
            ->where('barangay_code', trim((string) $address->barangay_code))
            ->whereHas('serviceArea', fn ($query) => $query
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true))
            ->with('serviceArea')
            ->get();

        if ($locations->isEmpty()) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} barangay PSGC is not covered by its authorized logistics hub.",
            ]);
        }

        if ($locations->count() !== 1) {
            throw ValidationException::withMessages([
                'address_id' => "Routing is ambiguous for the {$purpose} barangay PSGC.",
            ]);
        }

        return $locations->first();
    }

    private function assertCompleteAddress(Address|OrderAddress|null $address, string $purpose): void
    {
        if (! $address
            || blank($address->province_code)
            || blank($address->municipality_code)
            || blank($address->barangay_code)) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} address is missing a canonical province, municipality, or barangay PSGC.",
            ]);
        }
    }

    private function assertBarangayBelongsToMunicipality(string $municipalityCode, string $barangayCode, string $purpose): void
    {
        $barangays = PhilippineAddressController::barangayRecordsForMunicipality(trim($municipalityCode));
        if ($barangays === [] || ! collect($barangays)->contains(fn (array $record): bool => (string) ($record['code'] ?? '') === trim($barangayCode))) {
            throw ValidationException::withMessages([
                'address_id' => "The {$purpose} barangay PSGC is missing or does not belong to its municipality PSGC.",
            ]);
        }
    }
}
