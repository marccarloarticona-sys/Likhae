<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMandatoryPolicyAcceptance;
use App\Models\Admin\PlatformSetting;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Moderation\ProductModerationImagePreprocessor;
use App\Services\Moderation\ProductModerationProvider;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductModerationAlignmentScanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_scan_persists_alignment_evidence_and_admin_can_review_it_without_automatically_holding_product(): void
    {
        Queue::fake();
        $this->withoutMiddleware(RequireMandatoryPolicyAcceptance::class);
        PlatformSetting::put('product_moderation_enabled', true, 'boolean');
        $business = Category::create(['name' => "Men's Apparel", 'slug' => Str::uuid(), 'is_active' => true]);
        $category = Category::create(['name' => 'Activewear', 'slug' => Str::uuid(), 'is_active' => true, 'parent_id' => $business->id]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $seller = SellerProfile::create(['user_id' => $sellerUser->id, 'primary_category_id' => $business->id, 'business_name' => 'Alignment '.Str::random(10), 'status' => 'ACTIVE']);
        $product = Product::create(['seller_profile_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Test listing', 'slug' => Str::uuid(), 'status' => 'ACTIVE']);
        $product->images()->create(['file_path' => 'alignment-test-missing-image.jpg', 'is_primary' => true]);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);

        $this->mock(ProductModerationProvider::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('providerLabel')->andReturn('Test provider');
            $mock->shouldReceive('primaryModel')->andReturn('Test model');
            $mock->shouldReceive('objectModel')->andReturn('Test detector');
            $mock->shouldReceive('classifyText')->once()->andReturn(['available' => true, 'category_probabilities' => ['SAFE' => 1], 'business_mismatch_probability' => 0.01, 'subcategory_mismatch_probability' => 0.8]);
            $mock->shouldReceive('classifyImages')->once()->andReturn(['available' => true, 'category_probabilities' => ['ORDINARY_PRODUCT' => 1], 'business_mismatch_probability' => 0.01, 'subcategory_mismatch_probability' => 0.9]);
            $mock->shouldReceive('detectObjects')->once()->andReturn([]);
        });
        $this->mock(ProductModerationImagePreprocessor::class, function ($mock): void {
            $mock->shouldReceive('prepare')->once()->andReturn(['image_id' => 1, 'sha256' => 'test-hash', 'mime_type' => 'image/jpeg', 'bytes' => 'test-image']);
        });
        $service = app(ProductModerationService::class);
        $scan = $service->queueManual($product, $admin);
        $service->processScan($scan->id);
        $scan->refresh();

        $this->assertSame('COMPLETE', $scan->scan_status, (string) $scan->failure_code);
        $this->assertSame('MEDIUM', $scan->overall_risk);
        $this->assertSame('PENDING', $scan->decision_status);
        $this->assertSame('ACTIVE', $product->fresh()->status);
        $this->assertNull($scan->held_at);
        $this->assertSame(0.8, $scan->text_findings['subcategory_mismatch_probability']);
        $this->assertSame('NO_MISMATCH_SIGNAL', data_get($scan->model_results, 'alignment.checks.business_mismatch_probability.status'));
        $this->assertNull(data_get($scan->model_results, 'alignment.checks.category_mismatch_probability'));
        $this->assertSame('POSSIBLE_MISMATCH', data_get($scan->model_results, 'alignment.checks.subcategory_mismatch_probability.status'));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'reference_type' => \App\Models\Admin\ProductModerationScan::class,
            'reference_id' => $scan->id,
            'title' => 'Product category alignment needs review',
        ]);

        $this->actingAs($admin)->get(route('admin.products.show', $product))
            ->assertOk()->assertSee('Product-to-category fit (AI assessment)')
            ->assertSee('Activewear')->assertSee('Product content vs selected subcategory')
            ->assertDontSee('Selected category path check')->assertDontSee('Product content vs selected category')
            ->assertSee('90.0% mismatch score')
            ->assertSee('Save human decision');
    }

    public function test_manual_rescan_cooldown_tells_admin_about_how_long_remains(): void
    {
        $this->withoutMiddleware(RequireMandatoryPolicyAcceptance::class);
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN, 'status' => User::STATUS_ACTIVE]);
        $business = Category::create(['name' => 'Cooldown Business', 'slug' => Str::uuid(), 'is_active' => true]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $seller = SellerProfile::create(['user_id' => $sellerUser->id, 'primary_category_id' => $business->id, 'business_name' => 'Cooldown '.Str::random(10), 'status' => 'ACTIVE']);
        $product = Product::create(['seller_profile_id' => $seller->id, 'category_id' => $business->id, 'name' => 'Cooldown test listing', 'slug' => Str::uuid(), 'status' => 'ACTIVE']);
        $key = 'product-moderation-manual-rescan:'.$product->id;
        Cache::put($key, now()->subMinutes(9)->timestamp, now()->addMinute());

        $this->actingAs($admin)->from(route('admin.products.show', $product))
            ->post(route('admin.products.ai.rescan', $product))
            ->assertRedirect(route('admin.products.show', $product))
            ->assertSessionHasErrors(['moderation' => 'Manual rescan cooldown is active. Try again in about 1 minute.']);
    }
}
