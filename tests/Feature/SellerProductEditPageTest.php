<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\Category;
use App\Models\Seller\ProductOptionValue;
use App\Models\User;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Seller\SellerOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SellerProductEditPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_open_the_product_edit_page(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $product = Product::query()->whereBelongsTo($seller->sellerProfile)->sole();

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => $product->id]))
            ->assertOk()
            ->assertSee(route('seller.products.update', $product), escape: false)
            ->assertSee('data-listing-status-field', escape: false)
            ->assertSee("this.form.elements.listing_status.value='active'", escape: false);
    }

    public function test_seller_cannot_edit_a_missing_product(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();

        $this->actingAs($seller)
            ->get(route('seller.products', ['mode' => 'edit', 'product' => 999999]))
            ->assertNotFound();
    }

    public function test_seller_can_save_a_single_product_as_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Single Database Product',
            'category_id' => $category->id,
            'description' => 'Stored using the existing catalog schema.',
            'price' => 599,
            'stock' => 30,
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $product = Product::query()->where('name', 'Single Database Product')->sole();
        $this->assertSame('DRAFT', $product->status);
        $this->assertSame(1, $product->variants()->count());
    }

    public function test_seller_can_publish_a_product_with_variations(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'variations',
            'name' => 'Variant Database Product',
            'category_id' => $category->id,
            'description' => 'A handmade item available in multiple sizes.',
            'image' => UploadedFile::fake()->image('variant-product.jpg'),
            'listing_status' => 'active',
            'options' => [['name' => 'Size', 'values' => 'Small, Large']],
            'variants' => [
                ['values' => 'Small', 'price' => 399, 'stock' => 15, 'is_active' => 1],
                ['values' => 'Large', 'price' => 599, 'stock' => 10, 'is_active' => 1],
            ],
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'ACTIVE');

        $product = Product::query()->where('name', 'Variant Database Product')->sole();
        $this->assertSame('ACTIVE', $product->status);
        $this->assertSame(2, $product->variants()->where('is_active', true)->count());
        $this->assertSame(2, $product->options()->firstOrFail()->values()->count());
    }

    public function test_seller_can_save_an_incomplete_product_as_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);

        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Incomplete Draft Product',
            'category_id' => $category->id,
            'listing_status' => 'draft',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'DRAFT');

        $product = Product::query()->where('name', 'Incomplete Draft Product')->sole();
        $this->assertSame('DRAFT', $product->status);
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_incomplete_draft_cannot_be_published_and_remains_a_draft(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Draft Awaiting Completion',
            'category_id' => $category->id,
            'listing_status' => 'draft',
        ])->assertRedirect();
        $product = Product::query()->where('name', 'Draft Awaiting Completion')->sole();

        $this->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => 'Draft Awaiting Completion',
            'category_id' => $category->id,
            'description' => 'A description entered before publication.',
            'price' => 250,
            'stock' => 3,
            'listing_status' => 'active',
        ])->assertRedirect()->assertSessionHasErrors('image');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'DRAFT']);
    }

    public function test_seller_can_complete_and_publish_a_draft(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $this->actingAs($seller)->post(route('seller.products.store'), [
            'product_type' => 'single',
            'name' => 'Draft Ready to Publish',
            'category_id' => $category->id,
            'listing_status' => 'draft',
        ])->assertRedirect();
        $product = Product::query()->where('name', 'Draft Ready to Publish')->sole();

        $this->put(route('seller.products.update', $product), [
            'product_type' => 'single',
            'name' => 'Draft Ready to Publish',
            'category_id' => $category->id,
            'description' => 'A complete listing for buyers to review.',
            'price' => 250,
            'stock' => 3,
            'image' => UploadedFile::fake()->image('ready-product.jpg'),
            'listing_status' => 'active',
        ])->assertRedirect()->assertSessionHas('product_saved.status', 'ACTIVE');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('product_images', ['product_id' => $product->id, 'is_primary' => 1]);
    }

    public function test_repeated_product_submission_with_same_key_does_not_duplicate_product_or_variant(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $payload = [
            'product_type' => 'single',
            'name' => 'Idempotent Product Draft',
            'category_id' => $category->id,
            'price' => 125,
            'stock' => 4,
            'listing_status' => 'draft',
            'idempotency_key' => 'e6823b57-6972-4a5f-b03c-a12d0d4dfe78',
        ];

        $this->actingAs($seller)->post(route('seller.products.store'), $payload)->assertRedirect();
        $this->actingAs($seller)->post(route('seller.products.store'), $payload)->assertRedirect();

        $product = Product::query()->where('name', 'Idempotent Product Draft')->sole();
        $this->assertSame(1, Product::query()->where('name', 'Idempotent Product Draft')->count());
        $this->assertSame(1, $product->variants()->count());
        $this->assertSame('DRAFT', $product->status);
    }

    public function test_seller_cannot_soft_delete_a_product_with_an_unfinished_order(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->whereBelongsTo($seller->sellerProfile)->sole();
        $order = Order::query()->create([
            'order_number' => 'PRODUCT-DELETE-PROTECTION-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 350,
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'PRODUCT-DELETE-PROTECTION-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->sellerProfile->id,
            'status' => 'PLACED',
            'grand_total' => 350,
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => 'PRODUCT-DELETE-PROTECTION-SKU',
            'quantity' => 1,
            'unit_price' => 350,
            'line_total' => 350,
        ]);

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $product))
            ->assertSessionHasErrors('product');

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_seller_can_soft_delete_and_restore_an_unreferenced_product(): void
    {
        $this->withoutVite();
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $category = Category::query()->findOrFail($seller->sellerProfile->primary_category_id);
        $product = Product::query()->create([
            'seller_profile_id' => $seller->sellerProfile->id,
            'category_id' => $category->id,
            'name' => 'Unreferenced Recovery Product',
            'slug' => 'unreferenced-recovery-product',
            'status' => 'DRAFT',
        ]);

        $this->actingAs($seller)
            ->delete(route('seller.products.destroy', $product))
            ->assertRedirect(route('seller.products'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->actingAs($seller)->get(route('seller.products.trash'))->assertOk()->assertSee($product->name);
        $this->post(route('seller.products.restore', $product->id))->assertRedirect();
        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_seller_cannot_remove_a_variant_referenced_by_an_unfinished_order(): void
    {
        $this->seed(DatabaseSeeder::class);
        $seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $product = Product::query()->where('slug', 'sample-handmade-tote')->sole();
        $protectedVariant = $product->variants()->where('sku', 'TOTE-BLK-L')->sole();
        $sizeOption = $product->options()->where('name', 'Size')->firstOrFail();
        $sizeSmall = ProductOptionValue::query()->firstOrCreate(
            ['product_option_id' => $sizeOption->id, 'value' => 'Small'],
            ['sort_order' => 2],
        );
        $black = $product->options()->where('name', 'Color')->firstOrFail()->values()->where('value', 'Black')->firstOrFail();
        $retainedVariant = $product->variants()->create([
            'sku' => 'TOTE-BLK-S-KEEP',
            'price' => 350,
            'stock' => 4,
            'payment_method' => 'cod_online',
            'is_default' => false,
            'is_active' => true,
        ]);
        $retainedVariant->optionValues()->sync([$black->id, $sizeSmall->id]);

        $order = Order::query()->create([
            'order_number' => 'VARIANT-PROTECTION-ORDER',
            'buyer_user_id' => $buyer->id,
            'status' => 'PLACED',
            'grand_total' => 350,
            'placed_at' => now(),
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'VARIANT-PROTECTION-SELLER-ORDER',
            'order_id' => $order->id,
            'seller_profile_id' => $seller->sellerProfile->id,
            'status' => 'PLACED',
            'grand_total' => 350,
        ]);
        OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $protectedVariant->id,
            'product_name' => $product->name,
            'sku' => $protectedVariant->sku,
            'quantity' => 1,
            'unit_price' => 350,
            'line_total' => 350,
        ]);

        $options = $product->options()->with('values')->orderBy('sort_order')->get()
            ->map(fn ($option): array => [
                'name' => $option->name,
                'values' => $option->values->sortBy('sort_order')->pluck('value')->implode(', '),
            ])->all();

        $this->actingAs($seller)->put(route('seller.products.update', $product), [
            'product_type' => 'variations',
            'name' => $product->name,
            'category_id' => $product->category_id,
            'description' => $product->description,
            'listing_status' => 'draft',
            'options' => $options,
            'variants' => [[
                'id' => $retainedVariant->id,
                'sku' => $retainedVariant->sku,
                'values' => 'Black,Small',
                'price' => 350,
                'stock' => 4,
                'is_active' => true,
            ]],
        ])->assertRedirect()->assertSessionHasErrors('variants');

        $this->assertDatabaseHas('product_variants', [
            'id' => $protectedVariant->id,
            'product_id' => $product->id,
            'is_active' => true,
        ]);
    }
}
