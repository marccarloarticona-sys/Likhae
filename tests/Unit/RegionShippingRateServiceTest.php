<?php

namespace Tests\Unit;

use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\Address;
use App\Services\Finance\RegionShippingRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
            $this->address('Central Luzon'),
            '50.00',
        );

        $this->assertSame('50.00', $quote['shipping_fee']);
        $this->assertSame(0, $quote['snapshot']['additional_region_tiers']);
    }

    public function test_named_cross_region_pair_adds_configured_tiers_without_numeric_inference(): void
    {
        PlatformSetting::put('additional_region_tier_fee', '6.00', 'decimal');
        PlatformSetting::put('shipping_region_tiers', "National Capital Region | Central Luzon | 2", 'string');

        $quote = app(RegionShippingRateService::class)->quote(
            $this->address('National Capital Region'),
            $this->address('Central Luzon'),
            '50.00',
        );

        $this->assertSame('62.00', $quote['shipping_fee']);
        $this->assertSame(2, $quote['snapshot']['additional_region_tiers']);
        $this->assertSame('Central Luzon', $quote['snapshot']['destination_region']);
    }

    public function test_cross_region_route_without_a_configured_pair_fails_closed(): void
    {
        try {
            app(RegionShippingRateService::class)->quote(
                $this->address('Bicol Region'),
                $this->address('Central Visayas'),
                '50.00',
            );
            $this->fail('A missing route tier must not be inferred.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipping_rate', $exception->errors());
        }
    }

    private function address(string $region): Address
    {
        return (new Address())->forceFill(['region_name' => $region]);
    }
}
