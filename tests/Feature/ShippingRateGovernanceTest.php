<?php

namespace Tests\Feature;

use App\Models\Finance\ShippingRateChangeRequest;
use App\Models\Finance\ShippingRateVersion;
use App\Models\Buyer\CartItem;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Finance\ShippingRateGovernanceService;
use App\Services\Finance\ShippingRatePolicyService;
use App\Services\Marketplace\CheckoutService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Builds a disposable in-memory schema directly; this test never runs application migrations. */
class ShippingRateGovernanceTest extends TestCase
{
    private string $originalDefaultConnection;
    private User $admin;
    private User $logistics;
    private User $otherLogistics;
    private ShippingRateGovernanceService $governance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->originalDefaultConnection = (string) config('database.default');
        config(['database.connections.shipping_rate_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('shipping_rate_test');
        DB::setDefaultConnection('shipping_rate_test');
        $this->buildTestSchema();

        $this->admin = $this->makeUser(User::TYPE_ADMIN, 'Admin');
        $this->logistics = $this->makeLogistics('Rate Test Center');
        $this->otherLogistics = $this->makeLogistics('Other Rate Test Center');
        $this->governance = app(ShippingRateGovernanceService::class);
        $this->governance->initializeGlobalRate($this->admin);
    }

    protected function tearDown(): void
    {
        DB::purge('shipping_rate_test');
        DB::setDefaultConnection($this->originalDefaultConnection);
        parent::tearDown();
    }

    public function test_logistics_request_is_pending_and_checkout_policy_stays_at_current_rate(): void
    {
        $request = $this->submit($this->logistics, '60.00');

        $this->assertSame(ShippingRateChangeRequest::STATUS_PENDING, $request->status);
        $this->assertSame('INCREASE', $request->change_type);
        $this->assertSame('10.00', $request->change_amount);
        $this->assertSame('20.0000', $request->change_percentage);
        $this->assertSame('50.00', app(ShippingRatePolicyService::class)->currentPolicy()['shipping_fee']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->admin->id,
            'reference_type' => 'SHIPPING_RATE_REQUEST',
            'reference_id' => $request->id,
            'type' => 'SHIPPING_RATE_REQUESTED',
        ]);
    }

    public function test_admin_bootstrap_persists_the_existing_global_fallback_without_a_migration_seed(): void
    {
        DB::table('shipping_rate_versions')->delete();

        $fallback = app(ShippingRatePolicyService::class)->currentPolicy();
        $this->assertFalse($fallback['initialized']);
        $this->assertSame('50.00', $fallback['shipping_fee']);

        $version = $this->governance->initializeGlobalRate($this->admin);

        $this->assertSame('GLOBAL', $version->scope);
        $this->assertSame('50.00', $version->shipping_fee);
        $this->assertSame('60.0000', $version->rider_pool_percentage);
        $this->assertSame('40.0000', $version->logistics_percentage);
        $this->assertTrue(app(ShippingRatePolicyService::class)->currentPolicy()['initialized']);

        $again = $this->governance->initializeGlobalRate($this->admin);
        $this->assertSame($version->id, $again->id);
        $this->assertSame(1, ShippingRateVersion::query()->count());
    }

    public function test_checkout_uses_config_fallback_when_no_active_version_exists(): void
    {
        DB::table('shipping_rate_versions')->delete();

        $preview = $this->checkoutPreview();

        $this->assertSame(50.0, $preview['shipping_total']);
        $this->assertSame(50.0, (float) $preview['groups']->first()['shipping_fee']);
        $this->assertNull($preview['groups']->first()['shipping_rate_version_id']);
    }

    public function test_checkout_uses_effective_active_global_version_for_new_preview(): void
    {
        $request = $this->submit($this->logistics, '60.00');
        $version = $this->governance->approve($this->admin, $request, expectedActiveVersionId: app(ShippingRatePolicyService::class)->currentVersion()->id);

        $preview = $this->checkoutPreview();

        $this->assertSame(60.0, $preview['shipping_total']);
        $this->assertSame(60.0, (float) $preview['groups']->first()['shipping_fee']);
        $this->assertSame($version->id, $preview['groups']->first()['shipping_rate_version_id']);
    }

