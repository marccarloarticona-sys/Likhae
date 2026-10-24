<?php

namespace Tests\Feature;

use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Fulfillment\ShipmentWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RiderManagementAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_rider_002_reject_requires_and_records_a_reason(): void
    {
        [$owner, $center] = $this->center('Reject Center');
        $application = $this->application($center);

        $this->actingAs($owner)
            ->post(route('logistics.riders.reject', $application), ['reason' => '   '])
            ->assertSessionHasErrors('reason');
        $this->assertSame(RegistrationApplication::STATUS_PENDING, $application->fresh()->status);

        $reason = 'Required rider documents are incomplete.';
        $this->post(route('logistics.riders.reject', $application), ['reason' => $reason])
            ->assertRedirect();

        $this->assertDatabaseHas('registration_applications', [
            'id' => $application->id,
            'status' => RegistrationApplication::STATUS_REJECTED,
            'reviewed_by_user_id' => $owner->id,
            'rejection_reason' => $reason,
        ]);
        $this->assertSame(User::STATUS_PENDING, $application->user->fresh()->status);
    }

    public function test_rider_003_suspension_blocks_new_work_and_preserves_parcel_custody(): void
    {
        [$owner, $center] = $this->center('Suspension Center');
        [$riderUser, $rider] = $this->rider($center, 'ACTIVE', true);

        $uncollected = $this->shipment($center, 'PICKUP_ASSIGNED');
        $uncollectedPickup = $this->assignment($center, $rider, $uncollected, 'PICKUP', 'IN_PROGRESS');
        $collected = $this->shipment($center, 'PICKED_UP');
        $collectedPickup = $this->assignment($center, $rider, $collected, 'PICKUP', 'IN_PROGRESS');
        $uncollectedDelivery = $this->shipment($center, 'ASSIGNED_TO_RIDER');
        $uncollectedDeliveryAssignment = $this->assignment($center, $rider, $uncollectedDelivery, 'DELIVERY', 'ACCEPTED');
        $outForDelivery = $this->shipment($center, 'OUT_FOR_DELIVERY');
        $delivery = $this->assignment($center, $rider, $outForDelivery, 'DELIVERY', 'IN_PROGRESS');

        $this->actingAs($owner)
            ->patch(route('logistics.riders.moderation.update', $rider), [
                'status' => 'SUSPENDED',
                'reason' => 'Safety review requires temporary suspension.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'SUSPENDED', 'is_online' => false]);
        $this->assertDatabaseHas('users', ['id' => $riderUser->id, 'status' => User::STATUS_SUSPENDED]);
        $this->assertSame('CANCELLED', $uncollectedPickup->fresh()->status);
        $this->assertNotNull($uncollectedPickup->fresh()->cancelled_at);
        $this->assertSame('READY_FOR_PICKUP', $uncollected->fresh()->current_status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $uncollected->id,
            'status' => 'READY_FOR_PICKUP',
            'actor_user_id' => $owner->id,
        ]);

        // Keep work with the rider once physical custody has begun; do not silently return it to a queue.
        $this->assertSame('IN_PROGRESS', $collectedPickup->fresh()->status);
        $this->assertSame('PICKED_UP', $collected->fresh()->current_status);
        $this->assertSame('IN_PROGRESS', $delivery->fresh()->status);
        $this->assertSame('OUT_FOR_DELIVERY', $outForDelivery->fresh()->current_status);
        $this->assertSame('CANCELLED', $uncollectedDeliveryAssignment->fresh()->status);
        $this->assertSame('SORTED', $uncollectedDelivery->fresh()->current_status);
        $this->assertDatabaseHas('shipment_events', [
            'shipment_id' => $uncollectedDelivery->id,
            'status' => 'SORTED',
            'actor_user_id' => $owner->id,
        ]);

        $newWork = $this->shipment($center, 'SORTED');
        try {
            app(ShipmentWorkflowService::class)->assignRider($newWork, $rider, 'DELIVERY', $owner);
            $this->fail('A suspended rider must not receive new work.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Only active riders can receive assignments.', $exception->getMessage());
        }
        $this->assertDatabaseMissing('rider_assignments', [
            'shipment_id' => $newWork->id,
            'rider_profile_id' => $rider->id,
            'status' => 'ASSIGNED',
        ]);
    }

    public function test_rider_004_suspension_without_a_reason_is_rejected(): void
    {
        [$owner, $center] = $this->center('Required Reason Center');
        [$riderUser, $rider] = $this->rider($center, 'ACTIVE', true);

        $this->actingAs($owner)
            ->patch(route('logistics.riders.moderation.update', $rider), ['status' => 'SUSPENDED'])
            ->assertSessionHasErrors('reason');
        $this->patch(route('logistics.riders.moderation.update', $rider), [
            'status' => 'SUSPENDED',
            'reason' => '   ',
        ])->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'ACTIVE', 'is_online' => true]);
        $this->assertDatabaseHas('users', ['id' => $riderUser->id, 'status' => User::STATUS_ACTIVE]);
    }

    public function test_rider_005_logistics_reactivation_restores_rider_availability(): void
    {
        [$owner, $center] = $this->center('Reactivation Center');
        [$riderUser, $rider] = $this->rider($center, 'SUSPENDED', false);

        $this->actingAs($owner)
            ->patch(route('logistics.riders.moderation.update', $rider), ['status' => 'ACTIVE'])
            ->assertRedirect();

        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'ACTIVE', 'is_online' => false]);
        $this->assertDatabaseHas('users', ['id' => $riderUser->id, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($riderUser->fresh())
            ->patch(route('rider.availability.update'), ['is_online' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('rider_profiles', ['id' => $rider->id, 'status' => 'ACTIVE', 'is_online' => true]);
    }

    public function test_rider_006_management_page_shows_live_availability_and_real_active_workload(): void
    {
        [$owner, $center] = $this->center('Workload Center');
        [$riderUser, $rider] = $this->rider($center, 'ACTIVE', true);

        $this->assignment($center, $rider, $this->shipment($center, 'PICKUP_ASSIGNED'), 'PICKUP', 'ASSIGNED');
        $this->assignment($center, $rider, $this->shipment($center, 'OUT_FOR_DELIVERY'), 'DELIVERY', 'IN_PROGRESS');
        $this->assignment($center, $rider, $this->shipment($center, 'DELIVERED'), 'DELIVERY', 'COMPLETED');

        $response = $this->actingAs($owner)->get(route('logistics.riders'))->assertOk();
        $response->assertSee($riderUser->email)
            ->assertSee('Online and available')
            ->assertSee('2 active parcel assignments')
            ->assertSee('8 parcel capacity remaining')
            ->assertSee('1 deliveries completed today');

        $listedRider = $response->viewData('riders')->getCollection()->firstWhere('id', $rider->id);
        $this->assertNotNull($listedRider);
        $this->assertTrue($listedRider->is_online);
        $this->assertSame(2, (int) $listedRider->active_assignments_count);
    }

    public function test_rider_007_another_logistics_center_cannot_review_or_suspend_jnt_riders(): void
    {
        [$jntOwner, $jnt] = $this->center('JNT');
        [$pagsanjanOwner] = $this->center('Pagsanjan');
        [$jntRiderUser, $jntRider] = $this->rider($jnt, 'ACTIVE', true);
        $application = $this->application($jnt);

        $this->actingAs($pagsanjanOwner)
            ->post(route('logistics.riders.approve', $application))
            ->assertNotFound();
        $this->post(route('logistics.riders.reject', $application), [
            'rejection_reason' => 'Not the assigned logistics center.',
        ])->assertNotFound();
        $this->patch(route('logistics.riders.moderation.update', $jntRider), [
            'status' => 'SUSPENDED',
            'reason' => 'Cross-company action must not be allowed.',
        ])->assertForbidden();
        $this->get(route('logistics.riders'))->assertOk()->assertDontSee($jntRiderUser->email);

        $this->assertSame(RegistrationApplication::STATUS_PENDING, $application->fresh()->status);
        $this->assertDatabaseHas('rider_profiles', ['id' => $jntRider->id, 'status' => 'ACTIVE', 'is_online' => true]);
        $this->assertDatabaseHas('users', ['id' => $jntRiderUser->id, 'status' => User::STATUS_ACTIVE]);
        $this->assertSame((int) $jntOwner->id, (int) $jnt->fresh()->owner_user_id);
    }

    public function test_rider_logout_goes_offline_and_preserves_an_active_assignment_and_custody(): void
    {
        [$owner, $center] = $this->center('Logout Availability Center');
        [$riderUser, $rider] = $this->rider($center, 'ACTIVE', true);
        $shipment = $this->shipment($center, 'OUT_FOR_DELIVERY');
        $assignment = $this->assignment($center, $rider, $shipment, 'DELIVERY', 'IN_PROGRESS');
        $rider->update([
            'availability_latitude' => '14.2000000',
            'availability_longitude' => '121.5000000',
            'availability_location_updated_at' => now(),
        ]);

        $this->actingAs($riderUser)
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertDatabaseHas('rider_profiles', [
            'id' => $rider->id,
            'is_online' => false,
            'availability_latitude' => null,
            'availability_longitude' => null,
            'availability_location_updated_at' => null,
        ]);
        $this->assertDatabaseHas('rider_assignments', [
            'id' => $assignment->id,
            'rider_profile_id' => $rider->id,
            'status' => 'IN_PROGRESS',
        ]);
        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'current_status' => 'OUT_FOR_DELIVERY',
        ]);
    }

    /** @return array{User, LogisticsCenter} */
    private function center(string $name): array
    {
        $owner = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => strtoupper(str_replace(' ', '-', $name)).'-'.str()->random(8),
            'business_name' => $name,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);

        return [$owner, $center];
    }

    /** @return array{User, RiderProfile} */
    private function rider(LogisticsCenter $center, string $status, bool $online): array
    {
        $suffix = str()->random(10);
        $user = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => $status === 'SUSPENDED' ? User::STATUS_SUSPENDED : User::STATUS_ACTIVE,
        ]);
        $profile = RiderProfile::create([
            'user_id' => $user->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'approved_parcel_capacity' => 10,
            'plate_number' => 'RIDER-'.$suffix,
            'drivers_license_number' => 'LICENSE-'.$suffix,
            'status' => $status,
            'is_online' => $online,
        ]);

        return [$user, $profile];
    }

    private function application(LogisticsCenter $center): RegistrationApplication
    {
        $user = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_PENDING,
        ]);
        $application = RegistrationApplication::create([
            'application_number' => 'RIDER-APP-'.str()->upper(str()->random(12)),
            'user_id' => $user->id,
            'status' => RegistrationApplication::STATUS_PENDING,
            'submitted_at' => now(),
        ]);
        RiderApplicationData::create([
            'registration_application_id' => $application->id,
            'target_logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'APPLICANT-'.str()->random(8),
            'drivers_license_number' => 'LICENSE-'.str()->random(8),
        ]);

        return $application;
    }

    private function shipment(LogisticsCenter $center, string $status): Shipment
    {
        return Shipment::create([
            'seller_order_id' => $this->sellerOrder()->id,
            'tracking_number' => 'RIDER-TRACK-'.str()->upper(str()->random(12)),
            'logistics_center_id' => $center->id,
            'destination_province_code' => '0403400000',
            'destination_province_name' => 'Laguna',
            'destination_municipality_code' => '0403419000',
            'destination_municipality_name' => 'Pagsanjan',
            'destination_barangay_code' => '0403419001',
            'destination_barangay_name' => 'Anibong',
            'current_status' => $status,
        ]);
    }

    private function assignment(
        LogisticsCenter $center,
        RiderProfile $rider,
        Shipment $shipment,
        string $type,
        string $status,
    ): RiderAssignment {
        return RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $rider->id,
            'assignment_type' => $type,
            'status' => $status,
            'assigned_by_user_id' => $center->owner_user_id,
            'assigned_at' => now(),
            'started_at' => $status === 'IN_PROGRESS' ? now() : null,
            'completed_at' => $status === 'COMPLETED' ? now() : null,
        ]);
    }

    private function sellerOrder(): SellerOrder
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Rider acceptance '.str()->random(6),
            'slug' => 'rider-acceptance-'.str()->lower(str()->random(10)),
            'is_active' => true,
        ]);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Rider Acceptance Seller',
            'status' => 'ACTIVE',
        ]);
        $order = Order::create([
            'order_number' => 'RIDER-ORDER-'.str()->upper(str()->random(10)),
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
        ]);

        return SellerOrder::create([
            'seller_order_number' => 'RIDER-SO-'.str()->upper(str()->random(10)),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'READY_FOR_PICKUP',
        ]);
    }
}
