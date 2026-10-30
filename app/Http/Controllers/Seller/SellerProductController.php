<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSellerProductRequest;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOption;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Services\Marketplace\ProductCatalogService;
use App\Services\Marketplace\ProductPriceResolver;
use App\Services\Marketplace\SellerCatalogService;
use App\Services\Reports\SellerInventoryPdfService;
use App\Services\Reports\WorkspaceExportPdfService;
use App\Services\Seller\SellerPromotionConflictService;
use App\Support\InventoryStockStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    public function __construct(
        private readonly SellerCatalogService $sellerCatalog,
        private readonly ProductCatalogService $catalog,
    ) {}

    public function index(Request $request, SellerPromotionConflictService $promotionConflictService): View
    {
        $seller = $this->seller($request);
        $seller->loadMissing('primaryCategory.children');
        $mode = (string) $request->query('mode', 'list');
        $inventoryMode = $mode === 'inventory';
        $inventoryPage = null;
        $productsPage = null;
        $productStats = ['total' => 0, 'active' => 0, 'low_stock' => 0, 'archived' => 0];
        $selectedProductModel = null;
        if ($inventoryMode) {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:120'],
                'status' => ['nullable', 'string', 'in:all,low,out,healthy'],
            ]);
            $search = trim((string) ($filters['search'] ?? ''));
            $status = $filters['status'] ?? 'all';
            $matchingVariants = fn ($query) => $this->applyInventoryStatus($query->where('is_active', true), $status);
            $productsQuery = $seller->products()
                ->with(['images', 'variants' => $matchingVariants, 'variants.optionValues.option'])
                ->whereHas('variants', $matchingVariants)
                ->latest('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $productsQuery->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhereHas('variants', fn ($variants) => $variants
                            ->where('is_active', true)
                            ->where(function ($matching) use ($like): void {
                                $matching->where('sku', 'like', $like)
                                    ->orWhereHas('optionValues', fn ($values) => $values->where('value', 'like', $like));
                            }));
                });
            }

            $inventoryPage = $productsQuery->paginate(20)->withQueryString();
            $products = $inventoryPage->getCollection();
            $sellerProducts = collect();
            $inventoryProducts = $this->inventoryRows($products);
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $inventoryProducts = $inventoryProducts->filter(fn (array $row): bool => str_contains(mb_strtolower($row['name']), $needle)
                    || str_contains(mb_strtolower($row['variant'].' '.($row['sku'] ?? '')), $needle))->values();
            }
        } elseif ($mode === 'edit') {
            abort_unless($request->filled('product'), 404);
            $selectedProductModel = $seller->products()
                ->select('products.*')
                ->withSum('orderItems as sold_units', 'quantity')
                ->selectSub(
                    DB::table('reviews')
                        ->join('order_items', 'order_items.id', '=', 'reviews.order_item_id')
                        ->selectRaw('AVG(reviews.rating)')
                        ->whereColumn('order_items.product_id', 'products.id'),
                    'average_rating',
                )
                ->with(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option'])
                ->whereKey($request->integer('product'))
                ->firstOrFail();
            $products = collect([$selectedProductModel]);
            $sellerProducts = $products->map(fn (Product $product) => $this->viewData($product, true));
            $inventoryProducts = collect();
        } elseif ($mode === 'add') {
            $products = collect();
            $sellerProducts = collect();
            $inventoryProducts = collect();
        } else {
            $filters = $request->validate([
                'search' => ['nullable', 'string', 'max:120'],
                'status' => ['nullable', 'string', 'in:all,active,archived,draft'],
            ]);
            $search = trim((string) ($filters['search'] ?? ''));
            $status = $filters['status'] ?? 'all';
            $productsQuery = $seller->products()
                ->select('products.*')
                ->withSum('orderItems as sold_units', 'quantity')
                ->selectSub(
                    DB::table('reviews')
                        ->join('order_items', 'order_items.id', '=', 'reviews.order_item_id')
                        ->selectRaw('AVG(reviews.rating)')
                        ->whereColumn('order_items.product_id', 'products.id'),
                    'average_rating',
                )
                ->with(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option'])
                ->when($status !== 'all', fn ($query) => $query->where('status', strtoupper($status)))
                ->latest('id');

            if ($search !== '') {
                $like = '%'.addcslashes($search, '\\%_').'%';
                $productsQuery->where(function ($query) use ($like): void {
                    $query->where('name', 'like', $like)
                        ->orWhereHas('variants', fn ($variants) => $variants->where('sku', 'like', $like));
                });
            }

            $productsPage = $productsQuery->paginate(20)->withQueryString();
            $products = $productsPage->getCollection();
            $sellerProducts = $products->map(fn (Product $product) => $this->viewData($product));
            $inventoryProducts = collect();
            $productStats = $this->productStats($seller);
        }
        $inventoryGroups = $inventoryProducts->groupBy('db_id')->map(function (Collection $variants): array {
            $first = $variants->first();

            return [
                'db_id' => (int) $first['db_id'],
                'name' => $first['name'],
                'listing_status' => $first['listing_status'],
                'image' => $first['image'],
                'variant_count' => $variants->count(),
                'total_stock' => (int) $variants->sum('stock'),
                'low_stock_count' => $variants->where('stock_level', 'low')->count(),
                'out_of_stock_count' => $variants->where('stock_level', 'out')->count(),
                'variants' => $variants->values(),
            ];
        })->values();
        $inventoryStats = $inventoryMode ? $this->inventoryStats($seller) : [
            'total' => 0,
            'total_units' => 0,
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
        ];

        $selected = $mode === 'edit' ? $sellerProducts->first() : null;
        $promotionConflicts = $selectedProductModel
            ? $promotionConflictService->forProducts($seller, collect([$selectedProductModel]))
            : ['products' => [], 'variants' => []];

        return view('seller.products', [
            'mode' => $mode,
            'submissionToken' => $mode === 'add' ? (string) old('idempotency_key', Str::uuid()) : null,
            'seller' => $seller,
            'products' => $products,
            'sellerProducts' => $sellerProducts,
            'inventoryProducts' => $inventoryProducts,
            'inventoryGroups' => $inventoryGroups,
            'inventoryStats' => $inventoryStats,
            'inventoryPage' => $inventoryPage,
            'productsPage' => $productsPage,
            'productStats' => $productStats,
            'productSearch' => $request->query('search', ''),
            'productStatus' => $request->query('status', 'all'),
            'inventorySearch' => $request->query('search', ''),
            'inventoryStatus' => $request->query('status', 'all'),
            'expandedInventoryProductId' => $request->integer('expanded_product'),
            'selectedProduct' => $selected,
            'savedVariantPrices' => $selectedProductModel
                ? $selectedProductModel->variants->mapWithKeys(fn (ProductVariant $variant) => [(int) $variant->id => (float) $variant->price])->all()
                : [],
            'promotionConflicts' => $promotionConflicts,
            'lineOfBusinessCategory' => $seller->primaryCategory,
            'categories' => $this->allowedCategories($seller, $selectedProductModel),
            'complianceCases' => $seller->complianceCases()
                ->with(['product.category.parent'])
                ->latest('opened_at')
                ->latest('id')
                ->limit(3)
                ->get(),
        ]);
    }

    /** @return array{total:int,active:int,low_stock:int,archived:int} */
    private function productStats(SellerProfile $seller): array
    {
        return [
            'total' => $seller->products()->count(),
            'active' => $seller->products()->where('status', 'ACTIVE')->count(),
            'low_stock' => $seller->products()->whereHas('variants', fn ($query) => $query
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('stock', '<=', InventoryStockStatus::LOW_STOCK_THRESHOLD))->count(),
            'archived' => $seller->products()->where('status', 'ARCHIVED')->count(),
        ];
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

    public function duplicate(Request $request, Product $product): RedirectResponse
    {
        $seller = $this->seller($request);
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 404);
        $copiedImagePaths = [];
        $copiedDescriptionPaths = [];

        try {
            $duplicate = DB::transaction(function () use ($seller, $product, &$copiedImagePaths, &$copiedDescriptionPaths): Product {
                $source = $seller->products()
                    ->with(['images', 'options.values', 'variants.optionValues', 'variants.productImage'])
                    ->whereKey($product->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $copyName = mb_substr($source->name.' (Copy)', 0, 200);
                $productAttributes = [
                    'seller_profile_id' => $seller->id,
                    'category_id' => $source->category_id,
                    'name' => $copyName,
                    'slug' => 'copy-'.$source->id.'-'.Str::lower(Str::random(12)),
                    'description' => $source->description,
                    'status' => 'DRAFT',
                    'published_at' => null,
                    'archived_at' => null,
                ];
                foreach (['purchase_limit_per_buyer', 'flash_sale_starts_at', 'flash_sale_ends_at', 'submission_token', 'submission_fingerprint'] as $optionalColumn) {
                    if (Schema::hasColumn('products', $optionalColumn)) {
                        $productAttributes[$optionalColumn] = in_array($optionalColumn, ['flash_sale_starts_at', 'flash_sale_ends_at'], true)
                            ? null
                            : ($optionalColumn === 'purchase_limit_per_buyer' ? $source->{$optionalColumn} : null);
                    }
                }
                $copy = Product::query()->create($productAttributes);

                $suffix = '-copy-'.$copy->id;
                $copy->slug = mb_substr(Str::slug($copyName), 0, 220 - strlen($suffix)).$suffix;
                $copy->save();

                $optionValueIds = [];
                foreach ($source->options as $sourceOption) {
                    $option = ProductOption::query()->create([
                        'product_id' => $copy->id,
                        'name' => $sourceOption->name,
                        'sort_order' => $sourceOption->sort_order,
                    ]);
                    foreach ($sourceOption->values as $sourceValue) {
                        $value = ProductOptionValue::query()->create([
                            'product_option_id' => $option->id,
                            'value' => $sourceValue->value,
                            'sort_order' => $sourceValue->sort_order,
                        ]);
                        $optionValueIds[(int) $sourceValue->id] = (int) $value->id;
                    }
                }

                $imageIds = [];
                foreach ($source->images as $sourceImage) {
                    $newPath = null;
                    if (filled($sourceImage->file_path) && Storage::disk('public')->exists($sourceImage->file_path)) {
                        $extension = pathinfo($sourceImage->file_path, PATHINFO_EXTENSION);
                        $filename = Str::lower(Str::random(40)).($extension !== '' ? '.'.$extension : '');
                        $newPath = "sellers/{$seller->id}/products/{$copy->id}/{$filename}";
                        if (! Storage::disk('public')->copy($sourceImage->file_path, $newPath)) {
                            throw new \RuntimeException('A product image could not be copied safely.');
                        }
                        $copiedImagePaths[] = $newPath;
                    }

                    if ($newPath === null) {
                        continue;
                    }

                    $attributes = [
                        'product_id' => $copy->id,
                        'file_path' => $newPath,
                        'alt_text' => $copyName,
                        'is_primary' => $sourceImage->is_primary,
                        'sort_order' => $sourceImage->sort_order,
                    ];
                    if (DB::getSchemaBuilder()->hasColumn('product_images', 'product_option_value_id')) {
                        $sourceValueId = (int) $sourceImage->product_option_value_id;
                        $attributes['product_option_value_id'] = $optionValueIds[$sourceValueId] ?? null;
                    }
                    $image = ProductImage::query()->create($attributes);
                    $imageIds[(int) $sourceImage->id] = (int) $image->id;
                }

                $copy->description = $source->description === null
                    ? null
                    : preg_replace_callback(
                        '/\[\[image:image:(\d+)\]\]/',
                        static fn (array $match): string => isset($imageIds[(int) $match[1]])
                            ? '[[image:image:'.$imageIds[(int) $match[1]].']]'
                            : '',
                        $source->description,
                    );
                if ($copy->description !== null) {
                    $copy->description = preg_replace_callback(
                        '/\[\[description-image:stored:((?:[A-Za-z0-9]{40}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:jpg|jpeg|png|webp))\]\]/i',
                        function (array $match) use ($seller, $source, $copy, &$copiedDescriptionPaths): string {
                            $sourcePath = "sellers/{$source->seller_profile_id}/products/{$source->id}/descriptions/{$match[1]}";
                            if (! Storage::disk('public')->exists($sourcePath)) {
                                return '';
                            }

                            $filename = Str::lower(Str::random(40)).'.'.strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                            $copyPath = "sellers/{$seller->id}/products/{$copy->id}/descriptions/{$filename}";
                            if (! Storage::disk('public')->copy($sourcePath, $copyPath)) {
                                throw new \RuntimeException('A product description image could not be copied safely.');
                            }
                            $copiedDescriptionPaths[] = $copyPath;

                            return '[[description-image:stored:'.$filename.']]';
                        },
                        $copy->description,
                    );
                }
                $copy->save();

                foreach ($source->variants as $index => $sourceVariant) {
                    $variantAttributes = [
                        'product_id' => $copy->id,
                        'sku' => $this->duplicateSku($copy, (int) $index + 1),
                        'price' => $sourceVariant->price,
                        'stock' => 0,
                        'is_default' => $sourceVariant->is_default,
                        'is_active' => $sourceVariant->is_active,
                    ];
                    foreach (['discount_type', 'discount_value', 'payment_method', 'weight_kg'] as $copiedColumn) {
                        if (Schema::hasColumn('product_variants', $copiedColumn)) {
                            $variantAttributes[$copiedColumn] = $sourceVariant->{$copiedColumn};
                        }
                    }
                    foreach (['flash_sale_price', 'flash_sale_allocation'] as $resetColumn) {
                        if (Schema::hasColumn('product_variants', $resetColumn)) {
                            $variantAttributes[$resetColumn] = null;
                        }
                    }
                    if (Schema::hasColumn('product_variants', 'flash_sale_sold')) {
                        $variantAttributes['flash_sale_sold'] = 0;
                    }
                    if (Schema::hasColumn('product_variants', 'product_image_id')) {
                        $variantAttributes['product_image_id'] = $imageIds[(int) $sourceVariant->product_image_id] ?? null;
                    }
                    $variant = ProductVariant::query()->create($variantAttributes);

                    $newOptionValueIds = $sourceVariant->optionValues
                        ->map(fn (ProductOptionValue $value): ?int => $optionValueIds[(int) $value->id] ?? null)
                        ->filter()
                        ->values()
                        ->all();
                    if ($newOptionValueIds !== []) {
                        $variant->optionValues()->sync($newOptionValueIds);
                    }
                }

                return $copy->load(['images.optionValue.option', 'options.values', 'variants.optionValues.option', 'variants.productImage']);
            }, attempts: 3);
        } catch (Throwable $exception) {
            foreach (array_unique($copiedImagePaths) as $path) {
                Storage::disk('public')->delete($path);
            }
            foreach (array_unique($copiedDescriptionPaths) as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }

        return redirect()
            ->route('seller.products', ['mode' => 'edit', 'product' => $duplicate->id])
            ->with('status', 'Product duplicated as a draft. Review its details and add stock before publishing.');
    }

    private function duplicateSku(Product $product, int $variantIndex): string
    {
        do {
            $sku = 'LK-'.$product->id.'-COPY-'.$variantIndex.'-'.Str::upper(Str::random(6));
        } while (ProductVariant::query()->where('sku', $sku)->exists());

        return $sku;
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

        $variant = $this->sellerCatalog->updateVariantStock(
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
        $this->sellerCatalog->moveToRecentlyDeleted($seller, $product);

        return redirect()->route('seller.products')->with('status', 'Product moved to Recently Deleted. It will be permanently removed after 30 days.');
    }

    public function trash(Request $request): View
    {
        $seller = $this->seller($request);
        $products = Product::onlyTrashed()->where('seller_profile_id', $seller->id)->latest('deleted_at')->paginate(20);

        return view('seller.products-trash', compact('products'));
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

    public function export(Request $request, WorkspaceExportPdfService $pdf)
    {
        $seller = $this->seller($request);
        $products = $seller
            ->products()
            ->with(['category', 'variants'])
            ->orderByDesc('id')
            ->lazyById(500)
            ->map(function (Product $product): array {
                $activeVariants = $product->variants->where('is_active', true);

                return [
                    (string) $product->id,
                    (string) $product->name,
                    (string) ($product->category?->name ?? ''),
                    number_format((float) $activeVariants->min('price'), 2, '.', ''),
                    (string) $activeVariants->sum('stock'),
                    (string) $product->status,
                ];
            });
        $filename = 'seller-products-'.$seller->id.'-'.now()->format('Ymd-His').'.pdf';

        return response($pdf->render([
            'workspace' => 'Seller Center',
            'title' => 'Product catalog',
            'description' => 'Products available to your Seller workspace.',
            'generated_at' => now()->format('Y-m-d H:i:s T'),
            'orientation' => 'portrait',
            'columns' => [
                ['label' => 'ID', 'width' => 0.07],
                ['label' => 'Product', 'width' => 0.28],
                ['label' => 'Category', 'width' => 0.19],
                ['label' => 'Minimum price (PHP)', 'width' => 0.18],
                ['label' => 'Total stock', 'width' => 0.12],
                ['label' => 'Status', 'width' => 0.16],
            ],
            'rows' => $products,
            'empty_message' => 'No products are available for this Seller workspace.',
        ]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function inventoryPreview(Request $request): View
    {
        return view('seller.inventory-preview', $this->inventoryReportData($request));
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

    private function applyInventoryStatus($query, string $status)
    {
        return match ($status) {
            'low' => $query->where('stock', '>', 0)->where('stock', '<=', InventoryStockStatus::LOW_STOCK_THRESHOLD),
            'out' => $query->where('stock', '<=', 0),
            'healthy' => $query->where('stock', '>', InventoryStockStatus::LOW_STOCK_THRESHOLD),
            default => $query,
        };
    }

    /** @return array{total:int,total_units:int,in_stock:int,low_stock:int,out_of_stock:int} */
    private function inventoryStats(SellerProfile $seller): array
    {
        $totals = ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id))
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(stock), 0) AS total_units, SUM(CASE WHEN stock > 0 THEN 1 ELSE 0 END) AS in_stock, SUM(CASE WHEN stock > 0 AND stock <= ? THEN 1 ELSE 0 END) AS low_stock, SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) AS out_of_stock', [InventoryStockStatus::LOW_STOCK_THRESHOLD])
            ->first();

        return [
            'total' => (int) ($totals->total ?? 0),
            'total_units' => (int) ($totals->total_units ?? 0),
            'in_stock' => (int) ($totals->in_stock ?? 0),
            'low_stock' => (int) ($totals->low_stock ?? 0),
            'out_of_stock' => (int) ($totals->out_of_stock ?? 0),
        ];
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
    private function viewData(Product $product, bool $includeDescriptionImageLibrary = false): array
    {
        $product->loadMissing(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option']);
        $allVariants = $product->variants->sortBy('id')->values();
        $priceResolver = app(ProductPriceResolver::class);
        $allVariants->each(fn (ProductVariant $variant) => $variant->setRelation('product', $product));
        $activeVariants = $allVariants->where('is_active', true)->values();
        $imageLibrary = $product->images
            ->sortBy(fn (ProductImage $image): int => ((int) ! $image->is_primary * 1000000) + ((int) $image->sort_order * 1000) + (int) $image->id)
            ->values();
        $baseImages = $imageLibrary;
        $firstImage = $baseImages->first();
        $descriptionImageLibrary = [];
        if ($includeDescriptionImageLibrary
            && preg_match_all(
                '/\[\[description-image:stored:((?:[A-Za-z0-9]{40}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:jpg|jpeg|png|webp))\]\]/i',
                (string) $product->description,
                $descriptionImageMatches,
            )) {
            foreach (array_unique($descriptionImageMatches[1]) as $filename) {
                $path = "sellers/{$product->seller_profile_id}/products/{$product->id}/descriptions/{$filename}";
                if (Storage::disk('public')->exists($path)) {
                    $descriptionImageLibrary[] = [
                        'ref' => 'stored:'.$filename,
                        'url' => $this->catalog->publicUrl($path),
                        'alt' => 'Product description image',
                    ];
                }
            }
        }

        return [
            'db_id' => $product->id,
            'id' => (string) $product->id,
            'sku' => $allVariants->pluck('sku')->filter()->join(', '),
            'name' => $product->name,
            'purchase_limit_per_buyer' => $product->purchase_limit_per_buyer === null
                ? null
                : (int) $product->purchase_limit_per_buyer,
            'category' => $product->category?->name ?? 'Uncategorized',
            'category_id' => $product->category_id,
            'price' => (float) ($activeVariants->min(fn (ProductVariant $variant) => $variant->final_price) ?? 0),
            'base_price' => (float) ($activeVariants->min(fn (ProductVariant $variant) => (float) $variant->price) ?? 0),
            'stock' => (int) $activeVariants->sum('stock'),
            'low_stock_count' => $activeVariants->filter(fn (ProductVariant $variant): bool => InventoryStockStatus::isLow((int) $variant->stock))->count(),
            'out_of_stock_count' => $activeVariants->filter(fn (ProductVariant $variant): bool => InventoryStockStatus::isOut((int) $variant->stock))->count(),
            'sold' => (int) ($product->sold_units ?? 0),
            'rating' => (float) ($product->average_rating ?? 0),
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
            'description_image_library' => $descriptionImageLibrary,
            'flash_sale_starts_at' => $product->flash_sale_starts_at,
            'flash_sale_ends_at' => $product->flash_sale_ends_at,
            'flash_sale_is_paused' => (bool) ($product->flash_sale_is_paused ?? false),
            'discounts_enabled' => $product->discounts_enabled ?? true,
            'discount_starts_at' => $product->discount_starts_at,
            'discount_ends_at' => $product->discount_ends_at,
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
            'variation_rows' => $allVariants->map(function (ProductVariant $variant) use ($priceResolver): array {
                $basePrice = round((float) $variant->price, 2);

                return [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'price' => $variant->price,
                    'discount_type' => $variant->resolved_discount_type,
                    'discount_value' => $variant->discount_value,
                    'discount_active' => $priceResolver->productDiscountPrice($variant) < $basePrice,
                    'flash_sale_price' => $variant->flash_sale_price,
                    'flash_sale_active' => $priceResolver->activeFlashSalePrice($variant) !== null,
                    'flash_sale_remaining' => $priceResolver->remainingFlashSaleUnits($variant),
                    'flash_sale_allocation' => $variant->flash_sale_allocation,
                    'payment_method' => $variant->payment_method ?: 'cod_online',
                    'stock' => $variant->stock,
                    'weight_kg' => $variant->weight_kg,
                    'values' => $variant->optionValues->sortBy(fn ($value) => $value->option?->sort_order ?? 0)->pluck('value')->join(', '),
                    'option_value_ids' => $variant->optionValues->pluck('id')->values()->all(),
                    'product_image_id' => $variant->product_image_id,
                    'product_image_ref' => $variant->product_image_id ? 'image:'.$variant->product_image_id : null,
                    'description' => $variant->description,
                    'is_active' => $variant->is_active,
                ];
            })->all(),
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
