<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSellerProductRequest;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Marketplace\SellerCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SellerProductController extends Controller
{
    public function __construct(
        private readonly SellerCatalogService $sellerCatalog,
        private readonly ProductCatalogService $catalog,
    ) {}

    public function index(Request $request): View
    {
        $seller = $this->seller($request);
        $seller->loadMissing('primaryCategory.children');
        $products = $seller->products()
            ->with(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage', 'orderItems.review'])
            ->latest()
            ->get();
        $sellerProducts = $products->map(fn (Product $product) => $this->viewData($product));

        $selected = null;
        $selectedProductModel = null;
        if ($request->filled('product')) {
            $selected = $sellerProducts->firstWhere('db_id', $request->integer('product'));
            $selectedProductModel = $products->firstWhere('id', $request->integer('product'));
        }

        if ($request->query('mode') === 'edit' && $selected === null) {
            abort(404);
        }

        return view('Seller.products', [
            'mode' => $request->query('mode', 'list'),
            'seller' => $seller,
            'products' => $products,
            'sellerProducts' => $sellerProducts,
            'selectedProduct' => $selected,
            'lineOfBusinessCategory' => $seller->primaryCategory,
            'categories' => $this->allowedCategories($seller, $selectedProductModel),
            'complianceCases' => $seller->complianceCases()
                ->with(['product.category.parent'])
                ->latest('opened_at')
                ->latest('id')
                ->get(),
        ]);
    }

    public function store(SaveSellerProductRequest $request): RedirectResponse
    {
        $product = $this->sellerCatalog->save($this->seller($request), $request);

        return redirect()
            ->route('seller.products', ['mode' => 'edit', 'product' => $product->id])
            ->with('product_saved', [
                'name' => $product->name,
                'status' => $product->status,
            ]);
    }

    public function update(SaveSellerProductRequest $request, Product $product): RedirectResponse
    {
        $product = $this->sellerCatalog->save($this->seller($request), $request, $product);

        return back()->with('product_saved', [
            'name' => $product->name,
            'status' => $product->status,
        ]);
    }

    public function stock(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        $this->sellerCatalog->updateSingleStock($this->seller($request), $product, (int) $data['stock']);

        return back()->with('status', 'Stock updated.');
    }

    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $updated = $this->sellerCatalog->archiveOrRestore($this->seller($request), $product);

        return back()->with('status', $updated->status === 'ARCHIVED' ? 'Product archived.' : 'Product restored.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        $product->delete();

        return redirect()->route('seller.products')->with('status', 'Product moved to Recently Deleted. It will be permanently removed after 30 days.');
    }

    public function trash(Request $request): View
    {
        $seller = $this->seller($request);
        $products = Product::onlyTrashed()->where('seller_profile_id', $seller->id)->latest('deleted_at')->paginate(20);

        return view('Seller.products-trash', compact('products'));
    }

    public function restore(Request $request, int $productId): RedirectResponse
    {
        $seller = $this->seller($request);
        $product = Product::onlyTrashed()->where('seller_profile_id', $seller->id)->findOrFail($productId);
        $product->restore();

        return back()->with('status', 'Product restored.');
    }

    public function deleteImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        $this->sellerCatalog->deleteImage($this->seller($request), $product, $image);

        return back()->with('status', 'Product image removed.');
    }

    public function export(Request $request): StreamedResponse
    {
        $products = $this->seller($request)
            ->products()
            ->with(['category', 'variants'])
            ->latest()
            ->get();

        return response()->streamDownload(function () use ($products): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['ID', 'Product', 'Category', 'Minimum Price', 'Total Stock', 'Status']);

            foreach ($products as $product) {
                $activeVariants = $product->variants->where('is_active', true);
                fputcsv($out, [
                    $product->id,
                    $product->name,
                    $product->category?->name,
                    number_format((float) $activeVariants->min('price'), 2, '.', ''),
                    $activeVariants->sum('stock'),
                    $product->status,
                ]);
            }

            fclose($out);
        }, 'seller-products-'.now()->format('Ymd-His').'.csv');
    }

    private function seller(Request $request): SellerProfile
    {
        return $request->user()
            ->sellerProfile()
            ->where('status', 'ACTIVE')
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function viewData(Product $product): array
    {
        $product->loadMissing(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage']);
        $allVariants = $product->variants->sortBy('id')->values();
        $activeVariants = $allVariants->where('is_active', true)->values();
        $imageLibrary = $product->images
            ->sortBy(fn (ProductImage $image): int => ((int) ! $image->is_primary * 1000000) + ((int) $image->sort_order * 1000) + (int) $image->id)
            ->values();
        $baseImages = $imageLibrary;
        $firstImage = $baseImages->first();

        return [
            'db_id' => $product->id,
            'id' => (string) $product->id,
            'sku' => $activeVariants->pluck('sku')->filter()->join(', '),
            'name' => $product->name,
            'category' => $product->category?->name ?? 'Uncategorized',
            'category_id' => $product->category_id,
            'price' => (float) ($activeVariants->min(fn (ProductVariant $variant) => $variant->final_price) ?? 0),
            'stock' => (int) $activeVariants->sum('stock'),
            'sold' => (int) $product->orderItems->sum('quantity'),
            'rating' => (float) $product->orderItems->pluck('review.rating')->filter()->avg(),
            // Keep a stable canonical key for logic and a title-cased value
            // for the existing Seller table presentation.
            'status' => str((string) $product->status)->replace('_', ' ')->headline(),
            'status_key' => strtoupper((string) $product->status),
            'status_label' => str((string) $product->status)->replace('_', ' ')->headline(),
            'status_class' => match (strtoupper((string) $product->status)) {
                'ACTIVE' => 'is-success',
                'SUSPENDED' => 'is-danger',
                default => 'is-neutral',
            },
            'description' => $product->description,
            'image' => $firstImage?->file_path
                ? $this->catalog->publicUrl($firstImage->file_path)
                : asset('images/product-placeholder.svg'),
            'images' => $product->images->map(fn ($image): array => [
                'id' => $image->id,
                'url' => $this->catalog->publicUrl($image->file_path),
                'alt' => $image->alt_text ?: $product->name,
                'is_primary' => (bool) $image->is_primary,
                'product_option_value_id' => $image->product_option_value_id,
                'option_name' => $image->optionValue?->option?->name,
                'option_value' => $image->optionValue?->value,
            ])->all(),
            'base_images' => $baseImages->map(fn ($image): array => [
                'id' => $image->id,
                'url' => $this->catalog->publicUrl($image->file_path),
                'alt' => $image->alt_text ?: $product->name,
                'is_primary' => (bool) $image->is_primary,
            ])->all(),
            'option_rows' => $product->options->map(fn ($option): array => [
                'id' => $option->id,
                'name' => $option->name,
                'values' => $option->values->pluck('value')->join(', '),
                'value_rows' => $option->values->map(fn ($value): array => [
                    'id' => $value->id,
                    'value' => $value->value,
                ])->all(),
            ])->all(),
            'variation_rows' => $allVariants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'price' => $variant->price,
                'discount_type' => $variant->resolved_discount_type,
                'discount_value' => $variant->discount_value,
                'payment_method' => $variant->payment_method ?: 'cod_online',
                'stock' => $variant->stock,
                'values' => $variant->optionValues->sortBy(fn ($value) => $value->option?->sort_order ?? 0)->pluck('value')->join(', '),
                'option_value_ids' => $variant->optionValues->pluck('id')->values()->all(),
                'product_image_id' => $variant->product_image_id,
                'product_image_ref' => $variant->product_image_id ? 'image:'.$variant->product_image_id : null,
                'description' => $variant->description,
                'is_active' => $variant->is_active,
            ])->all(),
            'image_library' => $imageLibrary->map(fn (ProductImage $image, int $index): array => [
                'id' => $image->id,
                'ref' => 'image:'.$image->id,
                'number' => $index + 1,
                'url' => $this->catalog->publicUrl($image->file_path),
                'alt' => $image->alt_text ?: $product->name,
                'is_primary' => (bool) $image->is_primary,
            ])->all(),
        ];
    }

    private function allowedCategories(SellerProfile $seller, ?Product $editingProduct = null)
    {
        $primary = $seller->primaryCategory;

        $categories = collect();
        if ($primary && $primary->is_active && $primary->archived_at === null) {
            $categories = collect([$primary])
                ->merge($primary->children->where('is_active', true)->whereNull('archived_at'))
                ->filter(fn (Category $category) => $category->is_active && $category->archived_at === null)
                ->unique('id')
                ->sortBy('name')
                ->values();
        }

        // Keep a historical unavailable assignment selectable while editing
        // so a Seller can save unrelated corrections. New products and
        // category changes still pass the active-category backend rules.
        $existingCategory = $editingProduct?->category;
        if ($existingCategory && ! $categories->contains('id', $existingCategory->id)) {
            $existingCategory->setAttribute('is_unavailable_assignment', true);
            $categories->prepend($existingCategory);
        }

        return $categories;
    }
}