    public function test_logistics_can_request_a_rider_share_and_admin_approval_versions_the_complement(): void
    {
        $request = $this->governance->submitRequest($this->logistics, [
            'requested_rider_pool_percentage' => '65.0000',
            'reason_code' => 'RIDER_COMPENSATION',
            'reason_details' => 'Request a higher Rider share for this service area.',
        ]);

        $this->assertSame('RIDER_SHARE', $request->change_type);
        $this->assertSame('60.0000', $request->current_rider_pool_percentage);
        $this->assertSame('65.0000', $request->requested_rider_pool_percentage);
        $this->assertSame('50.00', $request->requested_shipping_fee);

        $version = $this->governance->approve(
            $this->admin,
            $request,
            expectedActiveVersionId: app(ShippingRatePolicyService::class)->currentVersion()->id,
        );

        $this->assertSame('65.0000', $version->rider_pool_percentage);
        $this->assertSame('35.0000', $version->logistics_percentage);
        $this->assertSame('50.00', $version->shipping_fee);
    }

    public function test_admin_can_update_logistics_shipping_share_on_the_current_version(): void
    {
        $oldVersion = app(ShippingRatePolicyService::class)->currentVersion();

        $version = $this->governance->updateCommissionSplit($this->admin, '35.0000');

        $this->assertSame('65.0000', $version->rider_pool_percentage);
        $this->assertSame('35.0000', $version->logistics_percentage);
        $this->assertSame('50.00', $version->shipping_fee);
        $this->assertSame('SUPERSEDED', $oldVersion->fresh()->status);
        $this->assertDatabaseHas('finance_audit_events', ['event_type' => 'SHIPPING_COMMISSION_SPLIT_UPDATED']);
    }

    public function test_database_unique_index_rejects_a_second_active_global_version(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        ShippingRateVersion::query()->create([
            'scope' => 'GLOBAL',
            'shipping_fee' => '60.00',
            'rider_pool_percentage' => '60.0000',
            'logistics_percentage' => '40.0000',
            'effective_from' => now(),
            'status' => 'ACTIVE',
            'approved_by_admin_user_id' => $this->admin->id,
        ]);
    }

    public function test_approved_rate_change_does_not_recalculate_existing_order_shipping_snapshot(): void
    {
        $oldVersion = app(ShippingRatePolicyService::class)->currentVersion();
        DB::table('seller_orders')->insert([
            'shipping_fee' => '50.00',
            'shipping_rate_version_id' => $oldVersion->id,
        ]);
        $orderId = (int) DB::table('seller_orders')->value('id');
        $request = $this->submit($this->logistics, '60.00');

        $this->governance->approve($this->admin, $request, expectedActiveVersionId: app(ShippingRatePolicyService::class)->currentVersion()->id);

        $newVersion = app(ShippingRatePolicyService::class)->currentVersion();
        DB::table('seller_orders')->insert([
            'shipping_fee' => '60.00',
            'shipping_rate_version_id' => $newVersion->id,
        ]);
        $newOrderId = (int) DB::table('seller_orders')->orderByDesc('id')->value('id');

        $this->assertDatabaseHas('seller_orders', [
            'id' => $orderId,
            'shipping_fee' => '50.00',
            'shipping_rate_version_id' => $oldVersion->id,
        ]);
        $this->assertDatabaseHas('seller_orders', [
            'id' => $newOrderId,
            'shipping_fee' => '60.00',
            'shipping_rate_version_id' => $newVersion->id,
        ]);
        $this->assertSame('60.00', app(ShippingRatePolicyService::class)->currentPolicy()['shipping_fee']);
    }

