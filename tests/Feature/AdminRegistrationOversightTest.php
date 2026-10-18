<?php

namespace Tests\Feature;

use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderApplicationData;
use App\Models\Seller\Category;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminRegistrationOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_inspect_rider_application_and_private_document_but_cannot_review_it(): void
    {
        Storage::fake('registrations');

        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $logisticsOwner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $logisticsOwner->id,
            'code' => 'CENTER-OVERSIGHT',
            'business_name' => 'Rider Hub',
            'status' => 'ACTIVE',
        ]);
        $rider = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_PENDING,
            'profile_photo_path' => 'profile-photos/rider.webp',
        ]);
        $application = RegistrationApplication::create([
            'application_number' => 'APP-RIDER-OVERSIGHT',
            'user_id' => $rider->id,
            'status' => RegistrationApplication::STATUS_PENDING,
            'submitted_at' => now(),
        ]);
        RiderApplicationData::create([
            'registration_application_id' => $application->id,
            'target_logistics_center_id' => $center->id,
            'vehicle_type' => 'MOTORCYCLE',
            'plate_number' => 'ABC1234',
            'drivers_license_number' => 'LICENSE123',
        ]);
        $path = 'registration/rider/APP-RIDER-OVERSIGHT/valid_id/id.webp';
        Storage::disk('registrations')->put($path, 'image-bytes');
        $document = ApplicationDocument::create([
            'registration_application_id' => $application->id,
            'document_type' => 'VALID_ID',
            'file_path' => $path,
            'original_name' => 'original.jpg',
            'mime_type' => 'image/webp',
            'verification_status' => 'PENDING',
        ]);

        $this->actingAs($admin)->get(route('admin.registrations', ['type' => 'RIDER']))
            ->assertOk()
            ->assertSee('APP-RIDER-OVERSIGHT')
            ->assertSee('ABC1234');

        $this->get(route('admin.registrations.show', $application))
            ->assertOk()
            ->assertSee('Rider Hub')
            ->assertSee('Profile photo')
            ->assertSee('profile-photos/rider.webp')
            ->assertDontSee('Approve Registration');

        $response = $this->get(route('admin.registrations.documents.show', [$application, $document]));
        $response->assertOk();
        $this->assertStringContainsString('valid_id.webp', $response->headers->get('content-disposition'));

        $this->post(route('admin.registrations.approve', $application))->assertNotFound();
        $this->post(route('admin.registrations.documents.verify', [$application, $document]))->assertNotFound();
        $this->assertDatabaseHas('registration_applications', [
            'id' => $application->id,
            'status' => RegistrationApplication::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('application_documents', [
            'id' => $document->id,
            'verification_status' => 'PENDING',
        ]);
    }

    public function test_admin_review_page_shows_seller_branding_image_paths(): void
    {
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $seller = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_PENDING,
            'profile_photo_path' => 'profile-photos/seller.webp',
        ]);
        $category = Category::create(['name' => 'Crafts', 'slug' => 'crafts']);
        SellerProfile::create([
            'user_id' => $seller->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Craft Store',
            'avatar_path' => 'seller-branding/avatar.webp',
            'banner_path' => 'seller-branding/banner.webp',
            'status' => 'PENDING',
        ]);
        $application = RegistrationApplication::create([
            'application_number' => 'APP-SELLER-PHOTOS',
            'user_id' => $seller->id,
            'status' => RegistrationApplication::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.registrations.show', $application))
            ->assertOk()
            ->assertSee('profile-photos/seller.webp')
            ->assertSee('seller-branding/avatar.webp')
            ->assertSee('seller-branding/banner.webp')
            ->assertSee('Approve Registration');
    }
}
