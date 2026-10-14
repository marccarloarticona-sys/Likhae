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
use App\Services\Reports\SellerInventoryPdfService;
use App\Support\InventoryStockStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            ->with(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'orderItems.review'])
            ->latest()
            ->get();
        $sellerProducts = $products->map(fn (Product $product) => $this->viewData($product));
        $inventoryProducts = $request->query('mode') === 'inventory'
            ? $this->inventoryRows($products)
            : collect();
        $inventoryGroups = $inventoryProducts->groupBy('db_id')->map(function (Collection $variants): array {
            $first = $variants->first();

            return [
                'db_id' => (int) $first['db_id'],
                'name' => $first['name'],
                'image' => $first['image'],
                'variant_count' => $variants->count(),
                'total_stock' => (int) $variants->sum('stock'),
                'low_stock_count' => $variants->where('stock_level', 'low')->count(),
                'out_of_stock_count' => $variants->where('stock_level', 'out')->count(),
                'variants' => $variants->values(),
            ];
        })->values();
        $inventoryStats = [
            'total' => $inventoryProducts->count(),
            'total_units' => (int) $inventoryProducts->sum('stock'),
            'in_stock' => $inventoryProducts->where('stock', '>', 0)->count(),
            'low_stock' => $inventoryProducts->where('stock_level', 'low')->count(),
            'out_of_stock' => $inventoryProducts->where('stock_level', 'out')->count(),
        ];

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
            'inventoryProducts' => $inventoryProducts,
            'inventoryGroups' => $inventoryGroups,
            'inventoryStats' => $inventoryStats,
            'expandedInventoryProductId' => $request->integer('expanded_product'),
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

    public function variantStock(Request $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        $data = $request->validate([
            'stock' => ['required', 'integer', 'min:0', 'max:999999'],
        ]);

        $this->sellerCatalog->updateVariantStock(
            $this->seller($request),
            $product,
            $variant,
            (int) $data['stock'],
        );

        $variant->loadMissing('optionValues.option');

        return redirect()
            ->route('seller.products', ['mode' => 'inventory', 'expanded_product' => $product->id])
            ->with('status', 'Stock updated for '.($variant->description ?: 'Standard').'.');
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

    public function inventoryPreview(Request $request): View
    {
        return view('Seller.inventory-preview', $this->inventoryReportData($request));
    }

    public function downloadInventoryPdf(Request $request, SellerInventoryPdfService $pdf)
    {
        $report = $this->inventoryReportData($request);
        $filename = 'likhae-inventory-'.now()->format('Ymd-His').'.pdf';

        return response($pdf->render($report), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function seller(Request $request): SellerProfile
    {
        return $request->user()
            ->sellerProfile()
            ->where('status', 'ACTIVE')
            ->firstOrFail();
    }

    /**
     * @param Collection<int, Product> $products
     * @return Collection<int, array{db_id:int,variant_id:int,name:string,variant:string,sku:?string,price:float,stock:int,stock_level:string,stock_status:string,listing_status:string,image:string}>
     */
    private function inventoryRows(Collection $products): Collection
    {
        return $products->flatMap(function (Product $product): Collection {
            $primaryImage = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

            return $product->variants
                ->where('is_active', true)
                ->map(function (ProductVariant $variant) use ($product, $primaryImage): array {
                    $variantLabel = $variant->optionValues
                        ->sortBy(fn ($value) => $value->option?->sort_order ?? 0)
                        ->pluck('value')
                        ->filter()
                        ->join(' / ') ?: 'Standard';
                    $imagePath = $primaryImage?->file_path;
                    $stock = (int) $variant->stock;

                    return [
                        'db_id' => (int) $product->id,
                        'variant_id' => (int) $variant->id,
                        'name' => $product->name,
                        'variant' => $variantLabel,
                        'sku' => $variant->sku,
                        'price' => (float) $variant->final_price,
                        'stock' => $stock,
                        'stock_level' => InventoryStockStatus::level($stock),
                        'stock_status' => InventoryStockStatus::label($stock),
                        'listing_status' => (string) $product->status,
                        'image' => $imagePath
                            ? $this->catalog->publicUrl($imagePath)
                            : asset('images/product-placeholder.svg'),
                    ];
                });
        })->values();
    }

    /** @return array<string, mixed> */
    private function inventoryReportData(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'in:all,low,out,healthy'],
        ]);
        $seller = $this->seller($request);
        $seller->loadMissing('user');
        $products = $seller->products()
            ->with(['images', 'variants.optionValues.option'])
            ->latest()
            ->get();
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        $status = $filters['status'] ?? 'all';

        $groups = $this->inventoryRows($products)
            ->groupBy('db_id')
            ->map(function (Collection $variants) use ($search, $status): ?array {
                $first = $variants->first();
                $productMatches = $search !== '' && str_contains(mb_strtolower($first['name']), $search);
                $matchingVariants = $variants->filter(function (array $variant) use ($search, $status, $productMatches): bool {
                    $matchesSearch = $search === ''
                        || $productMatches
                        || str_contains(mb_strtolower($variant['variant'].' '.($variant['sku'] ?? '')), $search);
                    $matchesStatus = $status === 'all' || $variant['stock_level'] === $status;

                    return $matchesSearch && $matchesStatus;
                })->values();

                if ($matchingVariants->isEmpty()) {
                    return null;
                }

                return [
                    'db_id' => (int) $first['db_id'],
                    'name' => $first['name'],
                    'image' => $first['image'],
                    'variant_count' => $matchingVariants->count(),
                    'total_stock' => (int) $matchingVariants->sum('stock'),
                    'low_stock_count' => $matchingVariants->where('stock_level', 'low')->count(),
                    'out_of_stock_count' => $matchingVariants->where('stock_level', 'out')->count(),
                    'healthy_count' => $matchingVariants->where('stock_level', 'healthy')->count(),
                    'variants' => $matchingVariants,
                ];
            })
            ->filter()
            ->values();
        $reportVariants = $groups->flatMap(fn (array $group) => $group['variants'])->values();
        $statusLabels = [
            'all' => 'All stock levels',
            'low' => 'Low Stock (1–10)',
            'out' => 'Out of Stock',
            'healthy' => 'Healthy (11+)',
        ];

        return [
            'sellerName' => $seller->business_name ?: ($seller->user?->name ?: 'Seller'),
            'generatedAt' => now()->format('M j, Y g:i A T'),
            'appliedSearch' => trim((string) ($filters['search'] ?? '')),
            'appliedStatus' => $status,
            'appliedStatusLabel' => $statusLabels[$status],
            'inventoryGroups' => $groups,
            'inventoryStats' => [
                'total' => $reportVariants->count(),
                'in_stock' => $reportVariants->where('stock', '>', 0)->count(),
                'low_stock' => $reportVariants->where('stock_level', 'low')->count(),
                'out_of_stock' => $reportVariants->where('stock_level', 'out')->count(),
                'healthy' => $reportVariants->where('stock_level', 'healthy')->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function viewData(Product $product): array
    {
        $product->loadMissing(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option']);
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
            'low_stock_count' => $activeVariants->filter(fn (ProductVariant $variant): bool => InventoryStockStatus::isLow((int) $variant->stock))->count(),
            'out_of_stock_count' => $activeVariants->filter(fn (ProductVariant $variant): bool => InventoryStockStatus::isOut((int) $variant->stock))->count(),
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
