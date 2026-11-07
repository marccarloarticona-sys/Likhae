<?php

namespace App\Services\Fulfillment;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\LogisticsCoverageRoute;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class LocalRiderEligibilityService
{
    public function activeRidersForCenter(LogisticsCenter $center): Builder
    {
        return RiderProfile::query()
            ->where('logistics_center_id', $center->id)
            ->whereIn('status', ['ACTIVE', 'WARNED'])
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereHas('user', fn (Builder $query) => $query
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE));
    }

    public function assertCenterCoversMunicipality(
        LogisticsCenter $center,
        string $provinceCode,
        string $municipalityCode,
        bool $allowExplicitRemoteCoverage = false,
    ): void {
        $centerAttributes = $center->getAttributes();
        if ($center->status !== 'ACTIVE'
            || ! filled($centerAttributes['approved_by_user_id'] ?? null)
            || ! filled($centerAttributes['approved_at'] ?? null)) {
            throw new RuntimeException('The logistics center must be active before it can manage barangay coverage.');
        }

        if (! $center->relationLoaded('address')) {
            $center->load('address');
        }
        $address = $center->address;

        if (! $address?->province_code || ! $address?->municipality_code
            || ! $address?->province_name || ! $address?->municipality_name) {
            throw new RuntimeException('Configure a canonical PSGC province and municipality for this logistics center before saving coverage.');
        }

        $canonical = $this->canonicalMunicipality($center);
        if (! $canonical
            || $canonical['province_code'] !== trim($provinceCode)
            || $canonical['municipality_code'] !== trim($municipalityCode)) {
            if ($allowExplicitRemoteCoverage && trim($provinceCode) !== '' && trim($municipalityCode) !== '') {
                return;
            }

            throw new RuntimeException('A service area must belong to this logistics center’s canonical province and municipality.');
        }
    }

    /**
     * @return array{region_code: string, province_code: string, province_name: string, municipality_code: string, municipality_name: string}|null
     */
    public function canonicalMunicipality(LogisticsCenter $center): ?array
    {
        if (! $center->relationLoaded('address')) {
            $center->load('address');
        }
        $address = $center->address;

        if (! $address?->province_code || ! $address?->province_name
            || ! $address?->municipality_code || ! $address?->municipality_name) {
            return null;
        }

        return PhilippineAddressController::canonicalMunicipalityFor(
            (string) $address->province_code,
            (string) $address->province_name,
            (string) $address->municipality_code,
            (string) $address->municipality_name,
        );
    }

    public function singleBarangayLocation(LogisticsCenter $center, ?ServiceArea $area): ?ServiceAreaLocation
    {
        if (! $area || ! $area->is_active || (int) $area->logistics_center_id !== (int) $center->id) {
            return null;
        }

        if (! $area->relationLoaded('locations')) {
            $area->load('locations');
        }
        if ($area->locations->count() !== 1) {
            return null;
        }

        $location = $area->locations->first();
        if (! $location) {
            return null;
        }

        if (! $this->centerAuthorizesExactLocation($center, $location)) {
            return null;
        }

        return $location;
    }

    /** Authorization comes from the exact configured PSGC area, never proximity alone. */
    public function centerAuthorizesExactLocation(LogisticsCenter $center, ServiceAreaLocation $location): bool
    {
        $location->loadMissing('serviceArea.logisticsCenter');
        $area = $location->serviceArea;
        if (! $area || ! $area->is_active || (int) $area->logistics_center_id !== (int) $center->id
            || (int) $area->logisticsCenter?->id !== (int) $center->id
            || ! $this->centerCoversApprovedArea($center)) {
            return false;
        }

        if ($this->centerCoversMunicipality($center, $location->province_code, $location->municipality_code)) {
            return true;
        }

        if ($location->coverage_type === 'PRIMARY') {
            return true;
        }

        return $this->hasApprovedExtendedCoverage($center, $location);
    }

    public function centerAuthorizesAddress(LogisticsCenter $center, object $address): bool
    {
        return ServiceAreaLocation::query()
            ->where('province_code', trim((string) $address->province_code))
            ->where('municipality_code', trim((string) $address->municipality_code))
            ->where('barangay_code', trim((string) $address->barangay_code))
            ->whereHas('serviceArea', fn (Builder $area) => $area
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true))
            ->with('serviceArea.logisticsCenter.address')
            ->get()
            ->contains(fn (ServiceAreaLocation $location): bool => $this->centerAuthorizesExactLocation($center, $location));
    }

    /** Whether any approved, active Logistics center is authorized for this exact PSGC address. */
    public function hasApprovedCenterCoverageForAddress(?object $address): bool
    {
        // Incomplete PSGC data cannot be used to prove that a location is uncovered.
        if (! $address
            || blank($address->province_code ?? null)
            || blank($address->municipality_code ?? null)
            || blank($address->barangay_code ?? null)) {
            return true;
        }

        return LogisticsCenter::query()
            ->approvedActive()
            ->whereHas('owner', fn (Builder $owner) => $owner
                ->where('account_type', User::TYPE_LOGISTICS)
                ->where('status', User::STATUS_ACTIVE))
            ->with('address')
            ->get()
            ->contains(fn (LogisticsCenter $center): bool => $this->centerAuthorizesAddress($center, $address));
    }

    private function centerCoversApprovedArea(LogisticsCenter $center): bool
    {
        $attributes = $center->getAttributes();

        return $center->status === 'ACTIVE'
            && filled($attributes['approved_by_user_id'] ?? null)
            && filled($attributes['approved_at'] ?? null);
    }

    public function assertRiderCanCoverArea(
        LogisticsCenter $center,
        RiderProfile $rider,
        ServiceArea $area,
    ): ServiceAreaLocation {
        $location = $this->singleBarangayLocation($center, $area);
        if (! $location) {
            throw new RuntimeException('Only active, single-barangay areas within the center’s municipality or with approved explicit fallback coverage can be assigned to a local rider.');
        }

        if (! $rider->relationLoaded('user')) {
            $rider->load('user');
        }
        if ((int) $rider->logistics_center_id !== (int) $center->id) {
            throw new RuntimeException('The rider and service area must belong to the same logistics center.');
        }

        if (! in_array($rider->status, ['ACTIVE', 'WARNED'], true)
            || $rider->user?->account_type !== User::TYPE_RIDER
            || $rider->user?->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Only active or warned riders with an active user account can receive local assignments.');
        }

        if (! RiderVehicleType::isLocalBarangay($rider->vehicle_type)) {
            throw new RuntimeException('Only motorcycle and tricycle riders can receive local barangay assignments.');
        }

        return $location;
    }

    public function centerCoversMunicipality(
        LogisticsCenter $center,
        ?string $provinceCode,
        ?string $municipalityCode,
    ): bool {
        $centerAttributes = $center->getAttributes();
        if (! $center->relationLoaded('address')) {
            $center->load('address');
        }
        $canonical = $this->canonicalMunicipality($center);

        return $center->status === 'ACTIVE'
            && filled($centerAttributes['approved_by_user_id'] ?? null)
            && filled($centerAttributes['approved_at'] ?? null)
            && $canonical !== null
            && $canonical['province_code'] === trim((string) $provinceCode)
            && $canonical['municipality_code'] === trim((string) $municipalityCode);
    }

    private function hasApprovedExtendedCoverage(LogisticsCenter $center, ServiceAreaLocation $location): bool
    {
        if (! app(LogisticsSchemaReadiness::class)->smartCoverageReady()
            || ! in_array($location->coverage_type, [LogisticsCoverageRoute::TYPE_SECONDARY, LogisticsCoverageRoute::TYPE_EXTENDED], true)) {
            return false;
        }

        return LogisticsCoverageRoute::query()
            ->approvedActive()
            ->currentlyValid()
            ->where('coverage_type', $location->coverage_type)
            ->where('shipment_type', LogisticsCoverageRoute::SHIPMENT_MARKETPLACE)
            ->where('destination_service_area_location_id', $location->id)
            ->whereHas('approvedBy', fn (Builder $admin) => $admin
                ->where('account_type', User::TYPE_ADMIN)
                ->where('status', User::STATUS_ACTIVE))
            ->whereHas('originLogisticsCenter', fn (Builder $origin) => $origin->approvedActive())
            ->whereHas('destinationLocation.serviceArea', fn (Builder $area) => $area
                ->where('logistics_center_id', $center->id)
                ->where('is_active', true)
                ->whereHas('logisticsCenter', fn (Builder $destination) => $destination->approvedActive()))
            ->exists();
    }
}
