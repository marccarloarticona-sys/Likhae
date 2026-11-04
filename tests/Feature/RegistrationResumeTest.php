<?php

namespace Tests\Feature;

use App\Mail\RegistrationEmailVerificationCode;
use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegistrationResumeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_verification_survives_refresh_without_creating_an_account(): void
    {
        $email = $this->verifyEmail();

        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            $response = $this->get(route('register', ['role' => $role]))->assertOk();
            $response->assertSee('value="'.$email.'"', false)
                ->assertSee('Email verified. You can continue your registration.')
                ->assertDontSee('data-send-email-code', false);
            $this->assertMatchesRegularExpression('/id="email"[^>]*readonly/s', $response->getContent());
        }

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame($email, session('registration_email_verified'));
    }

    public function test_validation_failure_keeps_the_verified_email_available_on_reload(): void
    {
        $email = $this->verifyEmail();
        $this->from(route('register'))->post(route('register.store'), [
            'account_type' => 'buyer', 'email' => $email,
        ])->assertSessionHasErrors('first_name')->assertSessionDoesntHaveErrors('email');

        $this->get(route('register'))->assertOk()
            ->assertSee('value="'.$email.'"', false)
            ->assertSee('Email verified. You can continue your registration.');
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_a_different_old_email_is_not_treated_as_verified(): void
    {
        $this->verifyEmail();
        $response = $this->withSession(['_old_input' => ['email' => 'different@example.com']])
            ->get(route('register'))->assertOk()
            ->assertSee('value="different@example.com"', false)
            ->assertSee('data-send-email-code', false);
        $this->assertDoesNotMatchRegularExpression('/id="email"[^>]*readonly/s', $response->getContent());
    }

    public function test_failed_policy_recording_rolls_back_registration_and_allows_retry_after_refresh(): void
    {
        config(['services.psgc.local_first' => true]);
        Notification::fake();
        $email = $this->verifyEmail();
        $attempt = 0;
        $this->partialMock(PolicyAcceptanceService::class, function ($mock) use (&$attempt): void {
            $mock->shouldReceive('validateRegistrationConsent')->twice()->andReturn(collect());
            $mock->shouldReceive('recordRegistrationAcceptances')->twice()->andReturnUsing(function () use (&$attempt): void {
                if (++$attempt === 1) {
                    throw ValidationException::withMessages(['policy_acceptances' => 'Please retry registration.']);
                }
            });
        });
        $payload = $this->buyerPayload($email);

        $this->from(route('register'))->post(route('register.store'), $payload)
            ->assertSessionHasErrors('policy_acceptances');
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertSame($email, session('registration_email_verified'));

        $this->get(route('register'))->assertOk()
            ->assertSee('Email verified. You can continue your registration.');
        $this->post(route('register.store'), $payload)
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $user = User::query()->where('email', $email)->sole();
        $this->assertDatabaseHas('registration_applications', ['user_id' => $user->id]);
        $this->assertNull(session('registration_email_verified'));

        $this->postJson(route('register.email-verification.send'), ['email' => $email])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    private function verifyEmail(): string
    {
        Mail::fake();
        $email = 'resume.'.Str::lower(Str::random(12)).'@example.com';
        $this->postJson(route('register.email-verification.send'), ['email' => $email])->assertOk();
        $code = Mail::sent(RegistrationEmailVerificationCode::class)->last()->code;
        $this->postJson(route('register.email-verification.verify'), ['email' => $email, 'code' => $code])->assertOk();

        return $email;
    }

    private function buyerPayload(string $email): array
    {
        return [
            'account_type' => 'buyer', 'first_name' => 'Resume', 'last_name' => 'Applicant',
            'sex' => 'prefer_not_to_say', 'email' => $email,
            'contact_number' => '+639'.random_int(100000000, 999999999),
            'birthday' => now()->subYears(25)->toDateString(), 'age' => 25,
            'region_code' => '0400000000', 'region' => 'Region IV-A (CALABARZON)',
            'province_code' => '0403400000', 'province' => 'Laguna',
            'municipality_code' => '0403424000', 'municipality' => 'City of San Pablo',
            'barangay_code' => '0403424001', 'barangay' => 'Bagong Bayan II-A',
            'postal_code' => '4000', 'street' => 'Mahogany Street',
            'password' => 'StrongPass123!', 'password_confirmation' => 'StrongPass123!',
            'terms_acknowledged' => '1', 'policy_acceptances' => ['1' => '1'],
        ];
    }
}