    public function test_only_one_pending_request_per_logistics_user_is_allowed(): void
    {
        $this->submit($this->logistics, '60.00');

        try {
            $this->submit($this->logistics, '70.00');
            $this->fail('A second pending request should have been blocked.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('requested_shipping_fee', $exception->errors());
        }

        $this->assertSame(1, ShippingRateChangeRequest::query()
            ->where('requesting_logistics_user_id', $this->logistics->id)
            ->pending()->count());
    }

    public function test_another_logistics_operator_may_have_its_own_pending_request(): void
    {
        $first = $this->submit($this->logistics, '60.00');
        $second = $this->submit($this->otherLogistics, '70.00');

        $this->assertSame('PENDING', $first->status);
        $this->assertSame('PENDING', $second->status);
        $this->assertSame(2, ShippingRateChangeRequest::query()->pending()->count());
    }

    public function test_admin_approval_creates_an_immutable_global_version_for_exact_requested_amount(): void
    {
        $oldVersion = app(ShippingRatePolicyService::class)->currentVersion();
        $request = $this->submit($this->logistics, '60.00');

        $newVersion = $this->governance->approve(
            $this->admin,
            $request,
            'Approved after review.',
            expectedActiveVersionId: app(ShippingRatePolicyService::class)->currentVersion()->id,
        );

        $this->assertSame('60.00', $newVersion->shipping_fee);
        $this->assertSame($request->id, $newVersion->source_request_id);
        $this->assertSame($this->admin->id, $newVersion->approved_by_admin_user_id);
        $this->assertSame('SUPERSEDED', $oldVersion->fresh()->status);
        $this->assertNotNull($oldVersion->fresh()->effective_until);
        $this->assertSame('APPROVED', $request->fresh()->status);
        $this->assertSame('50.00', $request->fresh()->current_shipping_fee);
        $this->assertSame('60.00', app(ShippingRatePolicyService::class)->currentPolicy()['shipping_fee']);
        $this->assertDatabaseHas('finance_audit_events', [
            'event_type' => 'SHIPPING_RATE_REQUEST_APPROVED',
            'auditable_id' => $request->id,
            'actor_user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->logistics->id,
            'reference_type' => 'SHIPPING_RATE_REQUEST',
            'reference_id' => $request->id,
            'type' => 'SHIPPING_RATE_REQUEST_APPROVED',
        ]);
    }

    public function test_rejection_keeps_active_rate_and_requires_a_reason(): void
    {
        $version = app(ShippingRatePolicyService::class)->currentVersion();
        $request = $this->submit($this->logistics, '60.00');

        $this->expectException(ValidationException::class);
        $this->governance->reject($this->admin, $request, ' ');
    }

    public function test_rejection_records_reason_without_creating_a_new_rate_version(): void
    {
        $version = app(ShippingRatePolicyService::class)->currentVersion();
        $request = $this->submit($this->logistics, '60.00');

        $this->governance->reject($this->admin, $request, 'Rate is under review.');

        $this->assertSame('REJECTED', $request->fresh()->status);
        $this->assertSame('Rate is under review.', $request->fresh()->admin_decision_reason);
        $this->assertSame($version->id, app(ShippingRatePolicyService::class)->currentVersion()->id);
        $this->assertSame(1, ShippingRateVersion::query()->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->logistics->id,
            'type' => 'SHIPPING_RATE_REQUEST_REJECTED',
            'reference_id' => $request->id,
        ]);
    }

