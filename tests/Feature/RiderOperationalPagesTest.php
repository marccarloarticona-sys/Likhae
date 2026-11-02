<?php

namespace Tests\Feature;

use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Buyer\Order;
use App\Models\Rider\RiderAssignment;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('Seller Pickup Assignments')
            ->assertSee('No seller pickup assignments are waiting.');

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

        $this->get(route('rider.profile'))
            ->assertOk()
            ->assertSee('Personal Information')
            ->assertSee('Vehicle Information')
            ->assertSee('Required Documents')
            ->assertSee('name="contact_number"', false)
            ->assertSee('Verified name and sign-in details are protected.');

        $this->get(route('rider.earnings'))
            ->assertOk()
            ->assertSee('My Earnings')
            ->assertSee("Today's earnings", false)
            ->assertSee("This week's earnings", false)
            ->assertSee('No real earnings records are available yet.');
    }

    public function test_rider_history_includes_only_return_workflows_the_rider_handled(): void
    {
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'RIDER-RETURN-HISTORY-CENTER',
            'business_name' => 'Pagsanjan Logistics',
            'status' => 'ACTIVE',
        ]);

        $originalRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $originalRider = RiderProfile::create([
            'user_id' => $originalRiderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'RETURN-HISTORY-ORIGINAL',
            'drivers_license_number' => 'RETURN-HISTORY-ORIGINAL-LICENSE',
            'status' => 'ACTIVE',
        ]);
        $returnRiderUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        $returnRider = RiderProfile::create([
            'user_id' => $returnRiderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'RETURN-HISTORY-RETURN',
            'drivers_license_number' => 'RETURN-HISTORY-RETURN-LICENSE',
            'status' => 'ACTIVE',
        ]);

        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create(['name' => 'Electronics', 'slug' => 'return-history-electronics']);
        $seller = SellerProfile::create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Pagsanjan Electronics & Gadgets',
            'status' => 'ACTIVE',
        ]);
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $order = Order::create([
            'order_number' => 'SO-RETURN-HISTORY-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
        ]);
        $sellerOrder = SellerOrder::create([
            'seller_order_number' => 'SO-20261027-WFMNKWFH',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'CONFIRMED',
        ]);
        $shipment = Shipment::create([
            'seller_order_id' => $sellerOrder->id,
            'tracking_number' => 'TRK-20261027-BRMP5RIU',
            'logistics_center_id' => $center->id,
            'destination_province_code' => 'PAGSANJAN-PROVINCE',
            'destination_province_name' => 'Laguna',
            'destination_municipality_code' => 'PAGSANJAN-MUNICIPALITY',
            'destination_municipality_name' => 'Pagsanjan',
            'destination_barangay_code' => 'PAGSANJAN-BARANGAY',
            'destination_barangay_name' => 'Barangay Uno',
            'current_status' => 'DELIVERY_FAILED',
        ]);
        $assignment = RiderAssignment::create([
            'shipment_id' => $shipment->id,
            'rider_profile_id' => $originalRider->id,
            'assignment_type' => RiderAssignment::TYPE_DELIVERY,
            'status' => 'COMPLETED',
            'completed_at' => now()->subDays(2),
        ]);
        ShipmentReturnWorkflow::create([
            'shipment_id' => $shipment->id,
            'logistics_center_id' => $center->id,
            'original_delivery_assignment_id' => $assignment->id,
            'return_rider_profile_id' => $returnRider->id,
            'status' => ShipmentReturnWorkflow::AWAITING_CENTER_RECEIPT,
            'return_disposition' => ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN,
            'reason' => 'Buyer refused parcel: Attempt',
            'initiated_by_user_id' => $originalRiderUser->id,
            'initiated_at' => now()->subDays(2),
            'center_handoff_by_user_id' => $originalRiderUser->id,
            'center_handoff_at' => now()->subDay(),
        ]);

        $this->actingAs($originalRiderUser)
            ->get(route('rider.history'))
            ->assertOk()
            ->assertSee('Return custody history')
            ->assertSee('TRK-20261027-BRMP5RIU')
            ->assertSee('Buyer refused parcel: Attempt')
            ->assertSee('Original delivery rider')
            ->assertSee('Original rider handed parcel to center')
            ->assertSee('Waiting for the center to confirm parcel receipt.');

        $this->actingAs($returnRiderUser)
            ->get(route('rider.history'))
            ->assertOk()
            ->assertSee('TRK-20261027-BRMP5RIU')
            ->assertSee('Your role: Return rider');
    }

    public function test_rider_history_renders_when_return_workflow_schema_is_unavailable(): void
    {
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $centerOwner->id,
            'code' => 'RIDER-RETURN-HISTORY-LEGACY',
            'business_name' => 'Legacy History Center',
            'status' => 'ACTIVE',
        ]);
        $riderUser = User::factory()->create(['account_type' => User::TYPE_RIDER, 'status' => User::STATUS_ACTIVE]);
        RiderProfile::create([
            'user_id' => $riderUser->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'RETURN-HISTORY-LEGACY',
            'drivers_license_number' => 'RETURN-HISTORY-LEGACY-LICENSE',
            'status' => 'ACTIVE',
        ]);

        Schema::dropIfExists('shipment_return_workflows');

        $this->actingAs($riderUser)
            ->get(route('rider.history'))
            ->assertOk()
            ->assertSee('Pickup and Delivery History')
            ->assertSee('No return custody history found for your rider account.');
    }

    public function test_rider_profile_update_cannot_overwrite_approved_name_or_sign_in_email(): void
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'RIDER-IDENTITY-'.str()->upper(str()->random(8)),
            'business_name' => 'Rider Identity Center',
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
            'plate_number' => 'RIDER-IDENTITY-PLATE',
            'drivers_license_number' => 'RIDER-IDENTITY-LICENSE',
            'status' => 'ACTIVE',
        ]);
        $newContact = '09'.str()->random(9);

        $this->actingAs($riderUser)
            ->patch(route('rider.account.profile.update'), [
                'first_name' => 'Changed',
                'last_name' => 'Name',
                'email' => 'changed-rider@example.test',
                'contact_number' => $newContact,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $riderUser->refresh();
        $this->assertNotSame('Changed', $riderUser->first_name);
        $this->assertNotSame('Name', $riderUser->last_name);
        $this->assertNotSame('changed-rider@example.test', $riderUser->email);
        $this->assertSame($newContact, $riderUser->contact_number);
    }

    public function test_profile_shows_document_verification_state_without_exposing_private_file_path(): void
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'RIDER-DOCUMENT-'.str()->upper(str()->random(8)),
            'business_name' => 'Rider Document Center',
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
            'plate_number' => 'RIDER-DOCUMENT-PLATE',
            'drivers_license_number' => 'RIDER-DOCUMENT-LICENSE',
            'status' => 'ACTIVE',
        ]);
        $application = RegistrationApplication::create([
            'application_number' => 'RIDER-DOCUMENT-'.str()->upper(str()->random(8)),
            'user_id' => $riderUser->id,
            'status' => RegistrationApplication::STATUS_APPROVED,
            'submitted_at' => now(),
        ]);
        RiderApplicationData::create([
            'registration_application_id' => $application->id,
            'target_logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => 'RIDER-DOCUMENT-APP-PLATE',
            'drivers_license_number' => 'RIDER-DOCUMENT-APP-LICENSE',
        ]);
        ApplicationDocument::create([
            'registration_application_id' => $application->id,
            'document_type' => 'drivers_license',
            'file_path' => 'private/rider-documents/secret-license.pdf',
            'original_name' => 'secret-license.pdf',
            'mime_type' => 'application/pdf',
            'verification_status' => 'VERIFIED',
            'verified_by_user_id' => $owner->id,
            'verified_at' => now(),
        ]);

        $this->actingAs($riderUser)
            ->get(route('rider.profile'))
            ->assertOk()
            ->assertSee('Drivers License')
            ->assertSee('Verified')
            ->assertDontSee('private/rider-documents/secret-license.pdf')
            ->assertDontSee('secret-license.pdf');
    }
}
