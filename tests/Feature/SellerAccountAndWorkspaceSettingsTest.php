<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Buyer\Order;
use App\Models\Seller\Category;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SellerAccountAndWorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_pages_and_updates_are_scoped_to_the_signed_in_seller(): void
    {
        $this->withoutVite();
        [$seller, $profile] = $this->seller('Own Account Shop');
        [$foreignSeller, $foreignProfile] = $this->seller('Foreign Account Shop');

        $this->actingAs($seller)
            ->get(route('seller.account', ['user_id' => $foreignSeller->id]))
            ->assertOk()
            ->assertSee($seller->email)
            ->assertDontSee($foreignSeller->email);

        $this->put(route('seller.account.profile'), [
            'first_name' => 'Changed',
            'last_name' => 'Owner',
            'email' => $seller->email,
            'contact_number' => $seller->contact_number,
            'user_id' => $foreignSeller->id,
            'seller_profile_id' => $foreignProfile->id,
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_DEACTIVATED,
            'email_verified_at' => null,
        ])->assertRedirect();

        $this->assertSame('Changed', $seller->fresh()->first_name);
        $this->assertSame(User::TYPE_SELLER, $seller->fresh()->account_type);
        $this->assertSame(User::STATUS_ACTIVE, $seller->fresh()->status);
        $this->assertSame($foreignSeller->first_name, $foreignSeller->fresh()->first_name);
        $this->assertSame($foreignProfile->id, $foreignSeller->fresh()->sellerProfile->id);
        $this->assertSame('ACTIVE', $profile->fresh()->status);
    }

    public function test_profile_validation_normalizes_names_and_requires_email_reverification(): void
    {
        $this->withoutVite();
        [$seller] = $this->seller('Email Change Shop');
        [$otherSeller] = $this->seller('Duplicate Email Shop');
        $otherSeller->forceFill(['email' => 'TAKEN@example.com'])->save();

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'first_name' => "\u{00A0}\u{2003}",
            ]))
            ->assertSessionHasErrors('first_name');

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'email' => 'Taken@Example.com',
            ]))
            ->assertSessionHasErrors('email');

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'email' => 'not-an-email',
            ]))
            ->assertSessionHasErrors('email');

        NotificationFacade::fake();
        $response = $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'first_name' => '  María-José  ',
                'last_name' => '  De La Cruz  ',
                'email' => ' New.Seller@Example.com ',
                'contact_number' => '09171234568',
            ]));

        $response->assertRedirect(route('verification.notice'));
        $this->assertSame('María-José', $seller->fresh()->first_name);
        $this->assertSame('De La Cruz', $seller->fresh()->last_name);
        $this->assertSame('new.seller@example.com', $seller->fresh()->email);
        $this->assertSame('+639171234568', $seller->fresh()->contact_number);
        $this->assertNull($seller->fresh()->email_verified_at);
        NotificationFacade::assertSentTo($seller, VerifyEmail::class);

        $updatedSeller = $seller->fresh();
        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $updatedSeller->id,
            'hash' => sha1($updatedSeller->email),
        ]);
        $this->actingAs($updatedSeller)->get($verificationUrl)->assertRedirect(route('seller.dashboard'));
        $this->assertNotNull($seller->fresh()->email_verified_at);
    }

    public function test_contact_and_profile_photo_validation_keep_old_files_and_escape_profile_text(): void
    {
        $this->withoutVite();
        Storage::fake('public');
        [$seller] = $this->seller('Photo Account Shop');

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'first_name' => '<script>alert(1)</script>',
                'contact_number' => '12345',
            ]))
            ->assertSessionHasErrors('contact_number');

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'first_name' => '<script>alert(1)</script>',
                'contact_number' => '+639171234569',
                'profile_photo' => UploadedFile::fake()->image('seller-photo.png', 40, 40),
            ]))
            ->assertRedirect();

        $photoPath = $seller->fresh()->profile_photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('public')->assertExists($photoPath);

        $this->get(route('seller.account'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('seller-profile-photo', false);

        $this->actingAs($seller)
            ->put(route('seller.account.profile'), $this->profilePayload($seller, [
                'profile_photo' => UploadedFile::fake()->create('spoof.jpg', 1, 'text/plain'),
            ]))
            ->assertSessionHasErrors('profile_photo');

        $this->assertSame($photoPath, $seller->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists($photoPath);
    }

    public function test_business_edits_keep_private_contact_and_cannot_reassign_foreign_address_or_category(): void
    {
        config(['services.psgc.local_first' => true]);
        [$seller, $profile, $category, $sellerAddress] = $this->seller('Business Account Shop', withAddress: true);
        [$foreignSeller, $foreignProfile, , $foreignAddress] = $this->seller('Foreign Business Shop', withAddress: true);
        $profile->update(['business_address_id' => $foreignAddress->id]);
        $privateContact = $seller->contact_number;

        $this->actingAs($seller)->put(route('seller.account.business'), $this->businessPayload([
            'contact_number' => '09175554444',
            'business_type' => 'Forged Category',
            'seller_profile_id' => $foreignProfile->id,
            'status' => 'SUSPENDED',
            'approved_by_user_id' => $foreignSeller->id,
            'dti_sec_number' => '  DTI-UPDATED  ',
            'tin' => '  TIN-UPDATED  ',
        ]))->assertRedirect();

        $seller->refresh();
        $profile->refresh();
        $foreignAddress->refresh();
        $newAddress = $profile->businessAddress()->firstOrFail();

        $this->assertSame($privateContact, $seller->contact_number);
        $this->assertSame($category->id, $profile->primary_category_id);
        $this->assertSame('ACTIVE', $profile->status);
        $this->assertSame('DTI-UPDATED', $profile->business_registration_number);
        $this->assertSame('TIN-UPDATED', $profile->tin);
        $this->assertNotSame($foreignAddress->id, $newAddress->id);
        $this->assertSame($seller->id, (int) $newAddress->user_id);
        $this->assertSame('+639175554444', $newAddress->contact_number);
        $this->assertSame((int) $foreignSeller->id, (int) $foreignAddress->user_id);
        $this->assertSame('+639171234567', $foreignAddress->contact_number);
        $this->assertSame((int) $seller->id, (int) $sellerAddress->user_id);
    }

    public function test_password_change_requires_current_password_and_stores_a_hash(): void
    {
        [$seller] = $this->seller('Password Account Shop');

        $this->actingAs($seller)->put(route('seller.account.password'), [
            'current_password' => 'wrong-password',
            'password' => 'NewSellerPass9',
            'password_confirmation' => 'NewSellerPass9',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($seller)->put(route('seller.account.password'), [
            'current_password' => 'Password1',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($seller)->put(route('seller.account.password'), [
            'current_password' => 'Password1',
            'password' => 'NewSellerPass9',
            'password_confirmation' => 'MismatchSellerPass9',
        ])->assertSessionHasErrors('password');

        $this->actingAs($seller)->put(route('seller.account.password'), [
            'current_password' => 'Password1',
            'password' => 'NewSellerPass9',
            'password_confirmation' => 'NewSellerPass9',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('NewSellerPass9', $seller->fresh()->password));
        $this->assertAuthenticatedAs($seller);
    }

    public function test_workspace_settings_are_seller_scoped_persisted_and_reject_tampering(): void
    {
        $this->withoutVite();
        [$seller] = $this->seller('Settings Account Shop');
        [$otherSeller] = $this->seller('Other Settings Shop');
        $seller->forceFill(['notification_preferences' => [
            'theme' => 'light',
            'language' => 'en',
            'future_preference' => 'preserve-me',
            'seller_ai_assistant_enabled' => true,
            'seller_ai_response_sound' => false,
        ]])->save();

        $this->actingAs($seller)->get(route('seller.settings'))
            ->assertOk()
            ->assertSee('Show AI Assistant')
            ->assertSee('AI Response Sound')
            ->assertSee('Sound will apply when LIKHAE AI Assistant becomes available.')
            ->assertSee('Notification Sounds')
            ->assertSee('Coming soon')
            ->assertDontSee('name="seller_chat_updates"', false)
            ->assertDontSee('name="language"', false);

        $this->actingAs($seller)->put(route('seller.settings.update'), [
            'theme' => 'dark',
            'seller_order_updates' => '0',
            'seller_review_updates' => '1',
            'seller_notification_sounds' => '0',
            'seller_ai_assistant_enabled' => '0',
            'seller_ai_response_sound' => '1',
        ])->assertRedirect();

        $preferences = $seller->fresh()->notification_preferences;
        $this->assertSame('dark', $preferences['theme']);
        $this->assertSame('en', $preferences['language']);
        $this->assertSame('preserve-me', $preferences['future_preference']);
        $this->assertFalse($preferences['seller_order_updates']);
        $this->assertTrue($preferences['seller_review_updates']);
        $this->assertFalse($preferences['seller_notification_sounds']);
        $this->assertFalse($preferences['seller_ai_assistant_enabled']);
        $this->assertTrue($preferences['seller_ai_response_sound']);
        $this->assertNull($otherSeller->fresh()->notification_preferences);

        $this->get(route('seller.account'))
            ->assertOk()
            ->assertSee('var t="dark"', false)
            ->assertDontSee('data-likhae-workspace-ai', false);

        $before = $seller->fresh()->notification_preferences;
        $this->actingAs($seller)->put(route('seller.settings.update'), [
            'theme' => 'light',
            'user_id' => $otherSeller->id,
        ])->assertSessionHasErrors('user_id');
        $this->actingAs($seller)->put(route('seller.settings.update'), [
            'theme' => 'light',
            'seller_order_updates' => 'sometimes',
        ])->assertSessionHasErrors('seller_order_updates');
        $this->actingAs($seller)->put(route('seller.settings.update'), [
            'theme' => 'light',
            'invented_setting' => '1',
        ])->assertSessionHasErrors('invented_setting');
        $this->assertSame($before, $seller->fresh()->notification_preferences);

        $this->actingAs($seller)->put(route('seller.settings.update'), [
            'theme' => 'light',
            'seller_ai_assistant_enabled' => '1',
            'seller_ai_response_sound' => '1',
        ])->assertRedirect();
        $this->get(route('seller.account'))
            ->assertOk()
            ->assertSee('data-likhae-workspace-ai', false)
            ->assertSee('data-ai-response-sound="1"', false);
    }

    public function test_account_notification_preferences_merge_without_erasing_settings_or_records(): void
    {
        [$seller] = $this->seller('Notification Account Shop');
        $seller->forceFill(['notification_preferences' => [
            'theme' => 'dark',
            'seller_ai_assistant_enabled' => false,
            'future_preference' => true,
        ]])->save();
        $historical = $seller->notifications()->create([
            'type' => 'SELLER_ORDER_PLACED',
            'title' => 'Older order alert',
            'message' => 'Historical notification remains.',
        ]);

        $this->actingAs($seller)->put(route('seller.account.notifications'), [
            'preferences' => ['new_order' => ['email' => '1']],
        ])->assertRedirect();

        $preferences = $seller->fresh()->notification_preferences;
        $this->assertSame('dark', $preferences['theme']);
        $this->assertFalse($preferences['seller_ai_assistant_enabled']);
        $this->assertTrue($preferences['future_preference']);
        $this->assertTrue($preferences['new_order']['email']);
        $this->assertFalse($preferences['new_order']['push']);
        $this->assertNull($historical->fresh()->read_at);
        $this->get(route('seller.account', ['tab' => 'notifications']))
            ->assertOk()
            ->assertSee(route('seller.settings'), false)
            ->assertDontSee('name="preferences[new_order][email]"', false);
    }

    public function test_order_notification_preference_suppresses_future_records_without_deleting_history(): void
    {
        [$seller, $profile] = $this->seller('Order Alert Shop');
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $seller->forceFill(['notification_preferences' => ['seller_order_updates' => true]])->save();
        $firstOrder = $this->order($buyer, 'SETTINGS-ORDER-1');
        $firstSellerOrder = $this->sellerOrder($profile, $firstOrder, 'SETTINGS-SELLER-ORDER-1');
        app(AccountNotificationService::class)->sellerOrderCreated($firstSellerOrder);
        $this->assertSame(1, $seller->notifications()->where('type', 'SELLER_ORDER_PLACED')->count());
        $historical = $seller->notifications()->where('type', 'SELLER_ORDER_PLACED')->firstOrFail();

        $seller->forceFill(['notification_preferences' => ['seller_order_updates' => false]])->save();
        $secondOrder = $this->order($buyer, 'SETTINGS-ORDER-2');
        $secondSellerOrder = $this->sellerOrder($profile, $secondOrder, 'SETTINGS-SELLER-ORDER-2');
        app(AccountNotificationService::class)->sellerOrderCreated($secondSellerOrder);

        $this->assertSame(1, $seller->notifications()->where('type', 'SELLER_ORDER_PLACED')->count());
        $this->assertSame('New order received', $historical->fresh()->title);
    }

    public function test_account_and_settings_mutations_keep_web_auth_and_seller_middleware(): void
    {
        foreach (['seller.account.profile', 'seller.account.business', 'seller.account.password', 'seller.settings.update'] as $routeName) {
            $middleware = Route::getRoutes()->getByName($routeName)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('auth', $middleware);
            $this->assertContains('verified', $middleware);
            $this->assertContains('App\\Http\\Middleware\\EnsureWorkspaceRole:seller', $middleware);
            $this->assertContains('seller.approved', $middleware);
        }
    }

    private function profilePayload(User $seller, array $overrides = []): array
    {
        return array_replace([
            'first_name' => $seller->first_name,
            'last_name' => $seller->last_name,
            'email' => $seller->email,
            'contact_number' => $seller->contact_number,
        ], $overrides);
    }

    private function seller(string $businessName, bool $withAddress = false): array
    {
        $user = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::create([
            'name' => 'Seller Test Category '.uniqid(),
            'slug' => 'seller-test-category-'.uniqid(),
            'is_active' => true,
        ]);
        $address = $withAddress ? $this->address($user) : null;
        $profile = SellerProfile::create([
            'user_id' => $user->id,
            'primary_category_id' => $category->id,
            'business_address_id' => $address?->id,
            'business_name' => $businessName,
            'status' => 'ACTIVE',
        ]);

        return [$user, $profile, $category, $address];
    }

    private function address(User $user, string $contact = '+639171234567'): Address
    {
        return Address::create([
            'user_id' => $user->id,
            'label' => 'Seller business',
            'recipient_name' => $user->name,
            'contact_number' => $contact,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'postal_code' => '4000',
            'house_number' => '12',
            'street_address' => 'Mahogany Street',
            'landmark' => 'Near the barangay hall',
            'latitude' => '14.0698',
            'longitude' => '121.3258',
            'is_default' => false,
        ]);
    }

    private function businessPayload(array $overrides = []): array
    {
        return array_replace([
            'dti_sec_number' => 'DTI-OLD',
            'tin' => 'TIN-OLD',
            'contact_number' => '+639171234567',
            'region_code' => '0400000000',
            'region_name' => 'Region IV-A (CALABARZON)',
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => '0403424000',
            'municipality_name' => 'City of San Pablo',
            'barangay_code' => '0403424001',
            'barangay_name' => 'Bagong Bayan II-A',
            'postal_code' => '4000',
            'house_number' => '12',
            'street_address' => 'Mahogany Street',
            'landmark' => 'Near the barangay hall',
            'latitude' => '14.0698',
            'longitude' => '121.3258',
        ], $overrides);
    }

    private function order(User $buyer, string $number): Order
    {
        return Order::create([
            'order_number' => $number,
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'subtotal' => 50,
            'grand_total' => 53,
            'payment_status' => 'PENDING',
            'placed_at' => now(),
        ]);
    }

    private function sellerOrder(SellerProfile $profile, Order $order, string $number): SellerOrder
    {
        return SellerOrder::create([
            'seller_order_number' => $number,
            'order_id' => $order->id,
            'seller_profile_id' => $profile->id,
            'status' => 'PLACED',
            'item_subtotal' => 50,
            'grand_total' => 53,
        ]);
    }
}
