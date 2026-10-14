<?php

namespace Tests\Feature;

use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOption;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\ProductCatalogService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SellerVariantInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;

    private User $buyer;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->seller = User::query()->where('email', 'seller@likhae.com')->sole();
        $this->buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $this->categoryId = (int) $this->seller->sellerProfile->primary_category_id;
    }

    public function test_inventory_lists_each_active_sku_and_calculates_variant_level_stats(): void
    {
        $product = $this->product('Inventory Shorts');
        $primaryImage = ProductImage::query()->create([
            'product_id' => $product->id,
            'file_path' => 'inventory/primary.jpg',
            'is_primary' => true,
        ]);
        $variantImage = ProductImage::query()->create([
            'product_id' => $product->id,
            'file_path' => 'inventory/black-large.jpg',
            'is_primary' => false,
        ]);
        $color = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Color', 'sort_order' => 1]);
        $size = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Size', 'sort_order' => 2]);
        $sizeValues = collect(['S', 'M', 'L', 'XL', 'XXL'])->mapWithKeys(function (string $sizeName, int $sizeIndex) use ($size): array {
            return [$sizeName => ProductOptionValue::query()->create([
                'product_option_id' => $size->id,
                'value' => $sizeName,
                'sort_order' => $sizeIndex + 1,
            ])];
        });
        $stocks = [0, 6, 2, 3, 4, 0, 5, 5, 5, 6];
        $index = 0;

        foreach (['Black', 'White'] as $colorIndex => $colorName) {
            $colorValue = ProductOptionValue::query()->create([
                'product_option_id' => $color->id,
                'value' => $colorName,
                'sort_order' => $colorIndex + 1,
            ]);

            foreach (['S', 'M', 'L', 'XL', 'XXL'] as $sizeName) {
                $sizeValue = $sizeValues[$sizeName];
                $variant = $this->variant($product, 'INV-SHORTS-'.($index + 1), $stocks[$index]);
                $variant->optionValues()->attach([$colorValue->id, $sizeValue->id]);
                if ($colorName === 'Black' && $sizeName === 'L') {
                    $variant->update(['product_image_id' => $variantImage->id]);
                }
                $index++;
            }
        }

        $inactive = $this->variant($product, 'INV-SHORTS-INACTIVE', 99, false);
        $sellerVariants = ProductVariant::query()
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $this->seller->sellerProfile->id))
            ->where('is_active', true)
            ->get();

        $response = $this->actingAs($this->seller)->get(route('seller.products', ['mode' => 'inventory']));
        $response->assertOk()
            ->assertSee('Black / L')
            ->assertSee('INV-SHORTS-1')
            ->assertDontSee($inactive->sku)
            ->assertSee('data-inventory-product', false)
            ->assertSee('data-inventory-variant', false)
            ->assertSee('data-inventory-toggle aria-expanded="false"', false)
            ->assertSee('10 active variants · 36 total units')
            ->assertSee('8</strong> Low Stock', false)
            ->assertSee('2</strong> Out of Stock', false)
            ->assertSee('0</strong> Healthy', false)
            ->assertSee('aria-label="Total SKUs: '.$sellerVariants->count().'"', false)
            ->assertSee('aria-label="In Stock: '.$sellerVariants->where('stock', '>', 0)->count().'"', false)
            ->assertSee('aria-label="Low Stock (1–10): '.$sellerVariants->where('stock', '>', 0)->where('stock', '<=', 10)->count().'"', false)
            ->assertSee('aria-label="Out of Stock: '.$sellerVariants->where('stock', 0)->count().'"', false)
            ->assertSee('data-product-status="out"', false)
            ->assertSee('data-product-status="low"', false)
            ->assertSee('data-product-status="healthy"', false);

        $catalog = app(ProductCatalogService::class);
        $response->assertSee($catalog->publicUrl($primaryImage->file_path), false)
            ->assertDontSee($catalog->publicUrl($variantImage->file_path), false);
        $this->assertSame(1, substr_count($response->getContent(), $catalog->publicUrl($primaryImage->file_path)));
        $this->assertSame(1, substr_count($response->getContent(), 'class="sl-inventory-product" data-inventory-product data-product-id="'.$product->id.'"'));
        $this->assertSame($sellerVariants->count(), substr_count($response->getContent(), 'data-inventory-variant data-product-status='));

        $this->actingAs($this->seller)
            ->get(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id]))
            ->assertOk()
            ->assertSee('data-inventory-toggle aria-expanded="true"', false);

    }

    public function test_variant_stock_update_changes_only_the_selected_sku_and_names_it_in_feedback(): void
    {
        [$product, $small, $large] = $this->blackSizeVariants();

        $this->actingAs($this->seller)
            ->from(route('seller.products', ['mode' => 'inventory']))
            ->patch(route('seller.products.variants.stock', ['product' => $product->id, 'variant' => $large->id]), ['stock' => 12])
            ->assertRedirect(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id]))
            ->assertSessionHas('status', 'Stock updated for Color: Black / Size: L.');

        $this->assertDatabaseHas('product_variants', ['id' => $small->id, 'stock' => 5]);
        $this->assertDatabaseHas('product_variants', ['id' => $large->id, 'stock' => 12]);
    }

    public function test_variant_update_rejects_another_sellers_product_and_a_variant_from_an_unrelated_product(): void
    {
        $otherSeller = User::query()->where('email', 'backstreetclub@likhae.online')->sole();
        $otherProduct = $this->product('Other Seller Product', $otherSeller);
        $otherVariant = $this->variant($otherProduct, 'INV-OTHER-SELLER', 4);

        $this->actingAs($this->seller)
            ->patch(route('seller.products.variants.stock', ['product' => $otherProduct->id, 'variant' => $otherVariant->id]), ['stock' => 20])
            ->assertForbidden();

        $ownProduct = $this->product('Own Parent Product');
        $this->actingAs($this->seller)
            ->patch(route('seller.products.variants.stock', ['product' => $ownProduct->id, 'variant' => $otherVariant->id]), ['stock' => 20])
            ->assertNotFound();

        $this->assertDatabaseHas('product_variants', ['id' => $otherVariant->id, 'stock' => 4]);
    }

    public function test_invalid_stock_is_rejected_without_changing_stock_or_leaving_inventory(): void
    {
        $product = $this->product('Validated Stock Product');
        $variant = $this->variant($product, 'INV-VALIDATION', 8);
        $inventoryUrl = route('seller.products', ['mode' => 'inventory']);

        foreach ([-1, '1.5', 'not-a-number', 1000000] as $invalidStock) {
            $this->actingAs($this->seller)
                ->from($inventoryUrl)
                ->followingRedirects()
                ->patch(route('seller.products.variants.stock', ['product' => $product->id, 'variant' => $variant->id]), ['stock' => $invalidStock])
                ->assertOk()
                ->assertSee('Stock was not updated.');

            $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock' => 8]);
        }

    }

    public function test_zero_stock_is_allowed_and_status_thresholds_are_per_variant(): void
    {
        $product = $this->product('Threshold Product');
        $option = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Label', 'sort_order' => 1]);
        $variants = [];
        foreach ([['Out', 0], ['One', 1], ['Ten', 10], ['Eleven', 11]] as [$label, $stock]) {
            $value = ProductOptionValue::query()->create([
                'product_option_id' => $option->id,
                'value' => $label,
                'sort_order' => count($variants) + 1,
            ]);
            $variant = $this->variant($product, 'INV-THRESHOLD-'.$label, $stock);
            $variant->optionValues()->attach($value->id);
            $variants[$label] = $variant;
        }

        $inventoryUrl = route('seller.products', ['mode' => 'inventory']);
        $this->actingAs($this->seller)->get($inventoryUrl)
            ->assertOk()
            ->assertSee('data-product-name="threshold product"', false)
            ->assertSee('data-variant-search="out inv-threshold-out"', false)
            ->assertSee('data-product-status="out"', false)
            ->assertSee('data-variant-search="one inv-threshold-one"', false)
            ->assertSee('data-product-status="low"', false)
            ->assertSee('data-variant-search="ten inv-threshold-ten"', false)
            ->assertSee('data-variant-search="eleven inv-threshold-eleven"', false)
            ->assertSee('data-product-status="healthy"', false);

        $this->actingAs($this->seller)
            ->get(route('seller.products.inventory.preview', ['search' => 'INV-THRESHOLD-Ten', 'status' => 'low']))
            ->assertOk()
            ->assertSee('INV-THRESHOLD-Ten')
            ->assertSee('Low Stock')
            ->assertDontSee('INV-THRESHOLD-Eleven');

        $this->actingAs($this->seller)
            ->from($inventoryUrl)
            ->patch(route('seller.products.variants.stock', ['product' => $product->id, 'variant' => $variants['One']->id]), ['stock' => 0])
            ->assertRedirect(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id]));

        $this->assertDatabaseHas('product_variants', ['id' => $variants['One']->id, 'stock' => 0]);
    }

    public function test_search_text_contains_sku_and_variant_and_default_variant_remains_editable(): void
    {
        [, , $large] = $this->blackSizeVariants();
        $singleProduct = $this->product('Handmade Wallet');
        $singleVariant = $this->variant($singleProduct, 'INV-WALLET-DEFAULT', 14);

        $inventoryUrl = route('seller.products', ['mode' => 'inventory']);
        $this->actingAs($this->seller)->get($inventoryUrl)
            ->assertOk()
            ->assertSee('data-product-name="black shorts"', false)
            ->assertSee('data-variant-search="black / s inv-black-s"', false)
            ->assertSee('data-variant-search="black / l inv-black-l"', false)
            ->assertSee('Standard')
            ->assertSee($singleVariant->sku);

        $this->actingAs($this->seller)
            ->from($inventoryUrl)
            ->patch(route('seller.products.variants.stock', ['product' => $singleProduct->id, 'variant' => $singleVariant->id]), ['stock' => 12])
            ->assertRedirect(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $singleProduct->id]))
            ->assertSessionHas('status', 'Stock updated for Standard.');

        $this->assertDatabaseHas('product_variants', ['id' => $singleVariant->id, 'stock' => 12]);
        $this->assertDatabaseHas('product_variants', ['id' => $large->id, 'stock' => 3]);
    }

    public function test_inventory_uses_pdf_preview_and_product_catalog_csv_export_stays_available(): void
    {
        $this->actingAs($this->seller)
            ->get(route('seller.products', ['mode' => 'inventory']))
            ->assertOk()
            ->assertSee('Preview PDF')
            ->assertDontSee('Export Inventory CSV')
            ->assertSee(route('seller.products.inventory.preview'), false);

        $productCatalogCsv = $this->actingAs($this->seller)->get(route('seller.products.export'));
        $productCatalogCsv->assertOk();
        $catalogRows = array_map('str_getcsv', preg_split('/\R+/', trim($productCatalogCsv->streamedContent())) ?: []);
        $this->assertSame(['ID', 'Product', 'Category', 'Minimum Price', 'Total Stock', 'Status'], $catalogRows[0]);
    }

    public function test_filtered_inventory_preview_is_html_and_download_generates_grouped_pdf(): void
    {
        [$product, , $large] = $this->blackSizeVariants();
        $this->variant($product, 'INV-BLACK-OUT', 0);
        $this->variant($product, 'INV-BLACK-HEALTHY', 11);
        $filters = ['search' => $large->sku, 'status' => 'low'];

        $this->actingAs($this->seller)
            ->get(route('seller.products.inventory.preview', $filters))
            ->assertOk()
            ->assertSee('Inventory Report Preview')
            ->assertSee('Applied Search')
            ->assertSee($large->sku)
            ->assertSee('Applied Stock Filter')
            ->assertSee('Low Stock (1–10)')
            ->assertDontSee('INV-BLACK-OUT')
            ->assertDontSee('INV-BLACK-HEALTHY')
            ->assertDontSee('%PDF-1.4');

        $download = $this->actingAs($this->seller)
            ->get(route('seller.products.inventory.download', $filters));
        $download->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-1.4', $download->getContent());
        $this->assertStringContainsString('Inventory Report', $download->getContent());
        $this->assertStringContainsString('INV-BLACK-L', $download->getContent());
        $this->assertStringNotContainsString('INV-BLACK-OUT', $download->getContent());
        $this->assertStringNotContainsString('INV-BLACK-HEALTHY', $download->getContent());
        $this->assertStringContainsString('%%EOF', $download->getContent());
    }

    public function test_updating_black_medium_changes_only_that_variant_and_product_total(): void
    {
        [$product, $variants, $blackMedium] = $this->tenVariantProduct();
        $this->assertSame(36, $variants->sum('stock'));

        $this->actingAs($this->seller)
            ->patch(route('seller.products.variants.stock', ['product' => $product->id, 'variant' => $blackMedium->id]), ['stock' => 15])
            ->assertRedirect(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id]));

        $this->assertDatabaseHas('product_variants', ['id' => $blackMedium->id, 'stock' => 15]);
        foreach ($variants->where('id', '!=', $blackMedium->id) as $otherVariant) {
            $this->assertDatabaseHas('product_variants', ['id' => $otherVariant->id, 'stock' => $otherVariant->stock]);
        }
        $this->assertSame(45, (int) $product->variants()->where('is_active', true)->sum('stock'));

        $this->actingAs($this->seller)
            ->get(route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id]))
            ->assertOk()
            ->assertSee('10 active variants · 45 total units')
            ->assertSee('data-product-status="healthy"', false);
    }

    public function test_buyer_cart_validation_uses_the_updated_variant_stock(): void
    {
        $product = $this->product('Buyer Stock Product');
        $variant = $this->variant($product, 'INV-BUYER-STOCK', 5);

        $this->actingAs($this->seller)
            ->patch(route('seller.products.variants.stock', ['product' => $product->id, 'variant' => $variant->id]), ['stock' => 1])
            ->assertRedirect();

        try {
            app(CartService::class)->add($this->buyer, $product, $variant->id, 2);
            $this->fail('Buyer cart accepted a quantity above the updated variant stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
    }

    public function test_dashboard_inventory_alerts_summarize_low_and_out_variants(): void
    {
        $product = $this->product('Dashboard Stock Alerts');
        $this->variant($product, 'INV-DASHBOARD-OUT', 0);
        $this->variant($product, 'INV-DASHBOARD-LOW', 10);
        $this->variant($product, 'INV-DASHBOARD-HEALTHY', 11);

        $this->actingAs($this->seller)
            ->get(route('seller.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard Stock Alerts')
            ->assertSee('1 out of stock · 1 low stock variants');
    }

    private function blackSizeVariants(): array
    {
        $product = $this->product('Black Shorts');
        $color = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Color', 'sort_order' => 1]);
        $size = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Size', 'sort_order' => 2]);
        $black = ProductOptionValue::query()->create(['product_option_id' => $color->id, 'value' => 'Black', 'sort_order' => 1]);
        $smallValue = ProductOptionValue::query()->create(['product_option_id' => $size->id, 'value' => 'S', 'sort_order' => 1]);
        $largeValue = ProductOptionValue::query()->create(['product_option_id' => $size->id, 'value' => 'L', 'sort_order' => 2]);
        $small = $this->variant($product, 'INV-BLACK-S', 5);
        $large = $this->variant($product, 'INV-BLACK-L', 3);
        $small->optionValues()->attach([$black->id, $smallValue->id]);
        $large->optionValues()->attach([$black->id, $largeValue->id]);

        return [$product, $small, $large];
    }

    private function tenVariantProduct(): array
    {
        $product = $this->product('Men\'s Active Sports Shorts');
        $color = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Color', 'sort_order' => 1]);
        $size = ProductOption::query()->create(['product_id' => $product->id, 'name' => 'Size', 'sort_order' => 2]);
        $colors = collect(['Black', 'Beige'])->mapWithKeys(fn (string $value, int $index) => [
            $value => ProductOptionValue::query()->create(['product_option_id' => $color->id, 'value' => $value, 'sort_order' => $index + 1]),
        ]);
        $sizes = collect(['S', 'M', 'L', 'XL', 'XXL'])->mapWithKeys(fn (string $value, int $index) => [
            $value => ProductOptionValue::query()->create(['product_option_id' => $size->id, 'value' => $value, 'sort_order' => $index + 1]),
        ]);
        $stocks = [0, 6, 2, 3, 4, 0, 5, 5, 5, 6];
        $variants = collect();
        $blackMedium = null;
        $index = 0;

        foreach (['Black', 'Beige'] as $colorName) {
            foreach (['S', 'M', 'L', 'XL', 'XXL'] as $sizeName) {
                $variant = $this->variant($product, 'INV-SHORTS-'.($index + 1), $stocks[$index]);
                $variant->optionValues()->attach([$colors[$colorName]->id, $sizes[$sizeName]->id]);
                $variants->push($variant);
                if ($colorName === 'Black' && $sizeName === 'M') {
                    $blackMedium = $variant;
                }
                $index++;
            }
        }

        return [$product, $variants, $blackMedium];
    }

    private function product(string $name, ?User $owner = null): Product
    {
        $owner ??= $this->seller;
        $profile = $owner->sellerProfile;

        return Product::query()->create([
            'seller_profile_id' => $profile->id,
            'category_id' => $profile->primary_category_id ?: $this->categoryId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'status' => 'ACTIVE',
        ]);
    }

    private function variant(Product $product, string $sku, int $stock, bool $active = true): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'price' => 100,
            'stock' => $stock,
            'is_default' => true,
            'is_active' => $active,
        ]);
    }
}
