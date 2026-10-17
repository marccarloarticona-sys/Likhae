<?php

namespace Tests\Feature;

use App\Models\Admin\SellerComplianceCase;
use App\Models\Admin\SellerComplianceResponse;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerCompliancePageRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
    }

    public function test_seller_compliance_case_two_renders_and_missing_private_evidence_is_safe(): void
    {
        $this->makeCase($this->seller->sellerProfile, 'B6-CASE-ONE');
        $case = $this->makeCase($this->seller->sellerProfile, 'B6-CASE-TWO');
        Storage::fake('local');
        SellerComplianceResponse::query()->create([
            'seller_compliance_case_id' => $case->id,
            'seller_profile_id' => $this->seller->sellerProfile->id,
            'explanation' => 'The listing details were corrected for review.',
            'corrective_action' => 'Updated the product description.',
            'evidence_path' => 'compliance/missing/evidence.pdf',
            'evidence_original_name' => 'evidence.pdf',
            'evidence_mime' => 'application/pdf',
            'evidence_size' => 128,
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.compliance.show', ['case' => 2]))
            ->assertOk()
            ->assertSee('B6-CASE-TWO')
            ->assertSee('Evidence file unavailable.')
            ->assertDontSee('compliance/missing/evidence.pdf');
    }

    public function test_seller_case_access_is_scoped_and_admin_compliance_page_remains_available(): void
    {
        $case = $this->makeCase($this->seller->sellerProfile, 'B6-OWN-CASE');
        $foreignProfile = $this->createForeignSellerProfile();
        $foreignCase = $this->makeCase($foreignProfile, 'B6-FOREIGN-CASE');
        $foreignResponse = SellerComplianceResponse::query()->create([
            'seller_compliance_case_id' => $foreignCase->id,
            'seller_profile_id' => $foreignProfile->id,
            'explanation' => 'The foreign seller submitted an explanation.',
            'corrective_action' => 'The foreign seller updated the listing.',
            'evidence_path' => 'compliance/private/foreign.pdf',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->seller)
            ->get(route('seller.compliance.show', $foreignCase))
            ->assertForbidden();
        $this->get(route('seller.compliance.responses.evidence', [$foreignCase, $foreignResponse]))
            ->assertForbidden();
        $this->get(route('seller.compliance.show', ['case' => 999999]))->assertNotFound();

        $buyer = User::query()->where('account_type', User::TYPE_BUYER)->firstOrFail();
        $this->actingAs($buyer)->get(route('seller.compliance.show', $case))->assertForbidden();

        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $this->actingAs($admin)
            ->get(route('admin.compliance.show', $case))
            ->assertOk()
            ->assertSee('B6-OWN-CASE');
    }

    private function makeCase(SellerProfile $seller, string $caseNumber): SellerComplianceCase
    {
        return SellerComplianceCase::query()->create([
            'case_number' => $caseNumber,
            'seller_profile_id' => $seller->id,
            'product_id' => null,
            'violation_type' => 'LISTING_INFORMATION',
            'description' => 'Review the seller listing information.',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);
    }

    private function createForeignSellerProfile(): SellerProfile
    {
        $user = User::factory()->create(['account_type' => User::TYPE_SELLER]);

        return SellerProfile::query()->create([
            'user_id' => $user->id,
            'primary_category_id' => $this->seller->sellerProfile->primary_category_id,
            'business_name' => 'Foreign Compliance Test Shop',
            'status' => 'ACTIVE',
        ]);
    }
}
