<?php

namespace App\Support;

/**
 * Canonical identities, PSGC identifiers, geographic estimates, and display
 * order for the Philippine regions used by addresses and shipping quotes.
 *
 * Display order is presentation metadata; it is never used as an identifier.
 */
final class PhilippineRegionCatalog
{
    /** @var array<string, array{display_order:int,name:string,aliases:list<string>,psgc_code:string,correspondence_code:string,legacy_codes:list<string>,latitude:float,longitude:float,island:string}> */
    private const REGIONS = [
        'I' => ['display_order' => 1, 'name' => 'Region I (Ilocos Region)', 'aliases' => ['Region I', 'Ilocos Region', 'Ilocos'], 'psgc_code' => '0100000000', 'correspondence_code' => '010000000', 'legacy_codes' => ['01'], 'latitude' => 17.1950, 'longitude' => 120.6500, 'island' => 'luzon'],
        'CAR' => ['display_order' => 2, 'name' => 'Cordillera Administrative Region (CAR)', 'aliases' => ['CAR', 'Cordillera Administrative Region', 'CAR (Cordillera Administrative Region)'], 'psgc_code' => '1400000000', 'correspondence_code' => '140000000', 'legacy_codes' => ['14'], 'latitude' => 17.3512, 'longitude' => 121.1719, 'island' => 'luzon'],
        'II' => ['display_order' => 3, 'name' => 'Region II (Cagayan Valley)', 'aliases' => ['Region II', 'Cagayan Valley'], 'psgc_code' => '0200000000', 'correspondence_code' => '020000000', 'legacy_codes' => ['02'], 'latitude' => 17.4500, 'longitude' => 121.7500, 'island' => 'luzon'],
        'III' => ['display_order' => 4, 'name' => 'Region III (Central Luzon)', 'aliases' => ['Region III', 'Central Luzon'], 'psgc_code' => '0300000000', 'correspondence_code' => '030000000', 'legacy_codes' => ['03'], 'latitude' => 15.5100, 'longitude' => 120.7500, 'island' => 'luzon'],
        'NCR' => ['display_order' => 5, 'name' => 'National Capital Region (NCR)', 'aliases' => ['NCR', 'Metro Manila', 'National Capital Region', 'NCR (National Capital Region)'], 'psgc_code' => '1300000000', 'correspondence_code' => '130000000', 'legacy_codes' => ['13'], 'latitude' => 14.5995, 'longitude' => 120.9842, 'island' => 'luzon'],
        'IV-A' => ['display_order' => 6, 'name' => 'Region IV-A (CALABARZON)', 'aliases' => ['Region IV-A', 'Region IV A', 'CALABARZON'], 'psgc_code' => '0400000000', 'correspondence_code' => '040000000', 'legacy_codes' => ['04'], 'latitude' => 14.1000, 'longitude' => 121.3000, 'island' => 'luzon'],
        'IV-B' => ['display_order' => 7, 'name' => 'MIMAROPA Region', 'aliases' => ['Region IV-B', 'Region IV B', 'Region IV-B (MIMAROPA)', 'MIMAROPA', 'MIMAROPA Region (MIMAROPA)'], 'psgc_code' => '1700000000', 'correspondence_code' => '170000000', 'legacy_codes' => ['17'], 'latitude' => 12.7500, 'longitude' => 121.4000, 'island' => 'mimaropa'],
        'V' => ['display_order' => 8, 'name' => 'Region V (Bicol Region)', 'aliases' => ['Region V', 'Bicol Region', 'Bicol'], 'psgc_code' => '0500000000', 'correspondence_code' => '050000000', 'legacy_codes' => ['05'], 'latitude' => 13.2500, 'longitude' => 123.5000, 'island' => 'luzon'],
        'VI' => ['display_order' => 9, 'name' => 'Region VI (Western Visayas)', 'aliases' => ['Region VI', 'Western Visayas'], 'psgc_code' => '0600000000', 'correspondence_code' => '060000000', 'legacy_codes' => ['06'], 'latitude' => 11.0000, 'longitude' => 122.5500, 'island' => 'visayas'],
        'NIR' => ['display_order' => 10, 'name' => 'Negros Island Region (NIR)', 'aliases' => ['NIR', 'Negros Island Region'], 'psgc_code' => '1800000000', 'correspondence_code' => '', 'legacy_codes' => ['18'], 'latitude' => 10.1500, 'longitude' => 123.0000, 'island' => 'visayas'],
        'VII' => ['display_order' => 11, 'name' => 'Region VII (Central Visayas)', 'aliases' => ['Region VII', 'Central Visayas'], 'psgc_code' => '0700000000', 'correspondence_code' => '070000000', 'legacy_codes' => ['07'], 'latitude' => 10.3500, 'longitude' => 123.9000, 'island' => 'visayas'],
        'VIII' => ['display_order' => 12, 'name' => 'Region VIII (Eastern Visayas)', 'aliases' => ['Region VIII', 'Eastern Visayas'], 'psgc_code' => '0800000000', 'correspondence_code' => '080000000', 'legacy_codes' => ['08'], 'latitude' => 11.2500, 'longitude' => 125.0500, 'island' => 'visayas'],
        'IX' => ['display_order' => 13, 'name' => 'Region IX (Zamboanga Peninsula)', 'aliases' => ['Region IX', 'Zamboanga Peninsula'], 'psgc_code' => '0900000000', 'correspondence_code' => '090000000', 'legacy_codes' => ['09'], 'latitude' => 7.8500, 'longitude' => 122.4500, 'island' => 'mindanao'],
        'X' => ['display_order' => 14, 'name' => 'Region X (Northern Mindanao)', 'aliases' => ['Region X', 'Northern Mindanao'], 'psgc_code' => '1000000000', 'correspondence_code' => '100000000', 'legacy_codes' => ['10'], 'latitude' => 8.4500, 'longitude' => 124.7000, 'island' => 'mindanao'],
        'XIII' => ['display_order' => 15, 'name' => 'Region XIII (Caraga)', 'aliases' => ['Region XIII', 'Caraga', 'Caraga Region'], 'psgc_code' => '1600000000', 'correspondence_code' => '160000000', 'legacy_codes' => ['16'], 'latitude' => 8.8000, 'longitude' => 126.0000, 'island' => 'mindanao'],
        'BARMM' => ['display_order' => 16, 'name' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)', 'aliases' => ['BARMM', 'Bangsamoro', 'Bangsamoro Autonomous Region in Muslim Mindanao'], 'psgc_code' => '1900000000', 'correspondence_code' => '150000000', 'legacy_codes' => ['19', '1500000000', '15'], 'latitude' => 6.6000, 'longitude' => 124.4000, 'island' => 'mindanao'],
        'XII' => ['display_order' => 17, 'name' => 'Region XII (SOCCSKSARGEN)', 'aliases' => ['Region XII', 'Soccsksargen'], 'psgc_code' => '1200000000', 'correspondence_code' => '120000000', 'legacy_codes' => ['12'], 'latitude' => 6.3000, 'longitude' => 124.8000, 'island' => 'mindanao'],
        'XI' => ['display_order' => 18, 'name' => 'Region XI (Davao Region)', 'aliases' => ['Region XI', 'Davao Region'], 'psgc_code' => '1100000000', 'correspondence_code' => '110000000', 'legacy_codes' => ['11'], 'latitude' => 7.2500, 'longitude' => 126.0000, 'island' => 'mindanao'],
    ];