    public function test_logistics_cannot_approve_and_cannot_cancel_another_operators_request(): void
    {
        $request = $this->submit($this->logistics, '60.00');

        $this->actingAs($this->logistics)
            ->post(route('admin.shipping-rate-requests.approve', $request))
            ->assertForbidden();

        try {
            $this->governance->cancelRequest($this->otherLogistics, $request);
            $this->fail('A different Logistics operator must not cancel the request.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_logistics_can_cancel_its_own_pending_request(): void
    {
        $request = $this->submit($this->logistics, '60.00');

        $this->governance->cancelRequest($this->logistics, $request);

        $this->assertSame('CANCELLED', $request->fresh()->status);
        $this->assertDatabaseHas('finance_audit_events', [
            'event_type' => 'LOGISTICS_SHIPPING_RATE_REQUEST_CANCELLED',
            'auditable_id' => $request->id,
            'actor_user_id' => $this->logistics->id,
        ]);
    }

    public function test_logistics_and_admin_rate_pages_render_global_policy(): void
    {
        $this->actingAs($this->logistics)
            ->get(route('logistics.shipping-rate'))
            ->assertOk()
            ->assertSee('Current Approved Shipping Fee')
            ->assertSee('₱50.00');

        $this->actingAs($this->admin)
            ->get(route('admin.shipping-rate-requests'))
            ->assertOk()
            ->assertSee('Shipping Rate Requests')
            ->assertSee('GLOBAL rate policy');
    }

    public function test_stale_baseline_is_exposed_and_concurrent_approvals_serialize_into_auditable_versions(): void
    {
        $versionOne = app(ShippingRatePolicyService::class)->currentVersion();
        $requestA = $this->submit($this->logistics, '60.00');
        $requestB = $this->submit($this->otherLogistics, '70.00');

        $versionTwo = $this->governance->approve($this->admin, $requestA, expectedActiveVersionId: $versionOne->id);
        $this->assertSame('60.00', app(ShippingRatePolicyService::class)->currentPolicy()['shipping_fee']);
        $this->assertSame('50.00', $requestB->fresh()->current_shipping_fee);

        $this->actingAs($this->admin)
            ->get(route('admin.shipping-rate-requests.show', $requestB))
            ->assertOk()
            ->assertSee('Request baseline is stale.')
            ->assertSee('PHP 50.00')
            ->assertSee('PHP 60.00')
            ->assertSee('PHP 70.00');

        try {
            $this->governance->approve($this->admin, $requestB, expectedActiveVersionId: $versionTwo->id);
            $this->fail('A stale approval must require explicit baseline confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_stale_baseline', $exception->errors());
        }
        $this->assertSame('60.00', app(ShippingRatePolicyService::class)->currentPolicy()['shipping_fee']);
        $this->assertSame('PENDING', $requestB->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('admin.shipping-rate-requests.approve', $requestB), [
                'expected_active_version_id' => $versionOne->id,
                'confirm_stale_baseline' => '1',
                'admin_decision_reason' => 'Reviewed the changed baseline; approve exact PHP 70 target.',
            ])
            ->assertSessionHasErrors('expected_active_version_id');

        $this->actingAs($this->admin)
            ->post(route('admin.shipping-rate-requests.approve', $requestB), [
                'expected_active_version_id' => $versionTwo->id,
                'confirm_stale_baseline' => '1',
                'admin_decision_reason' => 'Reviewed the changed baseline; approve exact PHP 70 target.',
            ])
            ->assertRedirect(route('admin.shipping-rate-requests.show', $requestB))
            ->assertSessionHasNoErrors();
        $versionThree = ShippingRateVersion::query()->latest('id')->firstOrFail();

        $this->assertSame('70.00', $versionThree->shipping_fee);
        $this->assertSame('50.00', $requestB->fresh()->current_shipping_fee);
        $this->assertSame('SUPERSEDED', $versionOne->fresh()->status);
        $this->assertSame('SUPERSEDED', $versionTwo->fresh()->status);
        $this->assertSame('ACTIVE', $versionThree->fresh()->status);
        $this->assertSame(1, ShippingRateVersion::query()->where('scope', 'GLOBAL')->where('status', 'ACTIVE')->count());
        $this->assertSame('APPROVED', $requestA->fresh()->status);
        $this->assertSame('APPROVED', $requestB->fresh()->status);
        $this->assertDatabaseHas('finance_audit_events', [
            'event_type' => 'SHIPPING_RATE_REQUEST_APPROVED',
            'auditable_id' => $requestA->id,
            'actor_user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('finance_audit_events', [
            'event_type' => 'SHIPPING_RATE_REQUEST_APPROVED',
            'auditable_id' => $requestB->id,
            'actor_user_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('finance_audit_events', [
            'event_type' => 'SHIPPING_RATE_ACTIVATED',
            'auditable_id' => $versionThree->id,
            'actor_user_id' => $this->admin->id,
        ]);
    }

    public function test_non_admin_roles_cannot_read_or_mutate_admin_shipping_rate_routes(): void
    {
        $request = $this->submit($this->logistics, '60.00');
        $nonAdminTypes = [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_RIDER, User::TYPE_LOGISTICS];

        foreach ($nonAdminTypes as $type) {
            $user = $this->makeUser($type, 'Denied '.$type);
            $this->actingAs($user)
                ->get(route('admin.shipping-rate-requests'))
                ->assertForbidden();
            $this->actingAs($user)
                ->get(route('admin.shipping-rate-requests.show', $request))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.shipping-rate-requests.initialize'))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.shipping-rate-requests.approve', $request))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.shipping-rate-requests.reject', $request), ['admin_decision_reason' => 'No permission'])
                ->assertForbidden();
        }

        $this->assertSame(1, ShippingRateVersion::query()->where('status', 'ACTIVE')->count());
        $this->assertSame('PENDING', $request->fresh()->status);

        $this->actingAs($this->otherLogistics)
            ->post(route('admin.shipping-rate-requests.approve', $request))
            ->assertForbidden();
    }

    public function test_no_generic_rate_version_crud_routes_are_registered(): void
    {
        $rateRoutes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->getName() ?? '', 'shipping-rate'));

