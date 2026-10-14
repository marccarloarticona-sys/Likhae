<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationAndRoleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_each_private_workspace(): void
    {
        foreach ([
            ['buyer.home', 'login'],
            ['seller.dashboard', 'login'],
            ['rider.dashboard', 'logistics.login'],
            ['logistics.dashboard', 'logistics.login'],
            ['admin.dashboard', 'login'],
        ] as [$privateRoute, $loginRoute]) {
            $this->get(route($privateRoute))->assertRedirect(route($loginRoute));
        }
    }

    public function test_authenticated_users_are_redirected_away_from_guest_auth_pages(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);

        $this->actingAs($buyer)
            ->get(route('login'))
            ->assertRedirect(route('buyer.home'));

        $this->get(route('register'))->assertRedirect(route('buyer.home'));
    }

    public function test_cross_workspace_urls_are_denied_server_side(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);
        $this->actingAs($buyer)->get(route('seller.dashboard'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();

        $seller = $this->user(User::TYPE_SELLER);
        $this->actingAs($seller)->get(route('admin.dashboard'))->assertForbidden();

        $rider = $this->rider('motorcycle');
        $this->actingAs($rider)->get(route('logistics.dashboard'))->assertForbidden();
        $this->get(route('admin.dashboard'))->assertForbidden();

        $logistics = $this->user(User::TYPE_LOGISTICS);
        $this->actingAs($logistics)->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_rider_vehicle_type_limits_local_and_inter_hub_routes(): void
    {
        $localRider = $this->rider('motorcycle');
        $this->actingAs($localRider)->get(route('rider.pickups'))->assertOk();
        $this->get(route('rider.transfers'))->assertForbidden();

        $hubRider = $this->rider('closed_van');
        $this->actingAs($hubRider)->get(route('rider.pickups'))->assertForbidden();
        $this->get(route('rider.transfers'))->assertOk();
        $this->get(route('rider.dashboard'))->assertRedirect(route('rider.transfers'));
    }

    public function test_hub_rider_is_also_denied_local_mobile_api_routes(): void
    {
        $hubRider = $this->rider('truck');
        $token = 'security-test-token';
        $hubRider->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addHour(),
        ])->save();

        $this->withToken($token)
            ->getJson(route('api.v1.rider.pickups.index'))
            ->assertForbidden();
    }

    public function test_suspended_account_loses_an_existing_authenticated_session(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);

        $this->actingAs($buyer)->get(route('buyer.home'))->assertOk();
        $buyer->update(['status' => User::STATUS_SUSPENDED]);

        $this->get(route('buyer.home'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_private_html_is_not_cached_and_logout_confirmation_is_present(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);

        $response = $this->actingAs($buyer)->get(route('buyer.home'))->assertOk();

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('Expires', '0');
        $response->assertSee('Confirm Logout');
        $response->assertSee('Are you sure you want to log out of your LIKHAE account?');
    }

    public function test_login_regenerates_session_id_and_logout_invalidates_session(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);

        $this->get(route('login'));
        $oldId = $this->app['session.store']->getId();

        $this->post(route('login.post'), [
            'email' => $buyer->email,
            'password' => 'Password1',
        ])->assertRedirect(route('buyer.home'));

        $authenticatedId = $this->app['session.store']->getId();
        $this->assertNotSame($oldId, $authenticatedId);
        $oldToken = $this->app['session.store']->token();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($authenticatedId, $this->app['session.store']->getId());
        $this->assertNotSame($oldToken, $this->app['session.store']->token());
    }

    public function test_get_logout_does_not_log_the_user_out(): void
    {
        $buyer = $this->user(User::TYPE_BUYER);

        $this->actingAs($buyer)
            ->get('/logout')
            ->assertStatus(405);

        $this->assertAuthenticatedAs($buyer);
    }

    public function test_logout_is_a_post_route_in_the_csrf_protected_web_group(): void
    {
        $route = app('router')->getRoutes()->getByName('logout');

        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
    }

    private function user(string $accountType): User
    {
        return User::factory()->create([
            'account_type' => $accountType,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function rider(string $vehicleType): User
    {
        $owner = $this->user(User::TYPE_LOGISTICS);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'SECURITY-CENTER-'.str()->random(10),
            'business_name' => 'Security Test Center',
            'status' => 'ACTIVE',
        ]);
        $rider = $this->user(User::TYPE_RIDER);

        RiderProfile::create([
            'user_id' => $rider->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => $vehicleType,
            'plate_number' => 'TEST-'.str()->random(8),
            'drivers_license_number' => 'LICENSE-'.str()->random(8),
            'status' => 'ACTIVE',
        ]);

        return $rider;
    }
}