    /** @return array<string, array{display_order:int,name:string,aliases:list<string>,psgc_code:string,correspondence_code:string,legacy_codes:list<string>,latitude:float,longitude:float,island:string}> */
    public static function ordered(): array
    {
        $regions = self::REGIONS;
        uasort($regions, static fn (array $left, array $right): int => $left['display_order'] <=> $right['display_order']);

        return $regions;
    }

    /** @return array<string, array{display_order:int,name:string,aliases:list<string>,psgc_code:string,correspondence_code:string,legacy_codes:list<string>,latitude:float,longitude:float,island:string}> */
    public static function all(): array
    {
        return self::REGIONS;
    }

    public static function displayOrderForCode(string $code): ?int
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        foreach (self::REGIONS as $region) {
            if (in_array($code, self::codesFor($region), true)) {
                return $region['display_order'];
            }
        }

        return null;
    }

    public static function identityForCode(string $code): ?string
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }

        foreach (self::REGIONS as $identity => $region) {
            if ($code === $identity || in_array($code, self::codesFor($region), true)) {
                return $identity;
            }
        }

        return null;
    }

    /** @param array{psgc_code:string,correspondence_code:string,legacy_codes:list<string>} $region
     * @return list<string>
     */
    private static function codesFor(array $region): array
    {
        return array_values(array_filter([
            $region['psgc_code'],
            $region['correspondence_code'],
            ...$region['legacy_codes'],
        ], static fn (string $code): bool => $code !== ''));
    }
}
