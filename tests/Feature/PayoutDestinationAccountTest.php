<?php

namespace Tests\Feature;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Category;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayoutDestinationAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_save_an_encrypted_payout_destination_from_account_settings(): void
    {
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $destination = $this->destination('GCash', '09171234567');

        $this->actingAs($admin)
            ->get(route('admin.account', ['tab' => 'payout']))
            ->assertOk()
            ->assertSee('Admin payout details')
            ->assertSee('GCash');

        $this->actingAs($admin)
            ->put(route('admin.account.payout-destination.update'), $destination)
            ->assertRedirect();

        $savedAdmin = $admin->fresh();
        $this->assertSame('GCash', $savedAdmin->payout_method);
        $this->assertSame('Ada Admin', $savedAdmin->payout_account_name);
        $this->assertSame('09171234567', $savedAdmin->payout_account_number);
        $this->assertNotSame(
            '09171234567',
            DB::table('users')->where('id', $admin->id)->value('payout_account_number'),
            'Payout account numbers must be encrypted in the database.',
        );
        $this->assertArrayNotHasKey('payout_account_number', $savedAdmin->toArray());
    }

    public function test_seller_can_manage_payout_details_from_the_seller_account_page(): void
    {
        [$seller, $profile] = $this->seller();

        $this->actingAs($seller)
            ->get(route('seller.account', ['tab' => 'payouts']))
            ->assertOk()
            ->assertSee('Payout Details')
            ->assertSee('Save payout details');

        $this->actingAs($seller)
            ->put(route('seller.settlements.payout-destination.update'), $this->destination('Maya', '09181234567'))
            ->assertRedirect();

        $savedProfile = $profile->fresh();
        $this->assertSame('Maya', $savedProfile->payout_method);
        $this->assertSame('09181234567', $savedProfile->payout_account_number);
        $this->assertNotSame(
            '09181234567',
            DB::table('seller_profiles')->where('id', $profile->id)->value('payout_account_number'),
            'Seller payout account numbers must be encrypted in the database.',
        );
    }

    public function test_logistics_center_can_manage_payout_details_from_its_account_page(): void
    {
        [$owner, $center] = $this->logisticsCenter();

        $this->actingAs($owner)
            ->get(route('logistics.account'))
            ->assertOk()
            ->assertSee('Payout destination')
            ->assertSee('Save payout details');

        $this->actingAs($owner)
            ->put(route('logistics.wallet.payout-destination.update'), $this->destination('Bank Transfer', '1234567890') + [
                'payout_bank_name' => 'Test Bank',
            ])
            ->assertRedirect();

        $savedCenter = $center->fresh();
        $this->assertSame('Bank Transfer', $savedCenter->payout_method);
        $this->assertSame('Test Bank', $savedCenter->payout_bank_name);
        $this->assertSame('1234567890', $savedCenter->payout_account_number);
        $this->assertNotSame(
            '1234567890',
            DB::table('logistics_centers')->where('id', $center->id)->value('payout_account_number'),
            'Logistics payout account numbers must be encrypted in the database.',
        );
    }

    public function test_card_numbers_are_not_accepted_as_payout_destination_data(): void
    {
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put(route('admin.account.payout-destination.update'), $this->destination('Card', '4111111111111111') + [
                'cvv' => '123',
            ])
            ->assertSessionHasErrors('payout_method');

        $this->assertNull($admin->fresh()->payout_method);
        $this->assertNull($admin->fresh()->payout_account_number);
    }

    /** @return array<string, string> */
    private function destination(string $method, string $number): array
    {
        return [
            'payout_method' => $method,
            'payout_account_name' => 'Ada Admin',
            'payout_account_type' => in_array($method, ['GCash', 'Maya'], true) ? 'MOBILE_NUMBER' : 'ACCOUNT_NUMBER',
            'payout_account_number' => $number,
        ];
    }

    /** @return array{User, SellerProfile} */
    private function seller(): array
    {
        $seller = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $category = Category::create([
            'name' => 'Payout Test Category',
            'slug' => 'payout-test-category',
            'is_active' => true,
        ]);
        $profile = SellerProfile::create([
            'user_id' => $seller->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Payout Test Shop',
            'status' => 'ACTIVE',
        ]);

        return [$seller, $profile];
    }

    /** @return array{User, LogisticsCenter} */
    private function logisticsCenter(): array
    {
        $owner = User::factory()->create([
            'account_type' => User::TYPE_LOGISTICS,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $center = LogisticsCenter::create([
            'owner_user_id' => $owner->id,
            'code' => 'PAYOUT-CENTER-'.str()->random(8),
            'business_name' => 'Payout Test Logistics',
            'status' => 'ACTIVE',
            'approved_by_user_id' => $owner->id,
            'approved_at' => now(),
        ]);

        return [$owner, $center];
    }
}
