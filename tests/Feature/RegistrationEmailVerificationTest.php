<?php

namespace Tests\Feature;

use App\Mail\RegistrationEmailVerificationCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RegistrationEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_form_prompts_for_email_verification(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Send verification code')
            ->assertSee('Email verification code')
            ->assertSee('Verify your email address before continuing.')
            ->assertSee('Minimum age: 18 years old.');
    }

    public function test_registration_rejects_anyone_under_18(): void
    {
        $birthday = now()->subYears(17)->subDay()->toDateString();

        $this->post(route('register.store'), [
            'account_type' => 'buyer',
            'first_name' => 'Underage',
            'last_name' => 'Applicant',
            'sex' => 'prefer_not_to_say',
            'email' => 'underage@example.com',
            'contact_number' => '+639171234568',
            'birthday' => $birthday,
            'age' => 17,
            'region' => 'Region',
            'region_code' => 'region',
            'province' => 'Province',
            'province_code' => 'province',
            'municipality' => 'Municipality',
            'municipality_code' => 'municipality',
            'barangay' => 'Barangay',
            'barangay_code' => 'barangay',
            'postal_code' => '1000',
            'street' => 'Test Street',
            'latitude' => '14.5995',
            'longitude' => '120.9842',
            'valid_id' => UploadedFile::fake()->create('valid-id.pdf', 10, 'application/pdf'),
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'terms' => '1',
        ])->assertSessionHasErrors('birthday');

        $this->assertDatabaseMissing('users', ['email' => 'underage@example.com']);
    }

    public function test_registration_email_code_is_sent_and_can_be_used_once(): void
    {
        Mail::fake();

        $email = 'new.buyer@example.com';

        $sendResponse = $this->postJson(route('register.email-verification.send'), ['email' => $email]);
        $this->assertSame(200, $sendResponse->status(), $sendResponse->getContent());
        $sendResponse->assertJsonPath('message', 'A verification code was sent. It expires in 5 minutes.');

        $mailable = Mail::sent(RegistrationEmailVerificationCode::class)->first();
        $this->assertNotNull($mailable);
        $this->assertTrue($mailable->hasTo($email));

        $verifyResponse = $this->postJson(route('register.email-verification.verify'), [
            'email' => $email,
            'code' => $mailable->code,
        ]);
        $this->assertSame(200, $verifyResponse->status(), $verifyResponse->getContent());
        $this->assertSame($email, session('registration_email_verified'));

        $this->postJson(route('register.email-verification.verify'), [
            'email' => $email,
            'code' => $mailable->code,
        ])->assertUnprocessable();
    }

    public function test_existing_account_email_does_not_receive_a_registration_code(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'existing@example.com']);

        $this->postJson(route('register.email-verification.send'), ['email' => 'EXISTING@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Mail::assertNothingSent();
    }

    public function test_registration_submission_is_rejected_until_the_email_is_verified(): void
    {
        $email = 'unverified.buyer@example.com';
        $birthday = now()->subYears(25)->subDay()->toDateString();

        $this->post(route('register.store'), [
            'account_type' => 'buyer',
            'first_name' => 'Test',
            'last_name' => 'Buyer',
            'sex' => 'prefer_not_to_say',
            'email' => $email,
            'contact_number' => '+639171234567',
            'birthday' => $birthday,
            'age' => 25,
            'region' => 'Region',
            'region_code' => 'region',
            'province' => 'Province',
            'province_code' => 'province',
            'municipality' => 'Municipality',
            'municipality_code' => 'municipality',
            'barangay' => 'Barangay',
            'barangay_code' => 'barangay',
            'postal_code' => '1000',
            'street' => 'Test Street',
            'latitude' => '14.5995',
            'longitude' => '120.9842',
            'valid_id' => UploadedFile::fake()->create('valid-id.pdf', 10, 'application/pdf'),
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'terms' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_incorrect_email_code_does_not_verify_the_address(): void
    {
        Mail::fake();
        $email = 'new.buyer@example.com';

        $this->postJson(route('register.email-verification.send'), ['email' => $email])
            ->assertOk();
        $mailable = Mail::sent(RegistrationEmailVerificationCode::class)->first();
        $this->assertNotNull($mailable);
        $wrongCode = str_pad((string) (((int) $mailable->code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        $this->postJson(route('register.email-verification.verify'), [
            'email' => $email,
            'code' => $wrongCode,
        ])->assertUnprocessable();
    }

    public function test_registration_code_remains_usable_after_many_incorrect_attempts(): void
    {
        Mail::fake();
        $email = 'retrying.buyer@example.com';

        $this->postJson(route('register.email-verification.send'), ['email' => $email])->assertOk();
        $mailable = Mail::sent(RegistrationEmailVerificationCode::class)->first();
        $this->assertNotNull($mailable);
        $wrongCode = str_pad((string) (((int) $mailable->code + 1) % 1_000_000), 6, '0', STR_PAD_LEFT);

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $this->postJson(route('register.email-verification.verify'), [
                'email' => $email,
                'code' => $wrongCode,
            ])->assertUnprocessable()
                ->assertJsonPath('message', 'That verification code is incorrect.');
        }

        $this->postJson(route('register.email-verification.verify'), [
            'email' => $email,
            'code' => $mailable->code,
        ])->assertOk();

        $this->assertSame($email, session('registration_email_verified'));
    }

    public function test_registration_code_can_be_resent_more_than_three_times(): void
    {
        Mail::fake();
        $email = 'resending.buyer@example.com';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('register.email-verification.send'), [
                'email' => $email,
            ])->assertOk();
        }

        Mail::assertSent(RegistrationEmailVerificationCode::class, 5);

        $latestCode = Mail::sent(RegistrationEmailVerificationCode::class)->last()->code;
        $this->postJson(route('register.email-verification.verify'), [
            'email' => $email,
            'code' => $latestCode,
        ])->assertOk();
    }
}
