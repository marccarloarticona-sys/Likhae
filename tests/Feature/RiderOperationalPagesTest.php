<?php

namespace Tests\Feature;

use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderApplicationData;
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
