<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_buyer_can_sign_in_through_marketplace_login(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->post(route('login.post'), [
            'email' => $buyer->email,
            'password' => 'Password1',
        ])->assertRedirect(route('buyer.home'));

        $this->assertAuthenticatedAs($buyer);
    }

    public function test_successful_login_remembers_safe_account_details_without_password(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $response = $this->post(route('login.post'), [
            'email' => $buyer->email,
            'password' => 'Password1',
        ])->assertRedirect(route('buyer.home'));

        $response->assertCookie('likhae_saved_accounts');
        $response->assertSessionHas('saved_accounts', function (array $accounts) use ($buyer): bool {
            $this->assertCount(1, $accounts);
            $this->assertSame($buyer->id, $accounts[0]['id']);
            $this->assertSame($buyer->email, $accounts[0]['email']);
            $this->assertSame(User::TYPE_BUYER, $accounts[0]['account_type']);
            $this->assertSame('marketplace', $accounts[0]['portal']);
            $this->assertArrayNotHasKey('password', $accounts[0]);
            $this->assertArrayNotHasKey('remember_token', $accounts[0]);

            return true;
        });
    }

    public function test_failed_login_does_not_add_a_recent_account(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->from(route('login'))
            ->post(route('login.post'), [
                'email' => $buyer->email,
                'password' => 'WrongPassword1',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Invalid email or password.'])
            ->assertSessionMissing('saved_accounts');
    }

    public function test_logout_keeps_recent_accounts_and_recent_account_can_be_removed_or_cleared(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->post(route('login.post'), ['email' => $buyer->email, 'password' => 'Password1'])
            ->assertRedirect(route('buyer.home'));
        $logout = $this->post(route('logout'));
        $logout->assertRedirect(route('login'))
            ->assertCookie('likhae_saved_accounts')
            ->assertSessionHas('saved_accounts', function (array $accounts) use ($buyer): bool {
                return collect($accounts)->contains(fn (array $account): bool => (int) $account['id'] === (int) $buyer->id);
            });
        $this->assertGuest();

        $loginPage = $this->get(route('login'))->assertOk();
        $this->assertCount(1, $loginPage->viewData('savedAccounts'));
        $loginPage
            ->assertSee('Recent accounts')
            ->assertSee('data-account-email="'.$buyer->email.'"', escape: false)
            ->assertSee('data-account-display-email=', escape: false)
            ->assertSee('Buyer')
            ->assertDontSee('data-account-password', escape: false);
        $this->assertCount(1, $loginPage->viewData('savedAccounts'));

        $removed = $this->post(route('login.recent-accounts.remove'), [
            'account_id' => $buyer->id,
            'portal' => 'marketplace',
        ]);
        $removed->assertRedirect(route('login'))
            ->assertSessionHas('saved_accounts', [])
            ->assertCookieExpired('likhae_saved_accounts');

        $secondBuyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->post(route('login.post'), ['email' => $secondBuyer->email, 'password' => 'Password1'])
            ->assertRedirect(route('buyer.home'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $cleared = $this->post(route('login.recent-accounts.clear'), ['portal' => 'marketplace']);
        $cleared->assertRedirect(route('login'))
            ->assertSessionHas('saved_accounts', [])
            ->assertCookieExpired('likhae_saved_accounts');
    }

    public function test_recent_accounts_are_separated_by_portal_for_multiple_roles(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);
        $rider = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->post(route('login.post'), ['email' => $buyer->email, 'password' => 'Password1'])
            ->assertRedirect(route('buyer.home'));
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('logistics.login.store'), ['email' => $rider->email, 'password' => 'Password1'])
            ->assertRedirect(route('rider.dashboard'));
        $this->post(route('logout'))->assertRedirect(route('logistics.login'));

        $this->get(route('login'))->assertOk()->assertSee($buyer->email)->assertDontSee($rider->email);
        $this->get(route('logistics.login'))->assertOk()->assertSee($rider->email)->assertDontSee($buyer->email);
    }

    public function test_active_rider_can_sign_in_through_logistics_portal(): void
    {
        $rider = User::factory()->create([
            'account_type' => User::TYPE_RIDER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->post(route('logistics.login.store'), [
            'email' => $rider->email,
            'password' => 'Password1',
        ])->assertRedirect(route('rider.dashboard'));

        $this->assertAuthenticatedAs($rider);
    }

    public function test_invalid_password_is_rejected_without_authenticating(): void
    {
        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->from(route('login'))
            ->post(route('login.post'), [
                'email' => $buyer->email,
                'password' => 'WrongPassword1',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Invalid email or password.']);

        $this->assertGuest();
    }
}