        $genericVersionCrudRoutes = $rateRoutes->filter(function ($route): bool {
            $name = strtolower((string) $route->getName());
            $uri = strtolower((string) $route->uri());
            $isGenericVersionResource = str_contains($name, 'shipping-rate-version')
                || preg_match('#(?:^|/)shipping-rate-versions?(?:/|$)#', $uri) === 1;

            return $isGenericVersionResource
                && (bool) array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);
        });

        $this->assertTrue($genericVersionCrudRoutes->isEmpty(), 'Shipping-rate policy must not expose generic rate-version CRUD endpoints.');
    }

    private function submit(User $user, string $amount): ShippingRateChangeRequest
    {
        return $this->governance->submitRequest($user, [
            'requested_shipping_fee' => $amount,
            'reason_code' => 'FUEL_COST_INCREASE',
            'reason_details' => 'Fuel and Rider operating costs changed.',
        ]);
    }

    private function checkoutPreview(): array
    {
        $sellerUser = $this->makeUser(User::TYPE_SELLER, 'Checkout Seller');
        $seller = (new SellerProfile())->forceFill(['id' => 901, 'status' => 'ACTIVE', 'vacation_mode' => false]);
        $seller->setRelation('user', $sellerUser);
        $product = (new Product())->forceFill([
            'id' => 902,
            'seller_profile_id' => 901,
            'name' => 'Checkout Fixture',
            'status' => 'ACTIVE',
            'archived_at' => null,
        ]);
        $product->setRelation('sellerProfile', $seller);
        $variant = (new ProductVariant())->forceFill([
            'id' => 903,
            'product_id' => 902,
            'price' => '100.00',
            'discount_type' => 'NONE',
            'discount_value' => '0.00',
            'is_active' => true,
            'stock' => 5,
        ]);
        $variant->setRelation('product', $product);
        $variant->setRelation('optionValues', collect());
        $item = (new CartItem())->forceFill(['id' => 904, 'product_variant_id' => 903, 'quantity' => 1]);
        $item->setRelation('productVariant', $variant);

        $availability = \Mockery::mock(\App\Services\Marketplace\BuyerProductAvailabilityService::class);
        $availability->shouldReceive('inspect')->once()->andReturn(['available' => true, 'message' => null]);
        $this->app->instance(\App\Services\Marketplace\BuyerProductAvailabilityService::class, $availability);

        return app(CheckoutService::class)->preview(collect([$item]));
    }

    private function makeLogistics(string $businessName): User
    {
        $user = $this->makeUser(User::TYPE_LOGISTICS, $businessName);
        LogisticsCenter::query()->create([
            'owner_user_id' => $user->id,
            'code' => 'RATE-'.strtoupper(substr(sha1($businessName), 0, 10)),
            'business_name' => $businessName,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $this->admin->id,
            'approved_at' => now(),
        ]);

        return $user;
    }

    private function makeUser(string $type, string $label): User
    {
        return User::query()->forceCreate([
            'first_name' => $label,
            'last_name' => 'Fixture',
            'email' => strtolower(str_replace(' ', '-', $label)).'-'.uniqid().'@example.test',
            'account_type' => $type,
            'status' => User::STATUS_ACTIVE,
            'password' => bcrypt('Password1'),
            'email_verified_at' => now(),
        ]);
    }

    private function buildTestSchema(): void
    {
        $schema = Schema::connection('shipping_rate_test');
        $schema->create('users', function ($table): void {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('account_type', 32);
            $table->string('status', 32);
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->text('notification_preferences')->nullable();
            $table->timestamps();
        });
        $schema->create('platform_settings', function ($table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 24)->default('string');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
        });
        $schema->create('logistics_centers', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->unique();
            $table->string('code')->unique();
            $table->string('business_name');
            $table->string('status', 32);
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        $schema->create('seller_profiles', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('business_name');
        });
        $schema->create('shipping_rate_change_requests', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('requesting_logistics_user_id');
            $table->decimal('current_shipping_fee', 12, 2);
            $table->decimal('requested_shipping_fee', 12, 2);
            $table->string('change_type', 16);
            $table->decimal('change_amount', 12, 2);
            $table->decimal('change_percentage', 9, 4);
            $table->decimal('current_rider_pool_percentage', 7, 4)->nullable();
            $table->decimal('requested_rider_pool_percentage', 7, 4)->nullable();
            $table->string('reason_code', 48);
            $table->text('reason_details')->nullable();
            $table->timestamp('requested_effective_at')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->unsignedBigInteger('reviewed_by_admin_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_decision_reason')->nullable();
            $table->timestamp('approved_effective_at')->nullable();
            $table->timestamps();
        });
        $schema->create('shipping_rate_versions', function ($table): void {
            $table->id();
            $table->string('scope', 24)->default('GLOBAL');
            $table->decimal('shipping_fee', 12, 2);
            $table->decimal('rider_pool_percentage', 7, 4);
            $table->decimal('logistics_percentage', 7, 4);
            $table->unsignedSmallInteger('pickup_rider_weight')->default(8);
            $table->unsignedSmallInteger('hub_to_hub_rider_weight')->default(7);
            $table->unsignedSmallInteger('last_mile_rider_weight')->default(15);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            $table->unsignedTinyInteger('active_global_slot')->nullable()
                ->virtualAs("CASE WHEN scope = 'GLOBAL' AND status = 'ACTIVE' THEN 1 ELSE NULL END");
            $table->unsignedBigInteger('approved_by_admin_user_id');
            $table->unsignedBigInteger('source_request_id')->nullable()->unique();
            $table->timestamps();
            $table->unique('active_global_slot', 'shipping_rate_one_active_global_unique');
        });
        $schema->create('seller_orders', function ($table): void {
            $table->id();
            $table->decimal('shipping_fee', 12, 2)->default(0);
            $table->unsignedBigInteger('shipping_rate_version_id')->nullable();
        });
        $schema->create('finance_audit_events', function ($table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('auditable_type', 150);
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        $schema->create('notifications', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 100);
            $table->string('title');
            $table->text('message');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
