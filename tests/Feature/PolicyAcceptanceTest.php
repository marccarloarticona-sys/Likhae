<?php

namespace Tests\Feature;

use App\Models\Admin\PlatformPolicy;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\PolicyAcceptanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_displays_the_role_policies_in_an_unchecked_modal(): void
    {
        $this->termsPolicy();
        $this->privacyPolicy();

        $this->get(route('register', ['role' => 'buyer']))
            ->assertOk()
            ->assertSee('data-registration-policy-modal', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('Version 1.0')
            ->assertSee('Test Terms & Conditions')
            ->assertSee('Test Privacy Policy')
            ->assertSee('data-registration-terms-ack', false)
            ->assertSee('data-registration-privacy-ack', false)
            ->assertSee('I agree')
            ->assertDontSee('data-registration-terms-ack checked', false)
            ->assertDontSee('data-registration-privacy-ack checked', false);
    }

    public function test_pending_policies_block_protected_routes_until_exact_versions_are_accepted(): void
    {
        $terms = $this->termsPolicy();
        $privacy = $this->privacyPolicy();
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $fingerprint = app(PolicyAcceptanceService::class)->fingerprintFor($buyer);

        $this->actingAs($buyer)
            ->withSession(['policy_version_snapshot' => $fingerprint])
            ->get(route('buyer.settings'))
            ->assertRedirect(route('login'));

        $this->post(route('policies.accept'), [
            'policy_acceptances' => [
                $terms->id => $terms->version,
                $privacy->id => $privacy->version,
            ],
            'policy_acknowledgements' => [
                $terms->id => '1',
                $privacy->id => '1',
            ],
        ])->assertRedirect(route('buyer.home'));

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $buyer->id,
            'event' => PolicyAcceptanceService::ACCEPTED_EVENT,
            'auditable_type' => PlatformPolicy::class,
            'auditable_id' => $terms->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $buyer->id,
            'event' => PolicyAcceptanceService::ACCEPTED_EVENT,
            'auditable_type' => PlatformPolicy::class,
            'auditable_id' => $privacy->id,
        ]);

        $this->get(route('buyer.settings'))
            ->assertOk()
            ->assertDontSee('Review these policies to continue');
    }

    public function test_effective_policy_update_invalidates_the_existing_browser_session(): void
    {
        $this->termsPolicy();
        $this->privacyPolicy();
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $snapshot = app(PolicyAcceptanceService::class)->fingerprintFor($buyer);

        $this->termsPolicy('2.0', 'Updated Terms & Conditions.');

        $this->actingAs($buyer)
            ->withSession(['policy_version_snapshot' => $snapshot])
            ->get(route('buyer.settings'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_api_policy_gate_blocks_features_and_allows_the_acceptance_endpoint(): void
    {
        $terms = $this->termsPolicy();
        $privacy = $this->privacyPolicy();
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $token = 'policy-test-token';
        $buyer->forceFill([
            'api_token_hash' => hash('sha256', $token),
            'api_token_expires_at' => now()->addHour(),
            'api_token_policy_snapshot' => app(PolicyAcceptanceService::class)->fingerprintFor($buyer),
        ])->save();

        $this->withToken($token)
            ->getJson(route('api.v1.buyer.cart.index'))
            ->assertStatus(423)
            ->assertJsonPath('code', 'mandatory_policy_acceptance_required');

        $this->withToken($token)
            ->postJson(route('api.v1.policies.accept'), [
                'policy_acceptances' => [
                    $terms->id => $terms->version,
                    $privacy->id => $privacy->version,
                ],
                'policy_acknowledgements' => [
                    $terms->id => '1',
                    $privacy->id => '1',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('pending_policies', []);

        $this->withToken($token)
            ->getJson(route('api.v1.buyer.cart.index'))
            ->assertOk();
    }

    public function test_provider_rider_policy_is_visible_only_to_that_providers_riders(): void
    {
        $centerA = $this->center('LC-POLICY-A', 'Provider A');
        $centerB = $this->center('LC-POLICY-B', 'Provider B');
        $riderA = $this->rider($centerA, 'POLICY-A');
        $riderB = $this->rider($centerB, 'POLICY-B');

        $policy = PlatformPolicy::query()->create([
            'title' => 'Rider Operational Policy',
            'version' => '1.0',
            'body' => 'Provider A rider requirements.',
            'policy_type' => 'RIDER_OPERATIONAL',
            'audience' => User::TYPE_RIDER,
            'logistics_center_id' => $centerA->id,
            'scope_key' => 'RIDER_PROVIDER:'.$centerA->id,
            'published_at' => now(),
            'is_active' => true,
        ]);

        $service = app(PolicyAcceptanceService::class);
        $this->assertSame([$policy->id], $service->pendingPolicies($riderA)->pluck('id')->all());
        $this->assertSame([], $service->pendingPolicies($riderB)->pluck('id')->all());
    }

    private function termsPolicy(string $version = '1.0', string $body = 'Test Terms & Conditions.'): PlatformPolicy
    {
        return PlatformPolicy::query()->create([
            'title' => 'Terms & Conditions',
            'version' => $version,
            'body' => $body,
            'policy_type' => 'TERMS',
            'audience' => 'ALL',
            'scope_key' => 'PLATFORM:ALL',
            'published_at' => now(),
            'is_active' => true,
        ]);
    }

    private function privacyPolicy(): PlatformPolicy
    {
        return PlatformPolicy::query()->create([
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

    private function center(string $code, string $name): LogisticsCenter
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);

        return LogisticsCenter::query()->create([
            'owner_user_id' => $owner->id,
            'code' => $code,
            'business_name' => $name,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);
    }

    private function rider(LogisticsCenter $center, string $prefix): User
    {
        $rider = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        RiderProfile::query()->create([
            'user_id' => $rider->id,
            'logistics_center_id' => $center->id,
            'vehicle_type' => 'motorcycle',
            'plate_number' => $prefix.'-PLATE',
            'drivers_license_number' => $prefix.'-LICENSE',
            'status' => 'ACTIVE',
        ]);

        return $rider;
    }
}
