<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderOperationalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_pickups_deliveries_history_and_earnings_pages_render_for_an_empty_rider(): void
    {
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'RIDER-PAGES-CENTER',
            'business_name' => 'Rider Pages Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'RIDER-PAGES-PLATE',
            'drivers_license_number' => 'RIDER-PAGES-LICENSE',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($riderUser)
            ->get(route('rider.pickups'))
            ->assertOk()
            ->assertSee('Pickup Assignments')
            ->assertSee('No pickup requests are available.');

        $this->get(route('rider.deliveries'))
            ->assertOk()
            ->assertSee('Delivery Assignments')
            ->assertSee('Scan Waybill')
            ->assertSee('data-parcel-scanner', false)
            ->assertSee('name="tracking"', false)
            ->assertSee('No active delivery assignments found.');

        $this->get(route('rider.history'))
            ->assertOk()
            ->assertSee('Pickup and Delivery History')
            ->assertSee('From date')
            ->assertSee('No rider history found yet.');

        $this->get(route('rider.earnings'))
            ->assertOk()
            ->assertSee('My Earnings')
            ->assertSee("Today's earnings")
            ->assertSee("This week's earnings")
            ->assertSee('No real earnings records are available yet.');
    }
}
