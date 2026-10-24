<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RiderAvailabilityAndModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_active_rider_can_go_online_and_offline(): void
    {
        [$owner, $riderUser, $rider] = $this->riderFixture();

        $this->actingAs($riderUser)
            ->patch(route('rider.availability.update'), ['is_online' => 1])
            ->assertRedirect();
        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'is_online' => true]);

        $this->patch(route('rider.availability.update'), ['is_online' => 0])->assertRedirect();
        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'is_online' => false]);
    }

    public function test_availability_is_visible_on_mobile_and_reflects_server_state(): void
    {
        [, $riderUser] = $this->riderFixture();

        $response = $this->actingAs($riderUser)->get(route('rider.dashboard'))->assertOk();

        $response->assertSee('RIDER STATUS')
            ->assertSee('OFFLINE')
            ->assertSee('Not accepting new jobs');
        $this->assertSame(2, substr_count($response->getContent(), 'name="is_online" value='));
    }

    public function test_suspension_requires_ten_reason_characters_and_reactivation_keeps_rider_offline(): void
    {
        [$owner, , $rider] = $this->riderFixture();
        $rider->update(['is_online' => true]);

        $this->actingAs($owner)
            ->patch(route('logistics.riders.moderation.update', $rider), [
                'status' => 'SUSPENDED',
                'reason' => '123456789',
            ])
            ->assertSessionHasErrors('reason');

        $this->patch(route('logistics.riders.moderation.update', $rider), [
            'status' => 'SUSPENDED',
            'reason' => '   Rider safety issue   ',
        ])->assertRedirect();
        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'SUSPENDED', 'is_online' => false]);

        $this->patch(route('logistics.riders.moderation.update', $rider), ['status' => 'ACTIVE'])
            ->assertRedirect();
        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'ACTIVE', 'is_online' => false]);
    }

    public function test_logistics_rider_list_shows_availability_and_workload_fields(): void
    {
        [$owner] = $this->riderFixture();

        $this->actingAs($owner)
            ->get(route('logistics.riders'))
            ->assertOk()
            ->assertSee('Offline')
            ->assertSee('0 active parcel assignments')
            ->assertSee('10 parcel capacity remaining')
            ->assertSee('0 deliveries completed today');
    }

    /** @return array{User, User, RiderProfile} */
    private function riderFixture(): array
    {
        $suffix = str()->random(12);
        $owner = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'RIDER-AVAIL-'.$suffix,
            'business_name' => 'Rider Availability Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
        $riderUser = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $rider = RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 10,
            'plate_number' => 'AVAIL-'.$suffix,
            'drivers_license_number' => 'LICENSE-'.$suffix,
            'status' => 'ACTIVE',
            'is_online' => false,
        ]);

        return [$owner, $riderUser, $rider];
    }
}
