<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\LogisticsCenter;
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
            ->where('status', 'ACTIVE')
            ->whereIn('vehicle_type', RiderVehicleType::localValues())
            ->whereHas('user', fn (Builder $query) => $query
                ->where('account_type', User::TYPE_RIDER)
                ->where('status', User::STATUS_ACTIVE));
    }

    public function assertCenterCoversMunicipality(
        LogisticsCenter $center,
        string $provinceCode,
        string $municipalityCode,
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

        if (! $address?->province_code || ! $address?->municipality_code) {
            throw new RuntimeException('Configure a canonical PSGC province and municipality for this logistics center before saving coverage.');
        }

        if (trim((string) $address->province_code) !== trim($provinceCode)
            || trim((string) $address->municipality_code) !== trim($municipalityCode)) {
            throw new RuntimeException('A service area must belong to this logistics center’s canonical province and municipality.');
        }
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

        if (! $this->centerCoversMunicipality($center, $location->province_code, $location->municipality_code)) {
            return null;
        }

        return $location;
    }

    public function assertRiderCanCoverArea(
        LogisticsCenter $center,
        RiderProfile $rider,
        ServiceArea $area,
    ): ServiceAreaLocation {
        $location = $this->singleBarangayLocation($center, $area);
        if (! $location) {
            throw new RuntimeException('Only active service areas with exactly one barangay in this center’s canonical municipality can be assigned to a local rider.');
        }

        if (! $rider->relationLoaded('user')) {
            $rider->load('user');
        }
        if ((int) $rider->logistics_center_id !== (int) $center->id) {
            throw new RuntimeException('The rider and service area must belong to the same logistics center.');
        }

        if ($rider->status !== 'ACTIVE'
            || $rider->user?->account_type !== User::TYPE_RIDER
            || $rider->user?->status !== User::STATUS_ACTIVE) {
            throw new RuntimeException('Only riders with an active rider profile and active user account can receive local assignments.');
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
        $address = $center->address;

        return $center->status === 'ACTIVE'
            && filled($centerAttributes['approved_by_user_id'] ?? null)
            && filled($centerAttributes['approved_at'] ?? null)
            && filled($address?->province_code)
            && filled($address?->municipality_code)
            && trim((string) $address->province_code) === trim((string) $provinceCode)
            && trim((string) $address->municipality_code) === trim((string) $municipalityCode);
    }
}
