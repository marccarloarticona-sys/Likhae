<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PhilippineAddressController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhilippineAddressApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.psgc.token' => 'fake-token',
            'services.psgc.version' => 'Q2_2024',
            'services.psgc.local_first' => false,
        ]);
    }

    public function test_regions_are_loaded_from_the_psa_psgc_api(): void
    {
        Http::fake([
            'classification.psa.gov.ph/psgc/Q2_2024/regions*' => Http::response([
                'results' => [
                    [
                        'psgc_code' => '0300000000',
                        'area_name' => 'Region III (Central Luzon)',
                        'geographic_level' => 'Reg',
                        'reg' => 3,
                        'prv' => 0,
                        'mun' => 0,
                    ],
                    [
                        'psgc_code' => '1300000000',
                        'area_name' => 'National Capital Region (NCR)',
                        'geographic_level' => 'Reg',
                        'reg' => 13,
                        'prv' => 0,
                        'mun' => 0,
                    ],
                    [
                        'psgc_code' => '0100000000',
                        'area_name' => 'Region I (Ilocos Region)',
                        'geographic_level' => 'Reg',
                        'reg' => 1,
                        'prv' => 0,
                        'mun' => 0,
                    ],
                    [
                        'psgc_code' => '1400000000',
                        'area_name' => 'Cordillera Administrative Region (CAR)',
                        'geographic_level' => 'Reg',
                        'reg' => 14,
                        'prv' => 0,
                        'mun' => 0,
                    ],
                ],
            ]),
        ]);

        $regions = $this->getJson('/address/philippines/regions')->assertOk()->json();
        $this->assertSame(
            ['0100000000', '1400000000', '0300000000', '1300000000'],
            array_column($regions, 'code'),
        );
        $this->assertSame('Region I (Ilocos Region)', $regions[0]['name']);
        $this->assertSame('Cordillera Administrative Region (CAR)', $regions[1]['name']);
        $this->assertIsString($regions[0]['code']);

        Http::assertSent(fn ($request) => $request->url() === 'https://classification.psa.gov.ph/psgc/Q2_2024/regions?token=fake-token&page_size=1000');
    }

    public function test_child_address_requests_pass_psgc_parent_filters(): void
    {
        Http::fake([
            'classification.psa.gov.ph/psgc/Q2_2024/provinces*' => Http::response([
                'results' => [
                    [
                        'psgc_code' => '0403400000',
                        'area_name' => 'Laguna',
                        'geographic_level' => 'Prov',
                        'reg' => 4,
                        'prv' => 34,
                        'mun' => 0,
                    ],
                ],
            ]),
            'classification.psa.gov.ph/psgc/Q2_2024/municipalities*' => Http::response([
                'results' => [
                    [
                        'psgc_code' => '0403404000',
                        'area_name' => 'Bay',
                        'geographic_level' => 'Mun',
                        'reg' => 4,
                        'prv' => 34,
                        'mun' => 4,
                    ],
                ],
            ]),
            'classification.psa.gov.ph/psgc/Q2_2024/barangays*' => Http::response([
                'results' => [
                    [
                        'psgc_code' => '0403404001',
                        'area_name' => 'Bitin',
                        'geographic_level' => 'Bgy',
                        'reg' => 4,
                        'prv' => 34,
                        'mun' => 4,
                    ],
                ],
            ]),
        ]);

        $this->getJson('/address/philippines/regions/0400000000/provinces')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0403400000',
                'name' => 'Laguna',
            ]);

        $this->getJson('/address/philippines/provinces/0403400000/municipalities')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0403404000',
                'name' => 'Bay',
            ]);

        $this->getJson('/address/philippines/municipalities/0403404000/barangays?prv=34&mun=4')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0403404001',
                'name' => 'Bitin',
            ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'provinces') && str_contains($request->url(), 'reg=04'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'municipalities') && str_contains($request->url(), 'prv=34'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'barangays') && str_contains($request->url(), 'prv=34') && str_contains($request->url(), 'mun=4'));
    }

    public function test_quezon_municipalities_include_lucena_city(): void
    {
        Http::fake([
            'classification.psa.gov.ph/psgc/Q2_2024/municipalities*' => Http::response([
                'results' => [],
            ]),
        ]);

        $this->getJson('/address/philippines/provinces/0405600000/municipalities?prv=56')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0405643000',
                'name' => 'City of Lucena',
                'prv' => 56,
                'mun' => 43,
            ]);
    }

    public function test_missing_psgc_token_uses_fallback_instead_of_503(): void
    {
        config(['services.psgc.token' => null]);

        Http::fake([
            'psgc.cloud/api/v2/regions' => Http::response([
                'data' => [
                    ['code' => '0400000000', 'name' => 'Region IV-A (CALABARZON)', 'type' => 'Reg'],
                ],
            ]),
        ]);

        $this->getJson('/address/philippines/regions')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0400000000',
                'name' => 'Region IV-A (CALABARZON)',
            ]);
    }

    public function test_local_address_data_is_used_when_both_remote_providers_are_unavailable(): void
    {
        Http::fake(fn () => Http::response(['message' => 'Service unavailable'], 503));

        $this->getJson('/address/philippines/regions')
            ->assertOk()
            ->assertJsonFragment([
                'code' => '0400000000',
                'name' => 'Region IV-A (CALABARZON)',
            ]);
    }

    public function test_production_local_first_mode_does_not_require_an_http_provider(): void
    {
        config(['services.psgc.local_first' => true]);
        Http::preventStrayRequests();

        $this->getJson('/address/philippines/regions')
            ->assertOk()
            ->assertJsonFragment(['code' => '0400000000']);
        $this->getJson('/address/philippines/regions/0400000000/provinces')
            ->assertOk()
            ->assertJsonFragment(['code' => '0403400000', 'name' => 'Laguna']);
        $municipalities = $this->getJson('/address/philippines/provinces/0403400000/municipalities')
            ->assertOk()
            ->json();
        $this->assertContains(
            ['code' => '0403424000', 'name' => 'City of San Pablo', 'prv' => 34, 'mun' => 24, 'level' => 'City'],
            $municipalities
        );
        $this->getJson('/address/philippines/municipalities/0403424000/barangays')
            ->assertOk()
            ->assertJsonFragment(['code' => '0403424001', 'name' => 'Bagong Bayan II-A']);

        Http::assertNothingSent();
    }

    public function test_region_list_uses_geographic_display_order_and_current_psgc_codes(): void
    {
        config(['services.psgc.local_first' => true]);
        Http::preventStrayRequests();

        $regions = $this->getJson('/address/philippines/regions')->assertOk()->json();

        $this->assertSame([
            '0100000000', '1400000000', '0200000000', '0300000000', '1300000000', '0400000000',
            '1700000000', '0500000000', '0600000000', '1800000000', '0700000000', '0800000000',
            '0900000000', '1000000000', '1600000000', '1900000000', '1200000000', '1100000000',
        ], array_column($regions, 'code'));
        $this->assertSame('National Capital Region (NCR)', $regions[4]['name']);
        $this->assertSame('Cordillera Administrative Region (CAR)', $regions[1]['name']);
        $this->assertSame('Region IV-A (CALABARZON)', $regions[5]['name']);
        $this->assertSame('Region XIII (Caraga)', $regions[14]['name']);
        $this->assertSame('Region XI (Davao Region)', $regions[17]['name']);
        $this->assertSame('1900000000', $regions[15]['code']);
        $this->assertIsString($regions[0]['code']);

        Http::assertNothingSent();
    }

    public function test_postal_code_is_resolved_from_selected_location(): void
    {
        $this->getJson('/address/philippines/postal-code?province=0403400000&municipality=0403424000&province_name=Laguna&municipality_name=City%20of%20San%20Pablo')
            ->assertOk()
            ->assertJson([
                'postal_code' => '4000',
            ]);
    }

    public function test_ncr_region_parent_loads_cities_and_pateros_without_submunicipalities(): void
    {
        config(['services.psgc.local_first' => true]);
        Http::preventStrayRequests();

        $regionParent = $this->getJson('/address/philippines/regions/1300000000/provinces')
            ->assertOk()
            ->assertJsonCount(1)
            ->json('0');

        $this->assertSame('1300000000', $regionParent['code']);
        $this->assertSame('Region', $regionParent['level']);

        $municipalities = $this->getJson('/address/philippines/provinces/1300000000/municipalities')
            ->assertOk()
            ->json();

        $this->assertCount(17, $municipalities);
        $names = array_column($municipalities, 'name');
        $this->assertContains('City of Manila', $names);
        $this->assertContains('Pateros', $names);
        $this->assertNotContains('Binondo', $names);
        $this->assertEqualsCanonicalizing(['City', 'Mun'], array_values(array_unique(array_column($municipalities, 'level'))));

        Http::assertNothingSent();
    }

    public function test_independent_cities_load_barangays_and_validate_as_their_own_municipality(): void
    {
        config(['services.psgc.local_first' => true]);
        Http::preventStrayRequests();

        $regions = $this->getJson('/address/philippines/regions')->assertOk()->json();
        $cases = [
            [
                'region_code' => '1700000000',
                'city_code' => '1731500000',
                'city_name' => 'City of Puerto Princesa',
                'barangay_code' => '1731500001',
                'barangay_name' => 'Babuyan',
                'barangay_count' => 66,
            ],
            [
                'region_code' => '1800000000',
                'city_code' => '1830200000',
                'city_name' => 'City of Bacolod',
                'barangay_code' => '1830200001',
                'barangay_name' => 'Alangilan',
                'barangay_count' => 61,
            ],
        ];

        foreach ($cases as $case) {
            $region = collect($regions)->firstWhere('code', $case['region_code']);
            $this->assertNotNull($region);

            $this->getJson('/address/philippines/regions/'.$case['region_code'].'/provinces')
                ->assertOk()
                ->assertJsonFragment([
                    'code' => $case['city_code'],
                    'name' => $case['city_name'],
                    'level' => 'City',
                ]);

            $barangays = $this->getJson('/address/philippines/municipalities/'.$case['city_code'].'/barangays')
                ->assertOk()
                ->json();

            $this->assertCount($case['barangay_count'], $barangays);
            $this->assertContains($case['barangay_name'], array_column($barangays, 'name'));
            $this->assertTrue(PhilippineAddressController::selectionIsValid(
                $case['region_code'],
                (string) $region['name'],
                $case['city_code'],
                $case['city_name'],
                $case['city_code'],
                $case['city_name'],
                $case['barangay_code'],
                $case['barangay_name'],
            ));

            $canonical = PhilippineAddressController::canonicalMunicipalityFor(
                $case['city_code'],
                $case['city_name'],
                $case['city_code'],
                $case['city_name'],
            );

            $this->assertSame($case['region_code'], $canonical['region_code'] ?? null);
            $this->assertSame($case['city_code'], $canonical['province_code'] ?? null);
            $this->assertSame($case['city_code'], $canonical['municipality_code'] ?? null);
        }

        Http::assertNothingSent();
    }
}
