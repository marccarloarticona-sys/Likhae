<?php

namespace App\Services\Finance;

use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Address;
use App\Support\FinancialRules;
use Illuminate\Validation\ValidationException;

final class RegionShippingRateService
{
    public const DEFAULT_ADDITIONAL_TIER_FEE = '6.00';

    /**
     * @return array{shipping_fee:string,snapshot:array<string,mixed>}
     */
    public function quote(?Address $origin, ?Address $destination, string|int|float $baseFee): array
    {
        $baseCents = FinancialRules::cents($baseFee);
        $originRegion = $this->regionName($origin);
        $destinationRegion = $this->regionName($destination);
        $tier = 0;

        if ($originRegion === null || $destinationRegion === null) {
            throw ValidationException::withMessages([
                'address_id' => 'Shipping needs a verified Seller and Buyer region. Update the saved addresses before continuing.',
            ]);
        }

        if ($this->normalizeRegion($originRegion) !== $this->normalizeRegion($destinationRegion)) {
            $tier = $this->configuredTier($originRegion, $destinationRegion);
            if ($tier === null) {
                throw ValidationException::withMessages([
                    'shipping_rate' => 'This Seller-to-Buyer region route has no approved distance tier yet. Ask Admin to configure it before checkout.',
                ]);
            }
        }

        $tierFee = (string) PlatformSetting::valueOf('additional_region_tier_fee', self::DEFAULT_ADDITIONAL_TIER_FEE);
        $tierFeeCents = FinancialRules::cents($tierFee);
        if ($tierFeeCents < 0) {
            throw ValidationException::withMessages(['shipping_rate' => 'The additional region tier fee must be zero or greater.']);
        }

        $totalCents = $baseCents + ($tier * $tierFeeCents);

        return [
            'shipping_fee' => FinancialRules::amount($totalCents),
            'snapshot' => [
                'base_fee' => FinancialRules::amount($baseCents),
                'origin_region' => $originRegion,
                'destination_region' => $destinationRegion,
                'additional_region_tiers' => $tier,
                'additional_region_tier_fee' => FinancialRules::amount($tierFeeCents),
                'shipping_fee' => FinancialRules::amount($totalCents),
            ],
        ];
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

            [$origin, $destination] = $this->pair($parts[0], $parts[1]);
            $tier = (int) $parts[2];
            if ($this->normalizeRegion($origin) === $this->normalizeRegion($destination) || $tier < 1 || $tier > 20) {
                throw ValidationException::withMessages([
                    'shipping_region_tiers' => 'Each route must name two different regions and a tier from 1 to 20.',
                ]);
            }

            $key = $this->pairKey($origin, $destination);
            if (isset($map[$key])) {
                throw ValidationException::withMessages(['shipping_region_tiers' => 'A region pair may appear only once.']);
            }
            $map[$key] = ['origin' => $origin, 'destination' => $destination, 'tier' => $tier];
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
        foreach ($this->parseTierMap($configured) as $route) {
            if ($this->pairKey($route['origin'], $route['destination']) === $this->pairKey($origin, $destination)) {
                return $route['tier'];
            }
        }

        return null;
    }

    private function regionName(?Address $address): ?string
    {
        $name = trim((string) $address?->region_name);
        if ($name !== '') {
            return $name;
        }

        if (! $address || ! $address->province_code || ! $address->province_name
            || ! $address->municipality_code || ! $address->municipality_name) {
            return null;
        }

        $canonical = \App\Http\Controllers\Auth\PhilippineAddressController::canonicalMunicipalityFor(
            (string) $address->province_code,
            (string) $address->province_name,
            (string) $address->municipality_code,
            (string) $address->municipality_name,
        );
        if (! $canonical) {
            return null;
        }

        return \App\Http\Controllers\Auth\PhilippineAddressController::regionNameForCode($canonical['region_code']);
    }

    /** @return array{0:string,1:string} */
    private function pair(string $origin, string $destination): array
    {
        return strcmp($this->normalizeRegion($origin), $this->normalizeRegion($destination)) <= 0
            ? [trim($origin), trim($destination)]
            : [trim($destination), trim($origin)];
    }

    private function pairKey(string $origin, string $destination): string
    {
        [$first, $second] = $this->pair($origin, $destination);

        return $this->normalizeRegion($first).'|'.$this->normalizeRegion($second);
    }

    private function normalizeRegion(string $name): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }
}
