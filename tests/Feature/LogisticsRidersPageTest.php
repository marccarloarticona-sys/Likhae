<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Buyer\Address;
use App\Models\Logistics\ServiceArea;
use App\Models\Logistics\ServiceAreaLocation;
use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticsRidersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_riders_page_lists_all_account_statuses_for_its_center_across_pages(): void
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'RIDERS-PAGE-CENTER',
            'business_name' => 'Riders Page Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);

        $first = null;
        $last = null;
        foreach (range(1, 16) as $number) {
            $status = match ($number % 3) {
                0 => 'PENDING',
                1 => 'ACTIVE',
                default => 'SUSPENDED',
            };
            $user = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => $status]);
            RiderProfile::create([
                'user_id' => $user->id,
                'logistics_center_id' => $center->id,
                'vehicle_type' => 'motorcycle',
                'plate_number' => 'PAGE-PLATE-'.$number,
                'drivers_license_number' => 'PAGE-LICENSE-'.$number,
                'status' => $status,
            ]);
            $first ??= $user;
            $last = $user;
        }

        $otherOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $otherCenter = LogisticsCenter::create([
            'owner_user_id' => $otherOwner->id,
            'code' => 'OTHER-RIDERS-CENTER',
            'business_name' => 'Other Riders Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $otherOwner->id,
            'approved_at' => now(),
        ]);
        $otherRider = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        RiderProfile::create([
            'user_id' => $otherRider->id,
            'logistics_center_id' => $otherCenter->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'OTHER-PAGE-PLATE',
            'drivers_license_number' => 'OTHER-PAGE-LICENSE',
            'status' => 'ACTIVE',
        ]);

        $this->actingAs($owner)
            ->get(route('logistics.riders'))
            ->assertOk()
            ->assertSee($last->email)
            ->assertDontSee($first->email)
            ->assertDontSee($otherRider->email)
            ->assertSee('16');

        $this->get(route('logistics.riders', ['page' => 2]))
            ->assertOk()
            ->assertSee($first->email);
    }

    public function test_riders_page_labels_local_delivery_and_four_wheel_inter_logistics_roles(): void
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS, 'status' => User::STATUS_ACTIVE]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'RIDER-CLASS-CENTER',
            'business_name' => 'Rider Classification Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);

        foreach ([
            ['first_name' => 'Local', 'last_name' => 'Delivery Rider', 'vehicle_type' => 'motorcycle', 'plate' => 'CLASS-MOTO-001'],
            ['first_name' => 'Inter', 'last_name' => 'Logistics Driver', 'vehicle_type' => 'closed_van', 'plate' => 'CLASS-VAN-001'],
        ] as $entry) {
            $user = User::factory()->create([
                'first_name' => $entry['first_name'],
                'last_name' => $entry['last_name'],
                'account_type' => User::TYPE_RIDER,
                'status' => User::STATUS_ACTIVE,
            ]);
            RiderProfile::create([
                'user_id' => $user->id,
                'logistics_center_id' => $center->id,
                'vehicle_type' => $entry['vehicle_type'],
                'plate_number' => $entry['plate'],
                'drivers_license_number' => $entry['plate'].'-LICENSE',
                'status' => 'ACTIVE',
            ]);
        }

        $this->actingAs($owner)
            ->get(route('logistics.riders'))
            ->assertOk()
            ->assertSee('Local Delivery')
            ->assertSee('4-Wheel Inter-Logistics Drivers')
            ->assertSee('4-Wheel Inter-Logistics Driver')
            ->assertSee('Closed Van');
    }

    public function test_rider_detail_renders_and_reassigns_delivery_area(): void
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $centerAddress = Address::create([
            'user_id' => $owner->id,
            'label' => 'Logistics Center',
            'recipient_name' => $owner->name,
            'contact_number' => $owner->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'street_address' => 'Rider Detail Center Road',
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'address_id' => $centerAddress->id,
            'code' => 'RIDER-DETAIL-CENTER',
            'business_name' => 'Rider Detail Center',
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
            'plate_number' => 'DETAIL-PLATE-001',
            'drivers_license_number' => 'DETAIL-LICENSE-001',
            'status' => 'ACTIVE',
        ]);
        $area = ServiceArea::create([
            'logistics_center_id' => $center->id,
            'code' => 'DETAIL-AREA',
            'name' => 'Detail Test Area',
            'is_active' => true,
        ]);
        ServiceAreaLocation::create([
            'service_area_id' => $area->id,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
        ]);

        $this->actingAs($owner)
            ->get(route('logistics.riders.show', $rider))
            ->assertOk()
            ->assertSee('Rider ID: RID-'.str_pad((string) $rider->id, 4, '0', STR_PAD_LEFT))
            ->assertSee('Delivery Area Assignment')
            ->assertSee('Detail Test Area');

        $this->patch(route('logistics.riders.area.update', $rider), [
            'service_area_id' => $area->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('rider_area_assignments', [
            'rider_profile_id' => $rider->id,
            'service_area_id' => $area->id,
            'is_active' => true,
        ]);

        $this->assertSame(1, RiderAreaAssignment::query()
            ->where('rider_profile_id', $rider->id)
            ->where('is_active', true)
            ->count());
    }
}
