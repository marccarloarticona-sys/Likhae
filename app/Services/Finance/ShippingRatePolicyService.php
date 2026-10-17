<?php

namespace App\Services\Finance;

use App\Models\Finance\ShippingRateVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ShippingRatePolicyService
{
    // FUTURE SHIPPING PRICING:
    // The shipping-rate architecture is intentionally designed so a future
    // pricing engine may select rates using location, distance, parcel weight,
    // parcel dimensions, or service level. This capability is NOT IMPLEMENTED
    // in the current release.
    public const DEFAULT_RIDER_PERCENTAGE = '60.0000';
    public const DEFAULT_LOGISTICS_PERCENTAGE = '40.0000';
    public const DEFAULT_PICKUP_WEIGHT = 8;
    public const DEFAULT_HUB_WEIGHT = 7;
    public const DEFAULT_LAST_MILE_WEIGHT = 15;

    public function schemaReady(): bool
    {
        return Schema::hasTable('shipping_rate_versions')
            && Schema::hasTable('shipping_rate_change_requests')
            && Schema::hasColumn('seller_orders', 'shipping_rate_version_id');
    }

    public function currentVersion(?Carbon $at = null, bool $lockForUpdate = false): ?ShippingRateVersion
    {
        if (! $this->schemaReady()) {
            return null;
        }

        $query = ShippingRateVersion::query()
            ->global()
            ->where('status', ShippingRateVersion::STATUS_ACTIVE)
            ->where('effective_from', '<=', $at ?? now())
            ->where(fn ($versions) => $versions
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at ?? now()))
            ->orderByDesc('effective_from')
            ->orderByDesc('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        /** @var Collection<int, ShippingRateVersion> $matches */
        $matches = $query->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('More than one GLOBAL shipping-rate version is effective. Checkout is paused to protect pricing accuracy.');
        }

        return $matches->first();
    }

    /** @return array{version:ShippingRateVersion|null,shipping_fee:string,rider_pool_percentage:string,logistics_percentage:string,pickup_rider_weight:int,hub_to_hub_rider_weight:int,last_mile_rider_weight:int,initialized:bool} */
    public function currentPolicy(?Carbon $at = null, bool $lockForUpdate = false): array
    {
        $version = $this->currentVersion($at, $lockForUpdate);
        if ($version) {
            return [
                'version' => $version,
                'shipping_fee' => (string) $version->shipping_fee,
                'rider_pool_percentage' => (string) $version->rider_pool_percentage,
                'logistics_percentage' => (string) $version->logistics_percentage,
                'pickup_rider_weight' => (int) $version->pickup_rider_weight,
                'hub_to_hub_rider_weight' => (int) $version->hub_to_hub_rider_weight,
                'last_mile_rider_weight' => (int) $version->last_mile_rider_weight,
                'initialized' => true,
            ];
        }

        // Bootstrap fallback preserves the current ₱50 global Checkout rate
        // until an Admin explicitly creates the first persistent version.
        return [
            'version' => null,
            'shipping_fee' => number_format((float) config('likhae.standard_shipping_fee', 50), 2, '.', ''),
            'rider_pool_percentage' => self::DEFAULT_RIDER_PERCENTAGE,
            'logistics_percentage' => self::DEFAULT_LOGISTICS_PERCENTAGE,
            'pickup_rider_weight' => self::DEFAULT_PICKUP_WEIGHT,
            'hub_to_hub_rider_weight' => self::DEFAULT_HUB_WEIGHT,
            'last_mile_rider_weight' => self::DEFAULT_LAST_MILE_WEIGHT,
            'initialized' => false,
        ];
    }
}
