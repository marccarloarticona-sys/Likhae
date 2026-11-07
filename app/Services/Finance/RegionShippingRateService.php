<?php

namespace App\Services\Finance;

use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Address;
use App\Support\FinancialRules;
use App\Support\PhilippineRegionCatalog;
use Illuminate\Validation\ValidationException;

final class RegionShippingRateService
{
    public const DEFAULT_ADDITIONAL_TIER_FEE = '5.00';
    public const DEFAULT_DISTANCE_KM_PER_TIER = 100;
    public const DEFAULT_INTERISLAND_TIER_SURCHARGE = 2;

    /** @var array<string, string>|null */
    private static ?array $provinceRegionIndex = null;

    /**
     * @return array{shipping_fee:string,snapshot:array<string,mixed>}
     */
    public function quote(?Address $origin, ?Address $destination, string|int|float $baseFee): array
    {
        $baseCents = FinancialRules::cents($baseFee);
        $originRegion = $this->regionForAddress($origin);
        $destinationRegion = $this->regionForAddress($destination);

        if ($originRegion === null || $destinationRegion === null) {
            throw ValidationException::withMessages([
                'address_id' => 'Shipping needs a valid saved Seller business address and Buyer delivery address. Update the addresses before continuing.',
            ]);
        }

        $sameRegion = $originRegion['key'] === $destinationRegion['key'];
        $distanceKm = $sameRegion ? 0.0 : $this->distanceKm($originRegion, $destinationRegion);
        $interislandTiers = ! $sameRegion && $originRegion['island'] !== $destinationRegion['island']
            ? $this->interislandTierSurcharge()
            : 0;

        $source = 'same_region';
        $referenceAddonCents = null;
        if ($sameRegion) {
            $tier = 0;
        } else {
            $tier = $this->configuredTier($originRegion['key'], $destinationRegion['key']);
            if ($tier !== null) {
                $source = 'route_override';
            } else {
                $reference = $this->referenceTier($originRegion['key'], $destinationRegion['key']);
                if ($reference !== null) {
                    [$tier, $referenceAddonCents] = $reference;
                    $source = 'reference_rate';
                } else {
                    $tier = $this->estimatedTier($distanceKm, $interislandTiers);
                    $source = 'distance_estimate';
                }
            }
        }

        $tierFee = (string) PlatformSetting::valueOf('additional_region_tier_fee', self::DEFAULT_ADDITIONAL_TIER_FEE);
        $tierFeeCents = FinancialRules::cents($tierFee);
        if ($tierFeeCents < 0) {
            throw ValidationException::withMessages(['shipping_rate' => 'The additional region tier fee must be zero or greater.']);
        }

        $regionalAddonCents = $referenceAddonCents ?? ($tier * $tierFeeCents);
        $totalCents = $baseCents + $regionalAddonCents;
        $effectiveTierFeeCents = $tier > 0 ? intdiv($regionalAddonCents, $tier) : $tierFeeCents;

        return [
            'shipping_fee' => FinancialRules::amount($totalCents),
            'snapshot' => [
                'base_fee' => FinancialRules::amount($baseCents),
                'origin_region' => $originRegion['name'],
                'destination_region' => $destinationRegion['name'],
                'additional_region_tiers' => $tier,
                'additional_region_tier_fee' => FinancialRules::amount($effectiveTierFeeCents),
                'regional_addon_fee' => FinancialRules::amount($regionalAddonCents),
                'shipping_fee' => FinancialRules::amount($totalCents),
                'rate_source' => $source,
                'estimated_distance_km' => round($distanceKm, 1),
                'interisland_tier_surcharge' => $interislandTiers,
                'distance_km_per_tier' => $sameRegion ? null : $this->distanceKmPerTier(),
            ],
        ];
    }

    /** @return list<string> */
    public function supportedRegions(): array
    {
        return array_map(static fn (array $region): string => $region['name'], array_values(PhilippineRegionCatalog::ordered()));
    }

