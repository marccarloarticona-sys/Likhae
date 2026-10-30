<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\ProductOptionValue;
use App\Services\Marketplace\ProductCatalogService;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerProductCatalogUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_generates_cartesian_variants_when_rows_are_not_submitted(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Generated Combination Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red, Blue'],
                ['name' => 'Size', 'values' => 'Small, Large'],
            ],
            'price' => 250,
            'stock' => 8,
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Generated Combination Product')->sole();

        $this->assertSame(4, $product->variants()->count());
        $this->assertSame(2, $product->variants()->firstOrFail()->optionValues()->count());
        $this->assertSame('cod_online', $product->variants()->firstOrFail()->payment_method);
    }

    public function test_duplicate_product_copies_catalog_as_an_editable_draft_without_reusing_stock_or_images(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Safe Copy Source',
            'category_id' => $category->id,
            'description' => 'A durable handmade product for safe catalog duplication.',
            'listing_status' => 'active',
            'options' => [['name' => 'Color', 'values' => 'Red, Blue']],
            'variants' => [
                ['values' => 'Red', 'price' => 500, 'stock' => 7, 'payment_method' => 'online', 'is_active' => 1, 'product_image_ref' => 'upload:primary'],
                ['values' => 'Blue', 'price' => 400, 'stock' => 3, 'payment_method' => 'cod', 'is_active' => 1, 'product_image_ref' => 'upload:additional:0'],
            ],
            'image' => UploadedFile::fake()->image('copy-source-red.jpg'),
            'images' => [UploadedFile::fake()->image('copy-source-blue.jpg')],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'ACTIVE');

        $source = Product::query()->where('name', 'Safe Copy Source')->with(['images', 'options.values', 'variants.optionValues'])->sole();
        $sourceVariants = $source->variants->keyBy(fn (ProductVariant $variant): int => (int) $variant->price);
        $this->actingAs($seller)->patch(route('seller.marketing.product-discounts.update', [
            'product' => $source,
            'section' => 'product-discounts',
        ]), [
            'discounts_enabled' => 1,
            'variants' => [
                ['id' => $sourceVariants[500]->id, 'discount_type' => 'percentage', 'discount_value' => 10],
                ['id' => $sourceVariants[400]->id, 'discount_type' => 'fixed', 'discount_value' => 25],
            ],
        ])->assertRedirect();
        $sourceVariantIds = $source->variants->modelKeys();
        $sourceOptionValueIds = $source->options->flatMap(fn ($option) => $option->values)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $sourceFiles = Storage::disk('public')->allFiles('sellers/'.$seller->sellerProfile->id.'/products/'.$source->id);
        $this->assertCount(2, $sourceFiles);

        $this->actingAs($seller)
            ->post(route('seller.products.duplicate', $source))
            ->assertRedirect()
            ->assertSessionHas('status');

        $copy = Product::query()->whereKeyNot($source->id)->where('name', 'Safe Copy Source (Copy)')
            ->with(['images', 'options.values', 'variants.optionValues'])
            ->sole();
        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => $copy->id]))
            ->assertOk()
            ->assertSee('Safe Copy Source (Copy)');

        $this->assertSame('DRAFT', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertNotSame($source->id, $copy->id);
        $this->assertSame('Safe Copy Source', $source->fresh()->name);
        $this->assertSame('ACTIVE', $source->fresh()->status);
        $this->assertSame([7, 3], $source->fresh()->variants()->orderBy('id')->pluck('stock')->map(fn ($stock): int => (int) $stock)->all());
        $this->assertCount(2, $copy->variants);
        $this->assertSame([0, 0], $copy->variants->sortBy('price')->pluck('stock')->map(fn ($stock): int => (int) $stock)->values()->all());
        $this->assertCount(2, array_unique($copy->variants->pluck('sku')->all()));
        $this->assertNotEqualsCanonicalizing($source->variants->pluck('sku')->all(), $copy->variants->pluck('sku')->all());
        $this->assertEqualsCanonicalizing(['fixed', 'percentage'], $copy->variants->pluck('discount_type')->all());
        $this->assertEqualsCanonicalizing(['cod', 'online'], $copy->variants->pluck('payment_method')->all());
        $this->assertCount(2, $copy->options->flatMap->values);
        $copyOptionValueIds = $copy->options->flatMap(fn ($option) => $option->values)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertNotEqualsCanonicalizing($sourceOptionValueIds, $copyOptionValueIds);
        $this->assertNotEqualsCanonicalizing($sourceVariantIds, $copy->variants->modelKeys());
        $this->assertSame(2, $copy->images->count());

        $copyFiles = Storage::disk('public')->allFiles('sellers/'.$seller->sellerProfile->id.'/products/'.$copy->id);
        $this->assertCount(2, $copyFiles);
        $this->assertNotEqualsCanonicalizing($sourceFiles, $copyFiles);
        foreach ([...$sourceFiles, ...$copyFiles] as $path) {
            Storage::disk('public')->assertExists($path);
        }

        $this->assertDatabaseMissing('order_items', ['product_id' => $copy->id]);
    }

    public function test_variation_types_are_limited_to_three(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Too Many Variation Types',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red, Blue'],
                ['name' => 'Size', 'values' => 'Small, Large'],
                ['name' => 'Material', 'values' => 'Cotton, Linen'],
                ['name' => 'Pattern', 'values' => 'Plain, Striped'],
            ],
        ])->assertSessionHasErrors('options');

        $this->assertDatabaseMissing('products', ['name' => 'Too Many Variation Types']);
    }

    public function test_duplicate_values_in_different_option_types_keep_their_own_links(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Duplicate Value Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red'],
                ['name' => 'Size', 'values' => 'Red'],
            ],
            'variants' => [
                ['values' => 'Red, Red', 'price' => 100, 'stock' => 4],
            ],
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Duplicate Value Product')->sole();
        $variant = $product->variants()->sole();

        $this->assertSame(2, $variant->optionValues()->count());
        $this->assertCount(2, ProductOptionValue::query()->whereIn('id', $variant->optionValues->pluck('id'))->get()->pluck('product_option_id')->unique());
    }

    public function test_variant_discounts_payment_and_existing_product_images_are_saved(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Discounted Image Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [['name' => 'Color', 'values' => 'Red, Blue']],
            'variants' => [
                ['values' => 'Red', 'price' => 500, 'stock' => 7, 'payment_method' => 'online', 'is_active' => 1, 'product_image_ref' => 'upload:primary'],
                ['values' => 'Blue', 'price' => 400, 'stock' => 3, 'payment_method' => 'cod', 'is_active' => 1, 'product_image_ref' => 'upload:additional:0'],
            ],
            'image' => UploadedFile::fake()->image('red-product.jpg'),
            'images' => [UploadedFile::fake()->image('blue-product.jpg')],
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Discounted Image Product')->sole();
        $product->update(['status' => 'ACTIVE', 'published_at' => now()]);
        $red = $product->variants()->where('price', 500)->sole();
        $blue = $product->variants()->where('price', 400)->sole();
        $this->actingAs($seller)->patch(route('seller.marketing.product-discounts.update', [
            'product' => $product,
            'section' => 'product-discounts',
        ]), [
            'discounts_enabled' => 1,
            'variants' => [
                ['id' => $red->id, 'discount_type' => 'percentage', 'discount_value' => 10],
                ['id' => $blue->id, 'discount_type' => 'fixed', 'discount_value' => 25],
            ],
        ])->assertRedirect();
        $red = $red->fresh();
        $blue = $blue->fresh();

        $this->assertSame('online', $red->payment_method);
        $this->assertSame('percentage', $red->resolved_discount_type);
        $this->assertSame('fixed', $blue->resolved_discount_type);
        $this->assertSame(450.0, $red->final_price);
        $this->assertSame(375.0, $blue->final_price);
        $bluePayload = app(ProductCatalogService::class)->variantPayload($blue);
        $this->assertSame('fixed', $bluePayload['discount_type']);
        $this->assertSame('25.00', $bluePayload['discount_value']);
        $this->assertSame(2, $product->images()->count());
        $this->assertNotNull($red->product_image_id);
        $this->assertNotNull($blue->product_image_id);
        $this->assertNotSame($red->product_image_id, $blue->product_image_id);
        $this->assertCount(2, Storage::disk('public')->allFiles('sellers/'.$product->seller_profile_id.'/products/'.$product->id));
    }

    public function test_product_discount_percentage_and_fixed_values_are_validated_in_marketing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Validated Marketing Discount',
            'slug' => 'validated-marketing-discount',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'VALIDATED-MARKETING-DISCOUNT',
            'price' => 100,
            'stock' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->patch(route('seller.marketing.product-discounts.update', ['product' => $product]), [
            'discounts_enabled' => 1,
            'variants' => [['id' => $variant->id, 'discount_type' => 'percentage', 'discount_value' => 101]],
        ])->assertSessionHasErrors('variants.0.discount_value');

        $this->patch(route('seller.marketing.product-discounts.update', ['product' => $product]), [
            'discounts_enabled' => 1,
            'variants' => [['id' => $variant->id, 'discount_type' => 'fixed', 'discount_value' => 100]],
        ])->assertSessionHasErrors('variants.0.discount_value');

        $this->assertSame('none', $variant->fresh()->resolved_discount_type);
    }

    public function test_unknown_product_discount_type_is_rejected_by_marketing(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Unknown Marketing Discount Type',
            'slug' => 'unknown-marketing-discount-type',
            'status' => 'ACTIVE',
            'published_at' => now(),
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'UNKNOWN-MARKETING-DISCOUNT',
            'price' => 100,
            'stock' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->patch(route('seller.marketing.product-discounts.update', ['product' => $product]), [
            'discounts_enabled' => 1,
            'variants' => [['id' => $variant->id, 'discount_type' => 'arbitrary', 'discount_value' => 10]],
        ])->assertSessionHasErrors('variants.0.discount_type');

        $this->assertSame('none', $variant->fresh()->resolved_discount_type);
    }

    public function test_failed_product_update_does_not_delete_existing_image_files(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Image Rollback Product',
            'slug' => 'image-rollback-product',
            'status' => 'DRAFT',
        ]);
        $variant = $product->variants()->create([
            'sku' => 'IMAGE-ROLLBACK-OWN',
            'price' => 100,
            'stock' => 2,
            'is_default' => true,
            'is_active' => true,
        ]);
        $path = 'sellers/'.$seller->sellerProfile->id.'/products/'.$product->id.'/old.webp';
        Storage::disk('public')->put($path, 'old image');
        $image = $product->images()->create([
            'file_path' => $path,
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $otherProduct = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'SKU Conflict Product',
            'slug' => 'sku-conflict-product',
            'status' => 'DRAFT',
        ]);
        $otherProduct->variants()->create([
            'sku' => 'IMAGE-ROLLBACK-CONFLICT',
            'price' => 100,
            'stock' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => $product->name,
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 2,
            'sku' => 'IMAGE-ROLLBACK-CONFLICT',
            'single_variant_id' => $variant->id,
            'delete_image_ids' => [$image->id],
            'listing_status' => 'draft',
        ])->assertSessionHasErrors('variants');

        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('product_images', ['id' => $image->id, 'product_id' => $product->id]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Image Rollback Product']);
    }

    public function test_product_image_upload_rejects_spoofed_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $base = [
            'product_type' => 'single',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
        ];

        $this->actingAs($seller)->post(route('seller.products.store'), $base + [
            'name' => 'Spoofed Product Image',
            'image' => UploadedFile::fake()->createWithContent('spoofed.jpg', 'not an image')->mimeType('text/plain'),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['name' => 'Spoofed Product Image']);
    }

    public function test_product_image_upload_rejects_oversized_files(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
            'name' => 'Oversized Product Image',
            'image' => UploadedFile::fake()->image('oversized.png')->size(5121),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseMissing('products', ['name' => 'Oversized Product Image']);
    }

    public function test_seller_cannot_republish_an_admin_suspended_product(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Suspended Product Correction',
            'slug' => 'suspended-product-correction',
            'status' => 'SUSPENDED',
        ]);
        $variant = $product->variants()->create([
            'sku' => 'SUSPENDED-PRODUCT-SKU',
            'price' => 100,
            'stock' => 2,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => 'Suspended Product Correction Updated',
            'category_id' => $category->id,
            'description' => 'Corrected content still awaiting administrator review.',
            'image' => UploadedFile::fake()->image('suspended-correction.jpg'),
            'price' => 100,
            'stock' => 2,
            'sku' => $variant->sku,
            'single_variant_id' => $variant->id,
            'listing_status' => 'active',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'SUSPENDED');

        $this->assertSame('SUSPENDED', $product->fresh()->status);
        $this->assertSame('Suspended Product Correction Updated', $product->fresh()->name);
    }

    public function test_category_rules_allow_active_business_subcategories_and_preserve_only_existing_unavailable_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $businessCategory = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $activeSubcategory = Category::query()->create([
            'parent_id' => $businessCategory->id,
            'name' => 'Approved Active Child',
            'slug' => 'approved-active-child',
            'is_active' => true,
        ]);
        $inactiveSubcategory = Category::query()->create([
            'parent_id' => $businessCategory->id,
            'name' => 'Approved Inactive Child',
            'slug' => 'approved-inactive-child',
            'is_active' => false,
        ]);
        $archivedSubcategory = Category::query()->create([
            'parent_id' => $businessCategory->id,
            'name' => 'Approved Archived Child',
            'slug' => 'approved-archived-child',
            'is_active' => true,
            'archived_at' => now(),
        ]);
        $unrelatedCategory = Category::query()->create([
            'name' => 'Unrelated Active Category',
            'slug' => 'unrelated-active-category',
            'is_active' => true,
        ]);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Approved Child Product',
            'category_id' => $activeSubcategory->id,
            'seller_profile_id' => $otherSeller->sellerProfile->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
        ])->assertRedirect();

        $approvedProduct = Product::query()->where('name', 'Approved Child Product')->sole();
        $this->assertSame($seller->sellerProfile->id, (int) $approvedProduct->seller_profile_id);
        $this->assertSame($activeSubcategory->id, (int) $approvedProduct->category_id);

        foreach ([$inactiveSubcategory, $archivedSubcategory, $unrelatedCategory] as $index => $category) {
            $name = 'Rejected Category Product '.$index;
            $this->actingAs($seller)->post(route('seller.products.store'), [
                'product_type' => 'single',
                'name' => $name,
                'category_id' => $category->id,
                'price' => 100,
                'stock' => 1,
                'listing_status' => 'draft',
            ])->assertSessionHasErrors('category_id');
            $this->assertDatabaseMissing('products', ['name' => $name]);
        }

        $historicalProduct = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $archivedSubcategory->id,
            'name' => 'Historical Archived Category Product',
            'slug' => 'historical-archived-category-product',
            'status' => 'DRAFT',
        ]);
        $variant = $historicalProduct->variants()->create([
            'sku' => 'HISTORICAL-ARCHIVED-CATEGORY-SKU',
            'price' => 100,
            'stock' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($seller)->put(route('seller.products.update', $historicalProduct), [
            'product_type' => 'single',
            'name' => 'Historical Product Correction',
            'category_id' => $archivedSubcategory->id,
            'price' => 100,
            'stock' => 1,
            'sku' => $variant->sku,
            'single_variant_id' => $variant->id,
            'listing_status' => 'draft',
        ])->assertRedirect();

        $this->assertSame('Historical Product Correction', $historicalProduct->fresh()->name);
        $this->assertSame($archivedSubcategory->id, (int) $historicalProduct->fresh()->category_id);
    }

    public function test_duplicate_variant_combinations_are_rejected_atomically(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Duplicate Combination Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red, Blue'],
            ],
            'variants' => [
                ['sku' => 'DUPLICATE-COMBINATION-1', 'values' => 'Red', 'price' => 100, 'stock' => 1],
                ['sku' => 'DUPLICATE-COMBINATION-2', 'values' => 'Red', 'price' => 100, 'stock' => 1],
            ],
        ])->assertSessionHasErrors('variants');

        $this->assertDatabaseMissing('products', ['name' => 'Duplicate Combination Product']);
    }

    public function test_required_image_assignment_scenario_reloads_without_duplicate_files(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $variants = [];
        foreach (['Red' => 'upload:primary', 'Blue' => 'upload:additional:0', 'Green' => 'upload:additional:1'] as $color => $imageRef) {
            foreach (['S', 'M'] as $size) {
                $variants[] = [
                    'values' => $color.', '.$size,
                    'price' => 500,
                    'stock' => 10,
                    'product_image_ref' => $imageRef,
                ];
            }
        }

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Three Color Image Assignment Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red, Blue, Green'],
                ['name' => 'Size', 'values' => 'S, M'],
            ],
            'variants' => $variants,
            'image' => UploadedFile::fake()->image('red-shirt.jpg'),
            'images' => [
                UploadedFile::fake()->image('blue-shirt.jpg'),
                UploadedFile::fake()->image('green-shirt.jpg'),
            ],
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Three Color Image Assignment Product')->sole();
        $images = $product->images()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        $this->assertCount(3, $images);
        $this->assertCount(3, Storage::disk('public')->allFiles('sellers/'.$product->seller_profile_id.'/products/'.$product->id));

        $assignments = $product->variants()->with('optionValues.option')->get()->mapWithKeys(function ($variant) {
            $values = $variant->optionValues->sortBy(fn ($value) => $value->option?->sort_order ?? 0)->pluck('value')->join('/');
            return [$values => $variant->product_image_id];
        });

        $this->assertSame($images[0]->id, $assignments['Red/S']);
        $this->assertSame($images[0]->id, $assignments['Red/M']);
        $this->assertSame($images[1]->id, $assignments['Blue/S']);
        $this->assertSame($images[1]->id, $assignments['Blue/M']);
        $this->assertSame($images[2]->id, $assignments['Green/S']);
        $this->assertSame($images[2]->id, $assignments['Green/M']);

        $catalog = app(ProductCatalogService::class);
        $redPayload = $catalog->variantPayload($product->variants()->firstOrFail());
        $this->assertSame([$catalog->publicUrl($images[0]->file_path)], $redPayload['image_urls']->all());

        $imageIds = $product->images()->pluck('id')->all();
        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'variations',
            'name' => $product->name,
            'category_id' => $category->id,
            'listing_status' => 'draft',
            'options' => [
                ['name' => 'Color', 'values' => 'Red, Blue, Green'],
                ['name' => 'Size', 'values' => 'S, M'],
            ],
            'variants' => $product->variants()->with('optionValues.option')->get()->map(function ($variant) {
                $values = $variant->optionValues->sortBy(fn ($value) => $value->option?->sort_order ?? 0)->pluck('value')->join(', ');
                return [
                    'id' => $variant->id,
                    'values' => $values,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'stock' => $variant->stock,
                    'product_image_ref' => 'image:'.$variant->product_image_id,
                ];
            })->all(),
        ])->assertRedirect();

        $this->assertSame($imageIds, $product->fresh()->images()->pluck('id')->all());
        $this->assertCount(3, Storage::disk('public')->allFiles('sellers/'.$product->seller_profile_id.'/products/'.$product->id));
    }

    public function test_category_must_belong_to_the_sellers_approved_line_of_business(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $otherCategory = Category::query()->create([
            'name' => 'Restricted Category',
            'slug' => 'restricted-category',
            'is_active' => true,
        ]);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Restricted Product',
            'category_id' => $otherCategory->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
        ])->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('products', ['name' => 'Restricted Product']);
    }

    public function test_edit_without_new_files_preserves_existing_product_images(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Image Preservation Product',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'listing_status' => 'draft',
            'image' => UploadedFile::fake()->image('main-product.jpg'),
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Image Preservation Product')->sole();
        $imageCount = $product->images()->count();

        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => 'Image Preservation Product Updated',
            'category_id' => $category->id,
            'price' => 110,
            'stock' => 2,
            'listing_status' => 'draft',
            'single_variant_id' => $product->variants()->sole()->id,
        ])->assertRedirect();

        $this->assertSame($imageCount, $product->fresh()->images()->count());
    }
}
