<?php

namespace Tests\Feature;

use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Admin\PlatformPolicy;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\Category;
use App\Models\User;
use App\Http\Middleware\RequireMandatoryPolicyAcceptance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationMediaWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private PlatformPolicy $termsPolicy;

    private PlatformPolicy $privacyPolicy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.psgc.local_first' => true]);
        Storage::fake('registrations');
        Storage::fake('public');
        $this->withoutMiddleware(RequireMandatoryPolicyAcceptance::class);
        $this->termsPolicy = PlatformPolicy::query()->create([
            'title' => 'Terms & Conditions',
            'version' => '1.0',
            'body' => 'Test Terms & Conditions.',
            'policy_type' => 'TERMS',
            'audience' => 'ALL',
            'scope_key' => 'PLATFORM:ALL',
            'published_at' => now(),
            'is_active' => true,
        ]);
        $this->privacyPolicy = PlatformPolicy::query()->create([
            'title' => 'Privacy Policy',
            'version' => '1.0',
            'body' => 'Test Privacy Policy.',
            'policy_type' => 'PRIVACY',
            'audience' => 'ALL',
            'scope_key' => 'PLATFORM:ALL',
            'published_at' => now(),
            'is_active' => true,
        ]);
    }

    public function test_logistics_registration_stores_separate_private_documents_and_is_visible_to_admin(): void
    {
        $email = 'logistics.registration@example.com';
        $this->withSession(['registration_email_verified' => $email])
            ->post(route('register.store'), $this->registrationPayload('logistics', $email, '+639171230001', [
                'business_name' => 'San Pablo Parcel Hub',
                'business_registration_number' => 'AB123456789',
                'tin' => '123-456-789-001',
                'valid_id' => UploadedFile::fake()->image('identity.jpg', 1200, 1200),
                'business_permit' => UploadedFile::fake()->create('permit.pdf', 20, 'application/pdf'),
                'sec_certificate' => UploadedFile::fake()->image('sec-certificate.png', 1200, 1200),
                'bir_form_2303' => UploadedFile::fake()->create('bir-2303.pdf', 20, 'application/pdf'),
                'profile_picture' => UploadedFile::fake()->image('profile.png', 1200, 1200),
            ]))
            ->assertRedirect(route('logistics.login'))
            ->assertSessionHasNoErrors();

        $application = RegistrationApplication::query()->whereHas('user', fn ($query) => $query->where('email', $email))->sole();
        $documents = $application->documents()->get()->keyBy('document_type');
        $this->assertEqualsCanonicalizing(
            ['VALID_ID', 'BUSINESS_PERMIT', 'SEC_CERTIFICATE', 'BIR_FORM_2303'],
            $documents->keys()->all(),
        );

        foreach ($documents as $type => $document) {
            $this->assertStringStartsWith('registration/logistics/'.$application->application_number.'/'.strtolower($type).'/', $document->file_path);
            Storage::disk('registrations')->assertExists($document->file_path);
            Storage::disk('public')->assertMissing($document->file_path);
        }

        $this->assertNotNull($application->user->profile_photo_path);
        Storage::disk('public')->assertExists($application->user->profile_photo_path);

        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $this->actingAs($admin)
            ->get(route('admin.registrations', ['type' => User::TYPE_LOGISTICS]))
            ->assertOk()
            ->assertSee('San Pablo Parcel Hub');
        $this->get(route('admin.registrations.show', $application))
            ->assertOk()
            ->assertSee('Business Permit');
        $this->get(route('admin.registrations.documents.show', [$application, $documents['BUSINESS_PERMIT']]))
            ->assertOk();
    }

    public function test_seller_registration_stores_private_documents_and_public_shop_photos(): void
    {
        $category = Category::query()->create(['name' => 'Crafts', 'slug' => 'crafts', 'is_active' => true]);
        $email = 'seller.registration@example.com';

        $this->withSession(['registration_email_verified' => $email])
            ->post(route('register.store'), $this->registrationPayload('seller', $email, '+639171230002', [
                'business_name' => 'Laguna Handmade',
                'line_of_business' => $category->id,
                'seller_type' => 'sole_proprietorship',
                'tin' => '123-456-789-002',
                'valid_id' => UploadedFile::fake()->image('identity.png', 1200, 1200),
                'dti_certificate' => UploadedFile::fake()->create('dti.pdf', 20, 'application/pdf'),
                'bir_form_2303' => UploadedFile::fake()->create('bir.pdf', 20, 'application/pdf'),
                'store_avatar' => UploadedFile::fake()->image('avatar.png', 1200, 1200),
                'store_banner' => UploadedFile::fake()->image('banner.png', 1600, 600),
            ]))
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors();

        $application = RegistrationApplication::query()->whereHas('user', fn ($query) => $query->where('email', $email))->sole();
        $this->assertEqualsCanonicalizing(
            ['VALID_ID', 'DTI_CERTIFICATE', 'BIR_FORM_2303'],
            $application->documents()->pluck('document_type')->all(),
        );
        foreach ($application->documents as $document) {
            $this->assertStringStartsWith('registration/seller/'.$application->application_number.'/'.strtolower($document->document_type).'/', $document->file_path);
            Storage::disk('registrations')->assertExists($document->file_path);
        }

        $profile = $application->user->sellerProfile;
        $this->assertNotNull($profile);
        Storage::disk('public')->assertExists($profile->avatar_path);
        Storage::disk('public')->assertExists($profile->banner_path);
    }

    public function test_rider_001_assigned_logistics_approval_activates_rider_access_and_admin_cannot_approve(): void
    {
        $centerOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::query()->create([
            'owner_user_id' => $centerOwner->id,
            'address_id' => $this->centerAddress($centerOwner)->id,
            'code' => 'LC-TEST-1',
            'business_name' => 'San Pablo Sorting Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $centerOwner->id,
            'approved_at' => now(),
        ]);
        $email = 'rider.registration@example.com';

        $this->withSession(['registration_email_verified' => $email])
            ->post(route('register.store'), $this->registrationPayload('rider', $email, '+639171230003', [
                'target_logistics_center_id' => $center->id,
                'vehicle_type' => 'motorcycle',
                'plate_number' => 'ABC 1234',
                'drivers_license_number' => 'D12-34-567890',
                'valid_id' => UploadedFile::fake()->image('identity.jpg', 1200, 1200),
                'or_cr' => UploadedFile::fake()->create('or-cr.pdf', 20, 'application/pdf'),
                'drivers_license' => UploadedFile::fake()->image('license.png', 1200, 1200),
            ]))
            ->assertRedirect(route('logistics.login'))
            ->assertSessionHasNoErrors();

        $application = RegistrationApplication::query()->whereHas('user', fn ($query) => $query->where('email', $email))->sole();
        $documents = $application->documents()->get();
        $this->assertEqualsCanonicalizing(['VALID_ID', 'VEHICLE_OR_CR', 'DRIVERS_LICENSE'], $documents->pluck('document_type')->all());
        foreach ($documents as $document) {
            $this->assertStringStartsWith('registration/rider/'.$application->application_number.'/'.strtolower($document->document_type).'/', $document->file_path);
            Storage::disk('registrations')->assertExists($document->file_path);
        }

        $this->actingAs($centerOwner)
            ->get(route('logistics.riders.applications'))
            ->assertOk()
            ->assertSee($email)
            ->assertSee('Review application');
        $this->get(route('logistics.riders.application.show', $application))
            ->assertOk()
            ->assertSee('Driver&#039;s License', false)
            ->assertSee('identity.jpg');

        $license = $documents->firstWhere('document_type', 'DRIVERS_LICENSE');
        $download = $this->get(route('logistics.riders.application.documents.show', [$application, $license]));
        $download->assertOk();
        $this->assertStringContainsString(pathinfo($license->file_path, PATHINFO_EXTENSION), $download->headers->get('content-disposition'));
        $this->get(route('logistics.riders.application.documents.show', [$application, $license]).'?preview=1')
            ->assertOk();

        foreach ($documents as $document) {
            $this->post(route('logistics.riders.application.documents.verify', [$application, $document]))
                ->assertRedirect();
        }

        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
        ]);
        $statusBeforeAdminDecision = $application->fresh()->status;
        $this->actingAs($admin)
            ->post(route('admin.registrations.approve', $application))
            ->assertNotFound();
        $this->assertSame($statusBeforeAdminDecision, $application->fresh()->status);

        $this->actingAs($centerOwner)
            ->from(route('logistics.riders.application.show', $application))
            ->post(route('logistics.riders.approve', $application))
            ->assertRedirect(route('logistics.riders.application.show', $application));
        $this->assertSame(RegistrationApplication::STATUS_APPROVED, $application->fresh()->status);

        $rider = RiderProfile::query()->where('user_id', $application->user_id)->sole();
        $this->assertSame((int) $center->id, (int) $rider->logistics_center_id);
        $this->assertSame('ACTIVE', $rider->status);
        $this->assertSame((int) $centerOwner->id, (int) $rider->approved_by_user_id);
        $this->assertNotNull($rider->approved_at);
        $this->assertSame(User::STATUS_ACTIVE, $application->user->fresh()->status);
        $this->actingAs($application->user->fresh())
            ->get(route('rider.dashboard'))
            ->assertOk();

        $approvalAuditCount = DB::table('audit_logs')
            ->where('event', 'registration.approved')
            ->where('auditable_type', RegistrationApplication::class)
            ->where('auditable_id', $application->id)
            ->count();
        $this->actingAs($centerOwner)
            ->post(route('logistics.riders.approve', $application))
            ->assertRedirect(route('logistics.riders.application.show', $application))
            ->assertSessionHasErrors('application');
        $this->assertSame(1, RiderProfile::query()->where('user_id', $application->user_id)->count());
        $this->assertSame($approvalAuditCount, DB::table('audit_logs')
            ->where('event', 'registration.approved')
            ->where('auditable_type', RegistrationApplication::class)
            ->where('auditable_id', $application->id)
            ->count());

        $otherOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        LogisticsCenter::query()->create([
            'owner_user_id' => $otherOwner->id,
            'address_id' => $this->centerAddress($otherOwner, '0403425000')->id,
            'code' => 'LC-TEST-2',
            'business_name' => 'Other Sorting Center',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $otherOwner->id,
            'approved_at' => now(),
        ]);
        $this->actingAs($otherOwner)
            ->get(route('logistics.riders.application.documents.show', [$application, $license]))
            ->assertNotFound();
    }

    private function centerAddress(User $owner, string $municipalityCode = '0403424000'): Address
    {
        return Address::query()->create([
            'user_id' => $owner->id,
            'recipient_name' => $owner->name,
            'contact_number' => $owner->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => $municipalityCode,
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'postal_code' => '4000',
            'street_address' => 'Hub Street',
            'is_default' => true,
        ]);
    }

    private function registrationPayload(string $type, string $email, string $phone, array $overrides): array
    {
        return array_replace([
            'account_type' => $type,
            'first_name' => 'Test',
            'last_name' => 'Applicant',
            'sex' => 'prefer_not_to_say',
            'email' => $email,
            'contact_number' => $phone,
            'birthday' => now()->subYears(25)->toDateString(),
            'age' => 25,
            'region_code' => '0400000000',
            'region' => 'Region IV-A (CALABARZON)',
            'province_code' => '0403400000',
            'province' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay' => 'Bagong Bayan II-A',
            'postal_code' => '4000',
            'street' => 'Mahogany Street',
            'latitude' => '14.0698',
            'longitude' => '121.3258',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'terms_acknowledged' => '1',
            'privacy_acknowledged' => '1',
            'policy_acceptances' => [
                $this->termsPolicy->id => $this->termsPolicy->version,
                $this->privacyPolicy->id => $this->privacyPolicy->version,
            ],
        ], $overrides);
    }
}