    /** @return list<array{origin:string,destination:string,tier:int}> */
    public function parseTierMap(string $lines): array
    {
        $map = [];
        foreach (preg_split('/\R/u', $lines) ?: [] as $lineNumber => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || ! ctype_digit($parts[2])) {
                throw ValidationException::withMessages([
                    'shipping_region_tiers' => 'Line '.($lineNumber + 1).' must use Origin Region | Destination Region | Tier.',
                ]);
            }

            $originRegion = $this->regionForName($parts[0]);
            $destinationRegion = $this->regionForName($parts[1]);
            $tier = (int) $parts[2];
            if ($originRegion === null || $destinationRegion === null) {
                throw ValidationException::withMessages([
                    'shipping_region_tiers' => 'Line '.($lineNumber + 1).' must use two supported Philippine region names.',
                ]);
            }
            if ($originRegion['key'] === $destinationRegion['key'] || $tier < 1 || $tier > 20) {
                throw ValidationException::withMessages([
                    'shipping_region_tiers' => 'Each override must name two different regions and a tier from 1 to 20.',
                ]);
            }

            $key = $this->routeKey($originRegion['key'], $destinationRegion['key']);
            if (isset($map[$key])) {
                throw ValidationException::withMessages(['shipping_region_tiers' => 'A directed region route may appear only once.']);
            }

            $map[$key] = ['origin' => $originRegion['name'], 'destination' => $destinationRegion['name'], 'tier' => $tier];
        }

