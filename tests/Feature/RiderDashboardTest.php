<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_rider_dashboard_renders_stats_and_empty_recent_parcels(): void
    {
        $this->withoutVite();

        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'RIDER-DASHBOARD-CENTER',
            'business_name' => 'Rider Dashboard Center',
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
            'plate_number' => 'RIDER-DASH-001',
            'drivers_license_number' => 'RIDER-DASH-LICENSE-001',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($riderUser)
            ->get(route('rider.dashboard'))
            ->assertOk()
            ->assertSee('Pending pickups')
            ->assertSee('Pending deliveries')
            ->assertSee('Parcel Scanner')
            ->assertSee('data-parcel-scanner', false)
            ->assertSee('data-scanner-start', false)
            ->assertSee('Earnings')
            ->assertSee('No rider parcels found yet.');

        $this->actingAs($riderUser)
            ->get(route('rider.scan', [
                'return_to' => 'dashboard',
                'tracking' => 'UNASSIGNED-TRACKING-TEST',
                'scan_method' => 'MANUAL',
            ]))
            ->assertRedirect(route('rider.dashboard'))
            ->assertSessionHasErrors(['tracking' => 'Tracking number does not match an active job assigned to your account.']);
    }
}
