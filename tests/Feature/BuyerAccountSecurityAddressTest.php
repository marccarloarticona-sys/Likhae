<?php

namespace Tests\Feature;

use App\Mail\BuyerEmailChangeVerificationCode;
use App\Mail\BuyerMobileChangeVerificationCode;
use App\Models\Buyer\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BuyerAccountSecurityAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.psgc.local_first' => true]);
    }

    public function test_profile_names_are_trimmed_and_reject_digits_and_one_character_names(): void
    {
        $buyer = User::factory()->create(['email' => 'buyer@example.com', 'contact_number' => '+639171234567']);

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload([
                'first_name' => '  Maria-Jane  ',
                'last_name' => 'O’Connor ',
            ]))
            ->assertRedirect();

        $this->assertSame('Maria-Jane', $buyer->fresh()->first_name);
        $this->assertSame('O’Connor', $buyer->fresh()->last_name);

        foreach (['Juan123', 'J', '   '] as $invalidName) {
            $this->actingAs($buyer)
                ->put(route('buyer.account.profile.update'), $this->profilePayload(['first_name' => $invalidName]))
                ->assertSessionHasErrors('first_name');
        }
    }

    public function test_profile_photo_validation_preserves_the_current_photo_and_valid_upload_replaces_it(): void
    {
        Storage::fake('public');
        $buyer = User::factory()->create([
            'email' => 'buyer@example.com',
            'contact_number' => '+639171234567',
            'profile_photo_path' => 'profile-photos/existing.webp',
        ]);
        Storage::disk('public')->put('profile-photos/existing.webp', 'existing-photo');

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload([
                'profile_photo' => UploadedFile::fake()->create('not-an-image.png', 20, 'text/plain'),
            ]))
            ->assertSessionHasErrors('profile_photo');

        $this->assertSame('profile-photos/existing.webp', $buyer->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists('profile-photos/existing.webp');

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload([
                'profile_photo' => UploadedFile::fake()->image('buyer.png', 100, 100),
            ]))
            ->assertRedirect();

        $this->assertNotSame('profile-photos/existing.webp', $buyer->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing('profile-photos/existing.webp');
        Storage::disk('public')->assertExists($buyer->fresh()->profile_photo_path);
    }

    public function test_password_change_requires_current_password_strong_different_confirmed_password(): void
    {
        $buyer = User::factory()->create(['password' => Hash::make('CurrentPass1!')]);

        $this->actingAs($buyer)->put(route('buyer.account.password.update'), [
            'current_password' => 'WrongPass1!',
            'new_password' => 'BetterPass2!',
            'new_password_confirmation' => 'BetterPass2!',
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('CurrentPass1!', $buyer->fresh()->password));

        $this->actingAs($buyer)->put(route('buyer.account.password.update'), [
            'current_password' => 'CurrentPass1!',
            'new_password' => 'weakpass',
            'new_password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('new_password');

        $this->actingAs($buyer)->put(route('buyer.account.password.update'), [
            'current_password' => 'CurrentPass1!',
            'new_password' => 'BetterPass2!',
            'new_password_confirmation' => 'DifferentPass3!',
        ])->assertSessionHasErrors('new_password');

        $this->actingAs($buyer)->put(route('buyer.account.password.update'), [
            'current_password' => 'CurrentPass1!',
            'new_password' => 'CurrentPass1!',
            'new_password_confirmation' => 'CurrentPass1!',
        ])->assertSessionHasErrors('new_password');

        $this->actingAs($buyer)->put(route('buyer.account.password.update'), [
            'current_password' => 'CurrentPass1!',
            'new_password' => 'BetterPass2!',
            'new_password_confirmation' => 'BetterPass2!',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('BetterPass2!', $buyer->fresh()->password));
    }

    public function test_email_change_is_held_until_a_hashed_single_use_email_code_is_confirmed(): void
    {
        Mail::fake();
        $buyer = User::factory()->create(['email' => 'buyer@example.com', 'contact_number' => '+639171234567']);

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload(['email' => ' New.Buyer@Example.com ']))
            ->assertRedirect();

        $this->assertSame('buyer@example.com', $buyer->fresh()->email);
        $mailable = Mail::sent(BuyerEmailChangeVerificationCode::class)->first();
        $this->assertNotNull($mailable);
        $this->assertTrue($mailable->hasTo('new.buyer@example.com'));
        $challenge = cache()->get('buyer_contact_change:email_change:'.$buyer->id);
        $this->assertNotSame($mailable->code, $challenge['hash']);
        $this->actingAs($buyer)
            ->get(route('buyer.account', ['tab' => 'profile']))
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('Email confirmation required')
            ->assertSee('new.buyer@example.com')
            ->assertSee('Six-digit verification code');

        $wrongCode = str_pad((string) (((int) $mailable->code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);
        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $wrongCode])
            ->assertSessionHasErrors('code');

        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $mailable->code])
            ->assertRedirect();

        $this->assertSame('new.buyer@example.com', $buyer->fresh()->email);
        $this->assertNotNull($buyer->fresh()->email_verified_at);
        $this->assertNull(cache()->get('buyer_contact_change:email_change:'.$buyer->id));

        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $mailable->code])
            ->assertSessionHasErrors('code');
    }

    public function test_duplicate_email_is_rejected_before_sending_and_email_code_is_owner_bound_and_expires(): void
    {
        Mail::fake();
        $buyer = User::factory()->create(['email' => 'buyer@example.com', 'contact_number' => '+639171234567']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload(['email' => 'TAKEN@example.com']))
            ->assertSessionHasErrors('email');
        Mail::assertNothingSent();

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload(['email' => 'pending@example.com']))
            ->assertRedirect();
        $mailable = Mail::sent(BuyerEmailChangeVerificationCode::class)->first();
        $this->assertNotNull($mailable);

        $otherBuyer = User::factory()->create(['email' => 'other@example.com']);
        $this->actingAs($otherBuyer)
            ->post(route('buyer.account.email.verify'), ['code' => $mailable->code])
            ->assertSessionHasErrors('code');
        $this->assertSame('buyer@example.com', $buyer->fresh()->email);

        $this->travel(6)->minutes();
        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $mailable->code])
            ->assertSessionHasErrors('code');
        $this->assertSame('buyer@example.com', $buyer->fresh()->email);
    }

    public function test_account_mobile_change_uses_a_code_sent_to_the_verified_email(): void
    {
        Mail::fake();
        $buyer = User::factory()->create([
            'email' => 'buyer@example.com',
            'email_verified_at' => now(),
            'contact_number' => '+639171234567',
        ]);

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload(['phone' => '0917 123 4568']))
            ->assertRedirect()
            ->assertSessionHas('profile_mobile_otp_pending', true);

        $this->assertSame('+639171234567', $buyer->fresh()->contact_number);
        $mailable = Mail::sent(\App\Mail\BuyerMobileChangeVerificationCode::class)->first();
        $this->assertNotNull($mailable);
        $this->assertTrue($mailable->hasTo('buyer@example.com'));

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload([
                'phone' => '0917 123 4568',
                'mobile_change_code' => $mailable->code,
            ]))
            ->assertRedirect();

        $this->assertSame('+639171234568', $buyer->fresh()->contact_number);
    }

    public function test_email_and_mobile_change_codes_are_purpose_bound(): void
    {
        Mail::fake();
        $buyer = User::factory()->create([
            'email' => 'buyer@example.com',
            'email_verified_at' => now(),
            'contact_number' => '+639171234567',
        ]);
        $emailChanges = app(\App\Services\Account\BuyerEmailChangeOtpService::class);
        $mobileChanges = app(\App\Services\Account\BuyerMobileChangeOtpService::class);
        $emailChanges->send($buyer, 'pending@example.com');
        $mobileChanges->send($buyer, '+639171234568');
        $emailCode = Mail::sent(BuyerEmailChangeVerificationCode::class)->sole()->code;
        $mobileCode = Mail::sent(\App\Mail\BuyerMobileChangeVerificationCode::class)->sole()->code;
        $appliedEmail = null;
        $appliedMobile = null;

        try {
            $emailChanges->confirm($buyer, $mobileCode, function (string $email) use (&$appliedEmail): void {
                $appliedEmail = $email;
            });
            $this->fail('A mobile-change code must not authorize an email change.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertNull($appliedEmail);
        }

        try {
            $mobileChanges->confirm($buyer, $emailCode, function (string $phone) use (&$appliedMobile): void {
                $appliedMobile = $phone;
            });
            $this->fail('An email-change code must not authorize a mobile change.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertNull($appliedMobile);
        }

        $mobileChanges->confirm($buyer, $mobileCode, function (string $phone) use (&$appliedMobile): void {
            $appliedMobile = $phone;
        });
        $emailChanges->confirm($buyer, $emailCode, function (string $email) use (&$appliedEmail): void {
            $appliedEmail = $email;
        });

        $this->assertSame('+639171234568', $appliedMobile);
        $this->assertSame('pending@example.com', $appliedEmail);
    }

    public function test_email_resend_is_rate_limited_and_new_code_invalidates_the_old_one(): void
    {
        Mail::fake();
        $buyer = User::factory()->create(['email' => 'buyer@example.com', 'contact_number' => '+639171234567']);

        $this->actingAs($buyer)
            ->put(route('buyer.account.profile.update'), $this->profilePayload(['email' => 'pending@example.com']))
            ->assertRedirect();
        $firstCode = Mail::sent(BuyerEmailChangeVerificationCode::class)->first();
        $this->assertNotNull($firstCode);

        $this->actingAs($buyer)
            ->post(route('buyer.account.email.resend'))
            ->assertSessionHasErrors('email');
        Mail::assertSent(BuyerEmailChangeVerificationCode::class, 1);

        $this->travel(61)->seconds();
        $this->actingAs($buyer)->post(route('buyer.account.email.resend'))->assertRedirect();
        $latestCode = Mail::sent(BuyerEmailChangeVerificationCode::class)->last();
        $this->assertNotNull($latestCode);

        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $firstCode->code])
            ->assertSessionHasErrors('code');
        $this->actingAs($buyer)
            ->post(route('buyer.account.email.verify'), ['code' => $latestCode->code])
            ->assertRedirect();

        $this->assertSame('pending@example.com', $buyer->fresh()->email);
    }

    public function test_invalid_delivery_recipient_mobile_is_rejected(): void
    {
        $buyer = User::factory()->create();

        $this->actingAs($buyer)
            ->post(route('buyer.account.addresses.store'), $this->addressPayload(['contact_number' => '0917ABC4567']))
            ->assertSessionHasErrors('contact_number');

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_address_book_enforces_ten_owned_addresses_and_normalizes_recipient_phone(): void
    {
        $buyer = User::factory()->create(['contact_number' => '+639171234567']);

        for ($index = 1; $index <= 10; $index++) {
            $this->actingAs($buyer)
                ->post(route('buyer.account.addresses.store'), $this->addressPayload([
                    'street_address' => 'Street '.$index,
                    'contact_number' => '0917 123 4567',
                    'is_default' => $index === 1 ? '1' : null,
                ]))
                ->assertRedirect();
        }

        $this->assertDatabaseCount('addresses', 10);
        $this->assertDatabaseHas('addresses', ['user_id' => $buyer->id, 'contact_number' => '+639171234567']);

        $this->actingAs($buyer)
            ->post(route('buyer.account.addresses.store'), $this->addressPayload())
            ->assertSessionHasErrors('address_limit');

        $this->assertDatabaseCount('addresses', 10);
        $this->actingAs($buyer)->get(route('buyer.account', ['tab' => 'addresses']))
            ->assertOk()
            ->assertSee('You can save up to 10 delivery addresses.');
    }

    public function test_default_address_must_be_changed_before_delete_and_only_owners_are_affected(): void
    {
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();

        $this->actingAs($buyer)->post(route('buyer.account.addresses.store'), $this->addressPayload(['is_default' => '1']))->assertRedirect();
        $default = Address::query()->where('user_id', $buyer->id)->sole();
        $this->actingAs($buyer)->post(route('buyer.account.addresses.store'), $this->addressPayload([
            'street_address' => 'Second Street',
        ]))->assertRedirect();
        $second = Address::query()->where('user_id', $buyer->id)->whereKeyNot($default->id)->sole();

        $this->actingAs($otherBuyer)->post(route('buyer.account.addresses.store'), $this->addressPayload())->assertRedirect();
        $otherAddress = Address::query()->where('user_id', $otherBuyer->id)->sole();

        $this->actingAs($buyer)
            ->delete(route('buyer.account.addresses.destroy', $default))
            ->assertSessionHasErrors('address');
        $this->assertDatabaseHas('addresses', ['id' => $default->id, 'is_default' => true]);

        $this->actingAs($buyer)
            ->put(route('buyer.account.addresses.update', $second), $this->addressPayload([
                'street_address' => 'Second Street',
                'is_default' => '1',
            ]))
            ->assertRedirect();
        $this->assertDatabaseHas('addresses', ['id' => $default->id, 'is_default' => false]);
        $this->assertDatabaseHas('addresses', ['id' => $second->id, 'is_default' => true]);

        $this->actingAs($buyer)->delete(route('buyer.account.addresses.destroy', $default))->assertRedirect();
        $this->assertDatabaseMissing('addresses', ['id' => $default->id]);
        $this->assertDatabaseHas('addresses', ['id' => $otherAddress->id, 'user_id' => $otherBuyer->id]);

        $this->actingAs($buyer)
            ->put(route('buyer.account.addresses.update', $otherAddress), $this->addressPayload())
            ->assertForbidden();
        $this->actingAs($buyer)
            ->delete(route('buyer.account.addresses.destroy', $otherAddress))
            ->assertForbidden();
        $this->assertDatabaseHas('addresses', ['id' => $otherAddress->id]);
    }

    public function test_last_default_address_can_be_deleted_without_automatic_reassignment(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->post(route('buyer.account.addresses.store'), $this->addressPayload(['is_default' => '1']))->assertRedirect();
        $address = Address::query()->where('user_id', $buyer->id)->sole();

        $this->actingAs($buyer)->delete(route('buyer.account.addresses.destroy', $address))->assertRedirect();

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_buyer_api_cannot_change_mobile_or_mass_assign_account_fields(): void
    {
        Mail::fake();
        $buyer = User::factory()->create([
            'email' => 'buyer@example.com',
            'contact_number' => '+639171234567',
            'account_type' => User::TYPE_BUYER,
        ]);
        $token = $this->issueApiToken($buyer);
        $base = [
            'first_name' => 'Maria',
            'middle_initial' => 'A.',
            'last_name' => 'Santos',
            'name_extension' => 'Jr.',
            'contact_number' => '+639171234567',
        ];

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), array_replace($base, [
                'email' => 'stolen@example.com',
                'account_type' => User::TYPE_ADMIN,
                'status' => User::STATUS_SUSPENDED,
                'contact_number' => '+639171234568',
            ]))
            ->assertAccepted()
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('verification_for', 'mobile_change');

        $this->assertSame('+639171234567', $buyer->fresh()->contact_number);
        $this->assertSame('buyer@example.com', $buyer->fresh()->email);
        $this->assertSame(User::TYPE_BUYER, $buyer->fresh()->account_type);
        $this->assertSame(User::STATUS_ACTIVE, $buyer->fresh()->status);
        Mail::assertSent(\App\Mail\BuyerMobileChangeVerificationCode::class);

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), array_replace($base, [
                'first_name' => 'Juan123',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('first_name');

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), array_replace($base, [
                'email' => 'stolen@example.com',
                'account_type' => User::TYPE_ADMIN,
                'status' => User::STATUS_SUSPENDED,
            ]))
            ->assertOk();

        $this->assertSame('buyer@example.com', $buyer->fresh()->email);
        $this->assertSame(User::TYPE_BUYER, $buyer->fresh()->account_type);
        $this->assertSame(User::STATUS_ACTIVE, $buyer->fresh()->status);
        $this->assertSame('+639171234567', $buyer->fresh()->contact_number);
    }

    public function test_buyer_api_confirms_mobile_change_with_otp_sent_to_verified_email(): void
    {
        Mail::fake();
        $buyer = User::factory()->create([
            'email' => 'buyer@example.com',
            'email_verified_at' => now(),
            'contact_number' => '+639171234567',
            'account_type' => User::TYPE_BUYER,
        ]);
        $token = $this->issueApiToken($buyer);
        $payload = [
            'first_name' => 'Maria',
            'middle_initial' => 'A.',
            'last_name' => 'Santos',
            'name_extension' => 'Jr.',
            'contact_number' => '0998 222 2222',
        ];

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), $payload)
            ->assertAccepted()
            ->assertJsonPath('verification_for', 'mobile_change');
        $this->assertSame('+639171234567', $buyer->fresh()->contact_number);

        $mailable = Mail::sent(BuyerMobileChangeVerificationCode::class)->sole();
        $this->assertTrue($mailable->hasTo('buyer@example.com'));
        $wrongCode = str_pad((string) (((int) $mailable->code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), $payload + ['mobile_change_code' => $wrongCode])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertSame('+639171234567', $buyer->fresh()->contact_number);

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.profile.update'), $payload + ['mobile_change_code' => $mailable->code])
            ->assertOk();
        $this->assertSame('+639982222222', $buyer->fresh()->contact_number);
    }

    public function test_buyer_api_enforces_address_limit_default_deletion_and_ownership(): void
    {
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $token = $this->issueApiToken($buyer);
        $otherToken = $this->issueApiToken($otherBuyer);
        $firstAddressId = null;

        for ($index = 1; $index <= 10; $index++) {
            $response = $this->withToken($token)->postJson(route('api.v1.buyer.addresses.store'), $this->addressPayload([
                'street_address' => 'API Street '.$index,
                'is_default' => $index === 1,
            ]))->assertCreated();

            if ($index === 1) {
                $firstAddressId = (int) $response->json('data.id');
            }
        }

        $this->withToken($token)
            ->postJson(route('api.v1.buyer.addresses.store'), $this->addressPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address_limit');

        $firstAddress = Address::query()->findOrFail($firstAddressId);
        $secondAddress = Address::query()->where('user_id', $buyer->id)->whereKeyNot($firstAddressId)->firstOrFail();

        $this->withToken($token)
            ->deleteJson(route('api.v1.buyer.addresses.destroy', $firstAddress))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('address');
        $this->assertDatabaseHas('addresses', ['id' => $firstAddressId, 'is_default' => true]);

        $this->withToken($otherToken)
            ->deleteJson(route('api.v1.buyer.addresses.destroy', $firstAddress))
            ->assertForbidden();

        $this->withToken($token)
            ->patchJson(route('api.v1.buyer.addresses.default', $secondAddress))
            ->assertOk();
        $this->withToken($token)
            ->deleteJson(route('api.v1.buyer.addresses.destroy', $firstAddress))
            ->assertOk();

        $this->assertDatabaseMissing('addresses', ['id' => $firstAddressId]);
        $this->assertDatabaseHas('addresses', ['id' => $secondAddress->id, 'is_default' => true]);
        $this->assertDatabaseCount('addresses', 9);
    }

    public function test_buyer_api_password_change_uses_the_same_password_policy(): void
    {
        $buyer = User::factory()->create(['password' => Hash::make('CurrentPass1!')]);
        $token = $this->issueApiToken($buyer);

        $this->withToken($token)->putJson(route('api.v1.buyer.password.update'), [
            'current_password' => 'CurrentPass1!',
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->withToken($token)->putJson(route('api.v1.buyer.password.update'), [
            'current_password' => 'CurrentPass1!',
            'password' => 'CurrentPass1!',
            'password_confirmation' => 'CurrentPass1!',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->withToken($token)->putJson(route('api.v1.buyer.password.update'), [
            'current_password' => 'CurrentPass1!',
            'password' => 'BetterPass2!',
            'password_confirmation' => 'BetterPass2!',
        ])->assertOk();

        $this->assertTrue(Hash::check('BetterPass2!', $buyer->fresh()->password));
    }

    /** @return array<string, mixed> */
    private function profilePayload(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Maria',
            'middle_initial' => '',
            'last_name' => 'Santos',
            'name_extension' => '',
            'email' => 'buyer@example.com',
            'phone' => '0917 123 4567',
            'birthday' => '1995-01-01',
            'gender' => 'female',
        ], $overrides);
    }

    private function issueApiToken(User $buyer): string
    {
        $token = bin2hex(random_bytes(32));
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addHour(),
        ])->save();

        return $token;
    }

    /** @return array<string, mixed> */
    private function addressPayload(array $overrides = []): array
    {
        return array_replace([
            'label' => 'Home',
            'recipient_name' => 'Test Buyer',
            'contact_number' => '09171234567',
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
}