        return array_values($map);
    }

    public function normalizeTierMap(string $lines): string
    {
        return collect($this->parseTierMap($lines))
            ->map(fn (array $route): string => $route['origin'].' | '.$route['destination'].' | '.$route['tier'])
            ->implode("\n");
    }

    private function configuredTier(string $origin, string $destination): ?int
    {
        $configured = (string) PlatformSetting::valueOf('shipping_region_tiers', '');
        $targetKey = $this->routeKey($origin, $destination);
        foreach ($this->parseTierMap($configured) as $route) {
            $originRegion = $this->regionForName($route['origin']);
            $destinationRegion = $this->regionForName($route['destination']);
            if ($originRegion && $destinationRegion
                && $this->routeKey($originRegion['key'], $destinationRegion['key']) === $targetKey) {
                return $route['tier'];
            }
        }

        return null;
    }

    private function estimatedTier(float $distanceKm, int $interislandTiers): int
    {
        $distanceTier = (int) ceil($distanceKm / $this->distanceKmPerTier());

        return min($this->maximumTier(), max(1, $distanceTier + $interislandTiers));
    }

    /** @return array{0:int,1:int}|null */
    private function referenceTier(string $originKey, string $destinationKey): ?array
    {
        $reference = [
            $this->routeKey('IV-A', 'NCR') => [1, 500],
            $this->routeKey('IV-A', 'III') => [2, 1000],
            $this->routeKey('IV-A', 'I') => [5, 2500],
        ];

        return $reference[$this->routeKey($originKey, $destinationKey)] ?? null;
    }

    private function distanceKmPerTier(): float
    {
        $value = (float) PlatformSetting::valueOf(
            'regional_shipping_distance_km_per_tier',
            config('likhae.regional_shipping_distance_km_per_tier', self::DEFAULT_DISTANCE_KM_PER_TIER),
        );

        return min(1000.0, max(1.0, $value));
    }

    private function interislandTierSurcharge(): int
    {
        $value = (int) PlatformSetting::valueOf(
            'regional_shipping_interisland_tier_surcharge',
            config('likhae.regional_shipping_interisland_tier_surcharge', self::DEFAULT_INTERISLAND_TIER_SURCHARGE),
        );

        return min(20, max(0, $value));
    }

    private function maximumTier(): int
    {
        $value = (int) config('likhae.regional_shipping_max_tiers', 20);

        return min(20, max(1, $value));
    }

    /** @param array{latitude:float,longitude:float} $origin @param array{latitude:float,longitude:float} $destination */
    private function distanceKm(array $origin, array $destination): float
    {
        $earthRadiusKm = 6371.0;
        $latitudeDelta = deg2rad($destination['latitude'] - $origin['latitude']);
        $longitudeDelta = deg2rad($destination['longitude'] - $origin['longitude']);
        $originLatitude = deg2rad($origin['latitude']);
        $destinationLatitude = deg2rad($destination['latitude']);
        $a = sin($latitudeDelta / 2) ** 2
            + cos($originLatitude) * cos($destinationLatitude) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    /** @return array{key:string,name:string,latitude:float,longitude:float,island:string}|null */
    private function regionForAddress(?Address $address): ?array
    {
        if (! $address) {
            return null;
        }

        $nameRegion = $this->regionForName((string) $address->region_name);
        $codeRegion = $this->regionForCode((string) $address->region_code);
        $provinceRegion = $this->regionForProvinceCode((string) $address->province_code);
        if ($nameRegion && $codeRegion && $nameRegion['key'] !== $codeRegion['key']) {
            return null;
        }
        if (($nameRegion && $provinceRegion && $nameRegion['key'] !== $provinceRegion['key'])
            || ($codeRegion && $provinceRegion && $codeRegion['key'] !== $provinceRegion['key'])) {
            return null;
        }

        return $codeRegion ?? $nameRegion ?? $provinceRegion;
    }

    /** @return array{key:string,name:string,latitude:float,longitude:float,island:string}|null */
    private function regionForProvinceCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        // Older saved addresses may not have the optional region columns yet.
        // Resolve their canonical province PSGC code from the bundled address
        // catalog so checkout does not need a network lookup to quote shipping.
        $regionKey = PhilippineRegionCatalog::identityForCode($code);
        if ($regionKey === null) {
            self::$provinceRegionIndex ??= $this->buildProvinceRegionIndex();
            $regionKey = self::$provinceRegionIndex[$code] ?? null;
        }

        $region = $regionKey === null ? null : (PhilippineRegionCatalog::all()[$regionKey] ?? null);

        return $regionKey !== null && $region !== null ? $this->regionRecord($regionKey, $region) : null;
    }

    /** @return array<string, string> */
    private function buildProvinceRegionIndex(): array
    {
        $path = storage_path('app/psgc/provinces.json');
        if (! is_file($path)) {
            return [];
        }

        $records = json_decode((string) file_get_contents($path), true);
        if (! is_array($records)) {
            return [];
        }

        $index = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! is_numeric($record['reg'] ?? null)) {
                continue;
            }

            $regionCode = sprintf('%02d00000000', (int) $record['reg']);
            $regionKey = PhilippineRegionCatalog::identityForCode($regionCode);
            if ($regionKey === null) {
                continue;
            }

            foreach (['code', 'correspondence_code'] as $field) {
                $provinceCode = strtoupper(trim((string) ($record[$field] ?? '')));
                if ($provinceCode !== '') {
                    $index[$provinceCode] = $regionKey;
                }
            }
        }

        return $index;
    }

    /** @return array{key:string,name:string,latitude:float,longitude:float,island:string}|null */
    private function regionForCode(string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        $key = PhilippineRegionCatalog::identityForCode($code);
        $region = $key === null ? null : (PhilippineRegionCatalog::all()[$key] ?? null);

        return $key !== null && $region !== null ? $this->regionRecord($key, $region) : null;
    }

    /** @return array{key:string,name:string,latitude:float,longitude:float,island:string}|null */
    private function regionForName(string $name): ?array
    {
        $normalized = $this->normalizeLabel($name);
        if ($normalized === '') {
            return null;
        }

        foreach (PhilippineRegionCatalog::all() as $key => $region) {
            foreach ([$key, $region['name'], ...$region['aliases']] as $candidate) {
                if ($normalized === $this->normalizeLabel($candidate)) {
                    return $this->regionRecord($key, $region);
                }
            }
        }

        return null;
    }

    /** @param array{name:string,aliases:list<string>,latitude:float,longitude:float,island:string} $region
     * @return array{key:string,name:string,latitude:float,longitude:float,island:string}
     */
    private function regionRecord(string $key, array $region): array
    {
        return [
            'key' => $key,
            'name' => $region['name'],
            'latitude' => $region['latitude'],
            'longitude' => $region['longitude'],
            'island' => $region['island'],
        ];
    }

    private function normalizeLabel(string $name): string
    {
        $name = mb_strtoupper(trim($name));
        $name = preg_replace('/[^A-Z0-9]+/u', ' ', $name) ?? $name;

        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    private function routeKey(string $originKey, string $destinationKey): string
    {
        return $originKey.'|'.$destinationKey;
    }
}
