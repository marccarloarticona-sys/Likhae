<?php

namespace Tests\Unit;

use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Address;
use App\Services\Finance\RegionShippingRateService;
use App\Support\PhilippineRegionCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegionShippingRateServiceTest extends TestCase
{
    private string $originalDefaultConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefaultConnection = (string) config('database.default');
        config(['database.connections.region_shipping_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('region_shipping_test');
        DB::setDefaultConnection('region_shipping_test');
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('value_type')->default('string');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('region_shipping_test');
        DB::setDefaultConnection($this->originalDefaultConnection);
        parent::tearDown();
    }

    public function test_same_region_uses_the_active_base_shipping_fee(): void
    {
        $quote = app(RegionShippingRateService::class)->quote(
            $this->address('Central Luzon'),
            $this->address('Region III'),
            '50.00',
        );

        $this->assertSame('50.00', $quote['shipping_fee']);
        $this->assertSame(0, $quote['snapshot']['additional_region_tiers']);
        $this->assertSame('0.00', $quote['snapshot']['regional_addon_fee']);
        $this->assertSame('same_region', $quote['snapshot']['rate_source']);
    }

    public function test_four_reference_routes_keep_the_required_add_ons(): void
    {
        $service = app(RegionShippingRateService::class);
        $cases = [
            ['Region IV-A', 'CALABARZON', '50.00', 0, '50.00'],
            ['Region IV-A', 'NCR', '50.00', 1, '55.00'],
            ['Region IV-A', 'Region III', '50.00', 2, '60.00'],
            ['Region IV-A', 'Region I', '50.00', 5, '75.00'],
        ];

        foreach ($cases as [$origin, $destination, $base, $tier, $expectedFee]) {
            $quote = $service->quote($this->address($origin), $this->address($destination), $base);

            $this->assertSame($expectedFee, $quote['shipping_fee'], "Unexpected quote for {$origin} to {$destination}.");
            $this->assertSame($tier, $quote['snapshot']['additional_region_tiers']);
            $this->assertSame($tier === 0 ? 'same_region' : 'reference_rate', $quote['snapshot']['rate_source']);
        }
    }

    public function test_supported_regions_follow_the_requested_geographic_display_order(): void
    {
        $this->assertSame([
            'Region I (Ilocos Region)',
            'Cordillera Administrative Region (CAR)',
            'Region II (Cagayan Valley)',
            'Region III (Central Luzon)',
            'National Capital Region (NCR)',
            'Region IV-A (CALABARZON)',
            'MIMAROPA Region',
            'Region V (Bicol Region)',
            'Region VI (Western Visayas)',
            'Negros Island Region (NIR)',
            'Region VII (Central Visayas)',
            'Region VIII (Eastern Visayas)',
            'Region IX (Zamboanga Peninsula)',
            'Region X (Northern Mindanao)',
            'Region XIII (Caraga)',
            'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)',
            'Region XII (SOCCSKSARGEN)',
            'Region XI (Davao Region)',
        ], app(RegionShippingRateService::class)->supportedRegions());
    }

    public function test_official_psgc_codes_resolve_without_losing_leading_zeroes(): void
    {
        $codes = [
            'I' => '0100000000', 'CAR' => '1400000000', 'II' => '0200000000',
            'III' => '0300000000', 'NCR' => '1300000000', 'IV-A' => '0400000000',
            'IV-B' => '1700000000', 'V' => '0500000000', 'VI' => '0600000000',
            'NIR' => '1800000000', 'VII' => '0700000000', 'VIII' => '0800000000',
            'IX' => '0900000000', 'X' => '1000000000', 'XIII' => '1600000000',
            'BARMM' => '1900000000', 'XII' => '1200000000', 'XI' => '1100000000',
        ];
        $this->assertSame($codes, array_map(
            static fn (array $region): string => $region['psgc_code'],
            PhilippineRegionCatalog::all(),
        ));

        foreach (PhilippineRegionCatalog::ordered() as $region) {
            $address = (new Address())->forceFill([
                'region_code' => $region['psgc_code'],
                'region_name' => $region['name'],
            ]);
            $quote = app(RegionShippingRateService::class)->quote($address, $address, '50.00');

            $this->assertSame('50.00', $quote['shipping_fee']);
            $this->assertSame(0, $quote['snapshot']['additional_region_tiers']);
        }
    }

    public function test_estimates_all_324_region_combinations_without_a_manual_route_map(): void
    {
        $service = app(RegionShippingRateService::class);
        $regions = $service->supportedRegions();
        $combinationCount = 0;

        $this->assertCount(18, $regions);
        foreach ($regions as $origin) {
            foreach ($regions as $destination) {
                $quote = $service->quote($this->address($origin), $this->address($destination), '50.00');
                $sameRegion = $origin === $destination;
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $quote['shipping_fee']);
                $this->assertGreaterThanOrEqual(0, $quote['snapshot']['additional_region_tiers']);
                $this->assertLessThanOrEqual(20, $quote['snapshot']['additional_region_tiers']);
                $this->assertSame($sameRegion, $quote['snapshot']['rate_source'] === 'same_region');
                if (! $sameRegion) {
                    $this->assertGreaterThan(0, $quote['snapshot']['estimated_distance_km']);
                    $this->assertContains($quote['snapshot']['rate_source'], ['reference_rate', 'distance_estimate']);
                }
                $combinationCount++;
            }
        }

        $this->assertSame(324, $combinationCount);
    }

    public function test_saved_route_overrides_are_directional_and_can_differ_by_direction(): void
    {
        $service = app(RegionShippingRateService::class);
        $routeMap = $service->normalizeTierMap("National Capital Region | CALABARZON | 4\nCALABARZON | National Capital Region | 3");
        $this->assertSame(
            "National Capital Region (NCR) | Region IV-A (CALABARZON) | 4\nRegion IV-A (CALABARZON) | National Capital Region (NCR) | 3",
            $routeMap,
        );
        PlatformSetting::put('shipping_region_tiers', $routeMap, 'string');

        $forward = $service->quote($this->address('NCR'), $this->address('Region IV-A'), '50.00');
        $reverse = $service->quote($this->address('Region IV-A'), $this->address('NCR'), '50.00');

        $this->assertSame('70.00', $forward['shipping_fee']);
        $this->assertSame('65.00', $reverse['shipping_fee']);
        $this->assertSame('route_override', $forward['snapshot']['rate_source']);
        $this->assertSame(4, $forward['snapshot']['additional_region_tiers']);
        $this->assertSame('route_override', $reverse['snapshot']['rate_source']);
        $this->assertSame(3, $reverse['snapshot']['additional_region_tiers']);
    }

    public function test_legacy_barmm_codes_remain_readable_for_saved_addresses(): void
    {
        foreach (['1900000000', '150000000', '1500000000', '15'] as $code) {
            $address = (new Address())->forceFill([
                'region_code' => $code,
                'region_name' => 'Bangsamoro Autonomous Region in Muslim Mindanao (BARMM)',
            ]);
            $quote = app(RegionShippingRateService::class)->quote($address, $address, '50.00');

            $this->assertSame('50.00', $quote['shipping_fee'], "Failed to resolve BARMM code {$code}.");
            $this->assertSame('same_region', $quote['snapshot']['rate_source']);
        }
    }

    public function test_interisland_estimate_adds_a_configurable_tier_surcharge(): void
    {
        $quote = app(RegionShippingRateService::class)->quote(
            $this->address('CALABARZON'),
            $this->address('Central Visayas'),
            '50.00',
        );

        $distanceTiers = (int) ceil($quote['snapshot']['estimated_distance_km'] / $quote['snapshot']['distance_km_per_tier']);
        $this->assertSame(2, $quote['snapshot']['interisland_tier_surcharge']);
        $this->assertGreaterThanOrEqual($distanceTiers + 2, $quote['snapshot']['additional_region_tiers']);
        $this->assertSame('distance_estimate', $quote['snapshot']['rate_source']);
    }

    public function test_changing_the_selected_address_recalculates_the_regional_fee(): void
    {
        $service = app(RegionShippingRateService::class);
        $origin = $this->address('Region IV-A');

        $ncr = $service->quote($origin, $this->address('NCR'), '50.00');
        $ilocos = $service->quote($origin, $this->address('Region I'), '50.00');

        $this->assertSame('55.00', $ncr['shipping_fee']);
        $this->assertSame('75.00', $ilocos['shipping_fee']);
        $this->assertNotSame($ncr['snapshot']['regional_addon_fee'], $ilocos['snapshot']['regional_addon_fee']);
    }

    public function test_estimated_routes_use_the_centrally_configured_fee_and_distance_band(): void
    {
        PlatformSetting::put('additional_region_tier_fee', '7.25', 'decimal');
        PlatformSetting::put('regional_shipping_distance_km_per_tier', '50', 'decimal');
        $quote = app(RegionShippingRateService::class)->quote(
            $this->address('Bicol Region'),
            $this->address('Central Visayas'),
            '50.00',
        );

        $this->assertSame('distance_estimate', $quote['snapshot']['rate_source']);
        $this->assertSame(50.0, $quote['snapshot']['distance_km_per_tier']);
        $this->assertSame('7.25', $quote['snapshot']['additional_region_tier_fee']);
        $this->assertSame(
            number_format(50 + (7.25 * $quote['snapshot']['additional_region_tiers']), 2, '.', ''),
            $quote['shipping_fee'],
        );
    }

    public function test_reference_routes_stay_exact_when_the_general_tier_fee_changes(): void
    {
        PlatformSetting::put('additional_region_tier_fee', '9.00', 'decimal');
        $quote = app(RegionShippingRateService::class)->quote($this->address('CALABARZON'), $this->address('NCR'), '50.00');

        $this->assertSame('55.00', $quote['shipping_fee']);
        $this->assertSame('5.00', $quote['snapshot']['additional_region_tier_fee']);
    }

    public function test_unknown_or_missing_regions_require_valid_saved_addresses(): void
    {
        foreach ([
            [$this->address('CALABARZON'), $this->address('Mars Province')],
            [$this->address(''), $this->address('NCR')],
            [null, $this->address('NCR')],
        ] as [$origin, $destination]) {
            try {
                app(RegionShippingRateService::class)->quote($origin, $destination, '50.00');
                $this->fail('An invalid saved region must not receive a shipping quote.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('address_id', $exception->errors());
            }
        }
    }

    public function test_region_code_and_saved_region_name_must_agree(): void
    {
        $address = $this->address('National Capital Region');
        $address->region_code = '0400000000';

        try {
            app(RegionShippingRateService::class)->quote($address, $this->address('NCR'), '50.00');
            $this->fail('Conflicting saved address region fields must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('address_id', $exception->errors());
        }
    }

    private function address(string $region): Address
    {
        return (new Address())->forceFill(['region_name' => $region]);
    }
}
