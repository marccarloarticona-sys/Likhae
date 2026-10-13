<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFake;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_login_links_to_the_password_reset_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('href="'.route('password.request').'"', escape: false);
    }

    public function test_logistics_login_preserves_the_logistics_recovery_portal(): void
    {
        $this->get(route('logistics.login'))
            ->assertOk()
            ->assertSee('href="'.route('password.request', ['portal' => 'logistics']).'"', escape: false);
    }

    public function test_unknown_email_receives_a_non_enumerating_response(): void
    {
        NotificationFake::fake();

        $this->post(route('password.email'), ['email' => 'missing@example.com'])
            ->assertRedirect()
            ->assertSessionHas('status', 'If an account exists with this email, a password reset link will be sent.');

        NotificationFake::assertNothingSent();
    }

    public function test_reset_form_preserves_the_logistics_portal_and_offers_a_new_link(): void
    {
        $this->get(route('password.reset', [
            'token' => 'example-token',
            'email' => 'rider@example.com',
            'portal' => 'logistics',
        ]))
            ->assertOk()
            ->assertSee('Create New Password')
            ->assertSee('Request a new reset link')
            ->assertSee('name="portal" value="logistics"', escape: false);
    }

    public function test_password_reset_link_can_be_requested_and_used(): void
    {
        $this->withoutVite();
        NotificationFake::fake();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        $token = null;
        NotificationFake::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'UpdatedPassword1',
            'password_confirmation' => 'UpdatedPassword1',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('UpdatedPassword1', $user->fresh()->password));
    }

    public function test_rider_reset_returns_to_logistics_portal_without_changing_account_status(): void
    {
        $this->withoutVite();
        NotificationFake::fake();
        $user = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_SUSPENDED,
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
            'portal' => 'logistics',
        ])->assertRedirect();

        $token = null;
        NotificationFake::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'portal' => 'logistics',
            'password' => 'UpdatedPassword1',
            'password_confirmation' => 'UpdatedPassword1',
        ])->assertRedirect(route('logistics.login'));

        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
        $this->assertTrue(Hash::check('UpdatedPassword1', $user->fresh()->password));
    }
}
