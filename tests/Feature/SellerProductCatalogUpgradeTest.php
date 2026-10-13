<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
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
                ['values' => 'Red', 'price' => 500, 'stock' => 7, 'discount_type' => 'percentage', 'discount_value' => 10, 'payment_method' => 'online', 'is_active' => 1, 'product_image_ref' => 'upload:primary'],
                ['values' => 'Blue', 'price' => 400, 'stock' => 3, 'discount_type' => 'fixed', 'discount_value' => 25, 'payment_method' => 'cod', 'is_active' => 1, 'product_image_ref' => 'upload:additional:0'],
            ],
            'image' => UploadedFile::fake()->image('red-product.jpg'),
            'images' => [UploadedFile::fake()->image('blue-product.jpg')],
        ])->assertRedirect();

        $product = Product::query()->where('name', 'Discounted Image Product')->sole();
        $red = $product->variants()->where('discount_type', 'percentage')->sole();
        $blue = $product->variants()->where('discount_type', 'fixed')->sole();

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

    public function test_discount_mode_limits_are_validated_server_side(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Invalid Percentage Discount',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 101,
            'listing_status' => 'draft',
        ])->assertSessionHasErrors('discount_value');

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Invalid Fixed Discount',
            'category_id' => $category->id,
            'price' => 100,
            'stock' => 1,
            'discount_type' => 'fixed',
            'discount_value' => 101,
            'listing_status' => 'draft',
        ])->assertSessionHasErrors('discount_value');

        $this->assertDatabaseMissing('products', ['name' => 'Invalid Percentage Discount']);
        $this->assertDatabaseMissing('products', ['name' => 'Invalid Fixed Discount']);
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
