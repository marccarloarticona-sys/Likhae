<?php

namespace Tests\Feature;

use App\Models\Seller\Voucher;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Finance\PlatformCampaignBudgetService;
use Tests\TestCase;

class PlatformVoucherIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_scoped_platform_voucher_and_sellers_cannot(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($admin)
            ->get(route('admin.marketing'))
            ->assertOk()
            ->assertSee('name="maximum_discount_amount"', escape: false)
            ->assertSee('name="per_user_limit"', escape: false);

        $this->post(route('admin.marketing.campaigns.store'), [
            'code' => 'PLATFORMCAP10',
            'name' => 'Platform cap voucher',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'benefit_scope' => 'PRODUCT',
            'minimum_order_amount' => 250,
            'maximum_discount_amount' => 40,
            'usage_limit' => 100,
            'per_user_limit' => 1,
            'campaign_budget_total' => 1000000,
            'campaign_budget_reason' => 'Authorized platform voucher test allocation',
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $voucher = Voucher::query()->where('code', 'PLATFORMCAP10')->sole();
        $this->assertNull($voucher->seller_profile_id);
        $this->assertSame('PLATFORMCAP10', $voucher->platform_code_key);
        $this->assertSame('PRODUCT', $voucher->benefit_scope);
        $this->assertSame('40.00', $voucher->maximum_discount_amount);
        $this->assertSame(1, $voucher->per_user_limit);
        $this->assertSame(100, $voucher->usage_limit);

        $this->actingAs($seller)
            ->post(route('admin.marketing.campaigns.store'), [
                'code' => 'SELLERPLATFORM',
                'name' => 'Unauthorized platform voucher',
                'discount_type' => 'FIXED',
                'discount_value' => 10,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('vouchers', ['code' => 'SELLERPLATFORM']);
    }

    public function test_admin_campaign_budget_increase_is_audited_and_commitments_cannot_be_reduced(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($admin)
            ->post(route('admin.marketing.campaigns.store'), [
                'code' => 'BUDGETAUDIT10',
                'name' => 'Budget audit campaign',
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'benefit_scope' => 'PRODUCT',
                'minimum_order_amount' => 0,
                'usage_limit' => 100,
                'per_user_limit' => 1,
                'campaign_budget_total' => 1000000,
                'campaign_budget_reason' => 'Initial test allocation',
                'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $voucher = Voucher::query()->where('code', 'BUDGETAUDIT10')->sole();
        $budgetService = app(PlatformCampaignBudgetService::class);
        $initial = FinancialLedgerEntry::query()
            ->where('voucher_id', $voucher->id)
            ->where('entry_type', 'CAMPAIGN_BUDGET_ALLOCATION')
            ->sole();
        $this->assertSame('1000000.00', $initial->amount);
        $this->assertSame('Initial test allocation', data_get($initial->metadata, 'reason'));
        $this->assertSame($admin->id, (int) $initial->actor_user_id);

        $this->actingAs($admin)
            ->patch(route('admin.marketing.campaigns.update', $voucher), [
                'code' => 'BUDGETAUDIT10',
                'name' => 'Budget audit campaign',
                'discount_type' => 'PERCENT',
                'discount_value' => 10,
                'benefit_scope' => 'PRODUCT',
                'minimum_order_amount' => 0,
                'usage_limit' => 100,
                'per_user_limit' => 1,
                'campaign_budget_total' => 1200000,
                'campaign_budget_reason' => 'Approved top-up for audit test',
                'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
                'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            ])
            ->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $voucher->refresh();
        $this->assertSame('1200000.00', $voucher->campaign_budget_total);
        $adjustment = FinancialLedgerEntry::query()
            ->where('voucher_id', $voucher->id)
            ->where('entry_type', 'CAMPAIGN_BUDGET_ALLOCATION')
            ->whereKeyNot($initial->id)
            ->sole();
        $this->assertSame('200000.00', $adjustment->amount);
        $this->assertSame('Approved top-up for audit test', data_get($adjustment->metadata, 'reason'));
        $this->assertSame('1000000.00', data_get($adjustment->metadata, 'previous_allocation'));
        $this->assertSame('1200000.00', data_get($adjustment->metadata, 'new_allocation'));
        $this->assertSame($admin->id, (int) $adjustment->actor_user_id);

        $this->actingAs($admin)
            ->get(route('admin.marketing', ['section' => 'campaigns']))
            ->assertOk()
            ->assertSee('₱1,200,000.00')
            ->assertSee('Approved top-up for audit test');

        FinancialLedgerEntry::query()->create([
            'voucher_id' => $voucher->id,
            'account_type' => 'CAMPAIGN_BUDGET',
            'entry_type' => 'CAMPAIGN_SUBSIDY_RESERVED',
            'direction' => 'DEBIT',
            'amount' => '250.00',
            'status' => 'RESERVED',
            'idempotency_key' => 'budget-audit-reservation-'.$voucher->id,
            'actor_user_id' => $admin->id,
            'posted_at' => now(),
            'metadata' => ['source' => 'PRODUCT_VOUCHER'],
        ]);

        $this->assertSame(119975000, $budgetService->summary($voucher)['available_cents']);
        try {
            $budgetService->assertAvailable($voucher, '1199750.01');
            $this->fail('A subsidy greater than the available balance must be rejected.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('platform_voucher_code', $exception->errors());
        }

        try {
            $budgetService->setAllocation($voucher, '249.99', 'Invalid reduction test', $admin);
            $this->fail('An allocation below the active reservation must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('below net spent plus active reservations', $exception->getMessage());
        }
        $this->assertSame('1200000.00', $voucher->fresh()->campaign_budget_total);

        $this->actingAs($seller)
            ->patch(route('admin.marketing.campaigns.update', $voucher), [
                'code' => 'BUDGETAUDIT10',
                'name' => 'Unauthorized edit',
            ])
            ->assertForbidden();
        $this->assertSame('1200000.00', $voucher->fresh()->campaign_budget_total);
    }
}
