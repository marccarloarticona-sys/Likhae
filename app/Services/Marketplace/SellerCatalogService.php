<?php

namespace App\Services\Marketplace;

use App\Http\Requests\SaveSellerProductRequest;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Services\Media\ImageOptimizationService;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Throwable;

class SellerCatalogService
{
    public function __construct(
        private readonly ImageOptimizationService $images,
        private readonly ProductModerationService $moderation,
    ) {}

    public function save(SellerProfile $seller, SaveSellerProductRequest $request, ?Product $product = null): Product
    {
        $storedPaths = [];
        $deletedPaths = [];
        $previousModerationFingerprint = null;
        $isNewProduct = $product === null;
        $idempotencyKey = $isNewProduct ? $request->input('idempotency_key') : null;
        $idempotencyReady = $isNewProduct && filled($idempotencyKey) && $this->productIdempotencyColumnsReady();
        $submissionFingerprint = $idempotencyReady ? $this->submissionFingerprint($request) : null;

        if ($idempotencyReady) {
            $existing = Product::withTrashed()->where('seller_profile_id', $seller->id)
                ->where('submission_token', $idempotencyKey)->first();
            if ($existing) {
                $this->assertIdempotencyFingerprintMatches($existing, (string) $submissionFingerprint);
                abort_if($existing->trashed(), 409, 'This product submission was already used for a deleted product. Start a new product form to create another listing.');

                return $existing->load(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage']);
            }
        }

        try {
            $savedProduct = DB::transaction(function () use ($seller, $request, $product, $isNewProduct, $idempotencyReady, $idempotencyKey, $submissionFingerprint, &$storedPaths, &$deletedPaths, &$previousModerationFingerprint): Product {
                if ($product?->exists) {
                    $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                } else {
                    $product = new Product(['seller_profile_id' => $seller->id]);
                }

                if ((int) $product->seller_profile_id !== (int) $seller->id) {
                    abort(403);
                }

                $wasActive = $product->exists && $product->status === 'ACTIVE';
                if ($product->exists) {
                    $previousModerationFingerprint = $this->moderation->revisionFingerprint($product);
                }

                $status = strtoupper((string) $request->input('status', 'DRAFT'));
                if ($product->exists && $product->status === 'SUSPENDED' && $status !== 'SUSPENDED') {
                    // Sellers may correct the listing while it is suspended, but
                    // only Admin can reinstate or republish it.
                    $status = 'SUSPENDED';
                }
                $syncVariantData = $status === 'ACTIVE' || $this->hasCompleteVariantInput($request);
                if ($product->exists && $syncVariantData) {
                    $this->assertOrderLinkedVariantsPreserved($product, $request);
                }

                $productAttributes = [
                    'seller_profile_id' => $seller->id,
                    'category_id' => (int) $request->input('category_id'),
                    'name' => $request->string('name')->toString(),
                    'slug' => $this->uniqueSlug($request->string('name')->toString(), $product),
                    'description' => $request->input('description'),
                    'status' => $status,
                    'purchase_limit_per_buyer' => $request->input('purchase_limit_per_buyer'),
                    'published_at' => $status === 'ACTIVE' ? ($product->published_at ?: now()) : $product->published_at,
                    'archived_at' => $status === 'ARCHIVED' ? now() : null,
                ];
                if ($isNewProduct && $idempotencyReady) {
                    $productAttributes['submission_token'] = $idempotencyKey;
                    $productAttributes['submission_fingerprint'] = $submissionFingerprint;
                }
                if (Schema::hasColumn('products', 'flash_sale_starts_at')
                    && Schema::hasColumn('products', 'flash_sale_ends_at')) {
                    $productAttributes['flash_sale_starts_at'] = $request->input('flash_sale_starts_at') ?: null;
                    $productAttributes['flash_sale_ends_at'] = $request->input('flash_sale_ends_at') ?: null;
                }
                $product->fill($productAttributes);

                $product->save();

                if ($syncVariantData && $request->input('product_type') === 'variations') {
                    $this->assertVariationOptions((array) $request->input('options', []));
                }

                $optionValuesByKey = $syncVariantData
                    ? $this->syncOptions($product, (array) $request->input('options', []))
                    : [];
                $this->removeImages($product, (array) $request->input('delete_image_ids', []), $deletedPaths);
                $this->promotePrimaryImageIfNeeded($product);
                $newImageReferences = $this->storeImages(
                    $seller,
                    $product,
                    $request,
                    $storedPaths,
                );
                if ($syncVariantData) {
                    $this->syncVariants($product, $request, $optionValuesByKey, $newImageReferences);
                }

                if ($status === 'ACTIVE') {
                    $allowLegacyImageGap = $wasActive
                        && ! $this->hasValidProductImage($product)
                        && $request->file('image') === null
                        && empty($request->file('images'))
                        && empty($request->input('delete_image_ids', []));
                    $this->assertStoredProductCanBePublished($product, $allowLegacyImageGap, $wasActive);
                }

                return $product->load(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage']);
            });
        } catch (QueryException $exception) {
            $this->deleteStoredPaths($storedPaths);
            if ($idempotencyReady) {
                $existing = Product::withTrashed()->where('seller_profile_id', $seller->id)
                    ->where('submission_token', $idempotencyKey)->first();
                if ($existing) {
                    $this->assertIdempotencyFingerprintMatches($existing, (string) $submissionFingerprint);
                    abort_if($existing->trashed(), 409, 'This product submission was already used for a deleted product. Start a new product form to create another listing.');

                    return $existing->load(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage']);
                }
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($storedPaths);

            throw $exception;
        }

        // The database changes have committed, so removing these files can no
        // longer leave a rolled-back product record pointing at missing media.
        foreach (array_unique($deletedPaths) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->syncFlashSaleReminders($savedProduct);

        $newModerationFingerprint = $this->moderation->revisionFingerprint($savedProduct);
        if ($previousModerationFingerprint === null || ! hash_equals($previousModerationFingerprint, $newModerationFingerprint)) {
            $this->moderation->queueAutomatic($savedProduct);
        }

        return $savedProduct;
    }

    public function archiveOrRestore(SellerProfile $seller, Product $product): Product
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        abort_unless($product->status !== 'SUSPENDED', 403, 'Suspended listings require Admin review before they can be reinstated.');

        $newStatus = $product->status === 'ARCHIVED' ? 'ACTIVE' : 'ARCHIVED';
        if ($newStatus === 'ACTIVE') {
            $this->assertStoredProductCanBePublished($product);
        }
        $product->update([
            'status' => $newStatus,
            'archived_at' => $newStatus === 'ARCHIVED' ? now() : null,
            'published_at' => $newStatus === 'ACTIVE' ? ($product->published_at ?: now()) : $product->published_at,
        ]);

        $this->syncFlashSaleReminders($product->fresh());

        return $product;
    }

    public function moveToRecentlyDeleted(SellerProfile $seller, Product $product): void
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);

        DB::transaction(function () use ($seller, $product): void {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedProduct->seller_profile_id === (int) $seller->id, 403);
            if ($this->productHasUnfinishedOrders($lockedProduct)) {
                throw ValidationException::withMessages([
                    'product' => 'This product is linked to an order that is still in progress. Complete or cancel the order before moving it to Recently Deleted. You can archive it instead.',
                ]);
            }
            $lockedProduct->delete();
        });
    }

    public function updateSingleStock(SellerProfile $seller, Product $product, int $stock): void
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        DB::transaction(function () use ($seller, $product, $stock): void {
            $isOwned = Product::query()
                ->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)
                ->exists();
            abort_unless($isOwned, 403);
            $variants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            abort_unless($variants->count() === 1, 409, 'Edit variant stock from the product editor.');
            $this->assertFlashSaleAllocationFitsStock($variants->first(), $stock);
            $variants->first()->update(['stock' => $stock]);
        }, attempts: 3);

        $this->syncFlashSaleReminders($product->fresh());
    }

    public function updateVariantStock(
        SellerProfile $seller,
        Product $product,
        ProductVariant $variant,
        int $stock,
    ): ProductVariant {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $variant->product_id === (int) $product->id, 404);

        $updatedVariant = DB::transaction(function () use ($seller, $product, $variant, $stock): ProductVariant {
            $isOwned = Product::query()
                ->whereKey($product->id)
                ->where('seller_profile_id', $seller->id)
                ->exists();
            abort_unless($isOwned, 403);
            $lockedVariant = ProductVariant::query()
                ->whereKey($variant->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertFlashSaleAllocationFitsStock($lockedVariant, $stock);
            $lockedVariant->update(['stock' => $stock]);

            return $lockedVariant;
        }, attempts: 3);

        $this->syncFlashSaleReminders($product->fresh());

        return $updatedVariant;
    }

    private function assertFlashSaleAllocationFitsStock(ProductVariant $variant, int $stock): void
    {
        if ($variant->flash_sale_allocation === null) {
            return;
        }

        $remainingAllocation = max(0, (int) $variant->flash_sale_allocation - (int) $variant->flash_sale_sold);
        if ($remainingAllocation > $stock) {
            throw ValidationException::withMessages([
                'stock' => 'Stock cannot be reduced below the remaining flash-sale allocation. Update the allocation first.',
            ]);
        }
    }

    private function syncFlashSaleReminders(Product $product): void
    {
        if (Schema::hasTable('flash_sale_reminders')) {
            app(FlashSaleReminderService::class)->syncProduct($product);
        }
    }

    private function productIdempotencyColumnsReady(): bool
    {
        return Schema::hasTable('products')
            && Schema::hasColumn('products', 'submission_token')
            && Schema::hasColumn('products', 'submission_fingerprint');
    }

    private function submissionFingerprint(SaveSellerProductRequest $request): string
    {
        $payload = $request->all();
        unset($payload['_token'], $payload['_method'], $payload['idempotency_key'], $payload['image'], $payload['images']);
        $uploads = collect([$request->file('image')])
            ->merge((array) $request->file('images', []))
            ->filter(fn ($file): bool => $file instanceof UploadedFile && $file->isValid())
            ->map(fn (UploadedFile $file): array => [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'fields' => $payload,
            'uploads' => $uploads,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function assertIdempotencyFingerprintMatches(Product $product, string $fingerprint): void
    {
        $storedFingerprint = (string) $product->getAttribute('submission_fingerprint');
        abort_unless($storedFingerprint !== '' && hash_equals($storedFingerprint, $fingerprint), 409,
            'This product form was already submitted with different information. Start a new product form to continue.');
    }

    /** @param array<int, string> $paths */
    private function deleteStoredPaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function hasCompleteVariantInput(SaveSellerProductRequest $request): bool
    {
        if ($request->input('product_type') === 'single') {
            return filled($request->input('price')) && filled($request->input('stock'));
        }

        $options = collect((array) $request->input('options', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row): bool => filled($row['name'] ?? null) || filled($row['values'] ?? null));
        if ($options->isEmpty() || $options->contains(fn (array $row): bool => blank($row['name'] ?? null) || blank($row['values'] ?? null))) {
            return false;
        }

        $variants = collect((array) $request->input('variants', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row): bool => collect(['id', 'sku', 'values', 'price', 'stock'])
                ->contains(fn (string $field): bool => filled($row[$field] ?? null)));
        if ($variants->isEmpty()) {
            return filled($request->input('price')) && filled($request->input('stock'));
        }

        return $variants->every(fn (array $row): bool => filled($row['values'] ?? null)
            && (filled($row['price'] ?? null) || filled($request->input('price')))
            && (filled($row['stock'] ?? null) || filled($request->input('stock'))));
    }

    private function assertOrderLinkedVariantsPreserved(Product $product, SaveSellerProductRequest $request): void
    {
        $variantIds = $this->unfinishedOrderVariantIds($product);
        if ($variantIds->isEmpty()) {
            return;
        }

        $currentOptions = $product->options()->with('values')->orderBy('sort_order')->get()
            ->map(fn ($option): array => [
                mb_strtolower(trim($option->name)),
                $option->values->sortBy('sort_order')->pluck('value')->map(fn ($value): string => mb_strtolower(trim($value)))->values()->all(),
            ])->values()->all();
        $submittedOptions = collect((array) $request->input('options', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row): bool => filled($row['name'] ?? null) || filled($row['values'] ?? null))
            ->map(fn (array $option): array => [
                mb_strtolower(trim((string) ($option['name'] ?? ''))),
                collect(preg_split('/[,|]+/', (string) ($option['values'] ?? '')) ?: [])
                    ->map(fn ($value): string => mb_strtolower(trim((string) $value)))
                    ->filter()->values()->all(),
            ])->values()->all();
        if ($currentOptions !== $submittedOptions) {
            throw ValidationException::withMessages([
                'options' => 'Variation options used by an order in progress cannot be changed.',
            ]);
        }

        $rows = collect((array) $request->input('variants', []))->filter(fn ($row): bool => is_array($row));
        foreach ($product->variants()->with('optionValues.option')->whereIn('id', $variantIds)->lockForUpdate()->get() as $variant) {
            $row = $request->input('product_type') === 'single'
                ? ((int) $request->input('single_variant_id') === (int) $variant->id ? [
                    'id' => $variant->id,
                    'sku' => $request->input('sku'),
                    'price' => $request->input('price'),
                    'stock' => $request->input('stock'),
                    'values' => '',
                    'is_active' => $request->input('is_active', true),
                ] : null)
                : $rows->first(fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === (int) $variant->id);

            if (! is_array($row)) {
                throw ValidationException::withMessages([
                    'variants' => 'A variation tied to an order in progress cannot be removed.',
                ]);
            }
            $expectedValues = $variant->optionValues
                ->sortBy(fn ($value): int => $value->option?->sort_order ?? 0)
                ->pluck('value')->map(fn ($value): string => mb_strtolower(trim($value)))->values()->all();
            $submittedValues = collect(preg_split('/[,|]+/', (string) ($row['values'] ?? '')) ?: [])
                ->map(fn ($value): string => mb_strtolower(trim((string) $value)))->filter()->values()->all();
            if ($expectedValues !== $submittedValues) {
                throw ValidationException::withMessages([
                    'variants' => 'A variation used by an order in progress cannot be reassigned.',
                ]);
            }
            if (filled($row['sku'] ?? null) && trim((string) $row['sku']) !== $variant->sku) {
                throw ValidationException::withMessages(['variants' => 'A variation used by an order in progress cannot change its SKU.']);
            }
            $price = $row['price'] ?? $request->input('price');
            if (filled($price) && round((float) $price, 2) !== round((float) $variant->price, 2)) {
                throw ValidationException::withMessages(['variants' => 'A variation used by an order in progress cannot change its price.']);
            }
            if (! filter_var($row['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
                throw ValidationException::withMessages(['variants' => 'A variation used by an order in progress cannot be deactivated.']);
            }
        }
    }

    private function unfinishedOrderVariantIds(Product $product): Collection
    {
        return $this->unfinishedOrderItems($product)
            ->whereNotNull('order_items.product_variant_id')
            ->distinct()
            ->pluck('order_items.product_variant_id');
    }

    private function productHasUnfinishedOrders(Product $product): bool
    {
        return $this->unfinishedOrderItems($product)->exists();
    }

    private function unfinishedOrderItems(Product $product)
    {
        return DB::table('order_items')
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->where(function ($query) use ($product): void {
                $query->where('order_items.product_id', $product->id)
                    ->orWhere('product_variants.product_id', $product->id);
            })
            ->whereNotIn('seller_orders.status', ['COMPLETED', 'CANCELLED'])
            ->whereNotIn('orders.status', ['COMPLETED', 'CANCELLED']);
    }

    private function hasValidProductImage(Product $product): bool
    {
        return $product->images()->get()->contains(fn (ProductImage $image): bool => filled($image->file_path)
            && Storage::disk('public')->exists($image->file_path));
    }

    private function promotePrimaryImageIfNeeded(Product $product): void
    {
        $validImages = $product->images()->get()->filter(fn (ProductImage $image): bool => filled($image->file_path)
            && Storage::disk('public')->exists($image->file_path));
        $primary = $validImages->firstWhere('is_primary', true) ?? $validImages->first();
        $product->images()->update(['is_primary' => false]);
        if ($primary) {
            $primary->update(['is_primary' => true]);
        }
    }

    private function assertStoredProductCanBePublished(
        Product $product,
        bool $allowLegacyImageGap = false,
        bool $allowUnavailableCategory = false,
    ): void {
        $errors = [];
        if (blank(trim((string) $product->name))) {
            $errors['name'] = 'Add a product name before publishing.';
        }
        if (blank(trim((string) $product->description))) {
            $errors['description'] = 'Add a product description before publishing.';
        }

        $category = Category::query()->with('parent')->find($product->category_id);
        $seller = $product->sellerProfile()->first();
        $categoryAllowed = $category
            && $category->is_active
            && $category->archived_at === null
            && (! $category->parent || ($category->parent->is_active && $category->parent->archived_at === null))
            && ((int) $category->id === (int) $seller?->primary_category_id || (int) $category->parent_id === (int) $seller?->primary_category_id);
        if (! $categoryAllowed && ! $allowUnavailableCategory) {
            $errors['category_id'] = 'Choose an active category within your approved line of business before publishing.';
        }

        $hasPrimary = $product->images()->where('is_primary', true)->get()->contains(fn (ProductImage $image): bool => filled($image->file_path)
            && Storage::disk('public')->exists($image->file_path));
        if (! $hasPrimary && ! $allowLegacyImageGap) {
            $errors['image'] = 'Upload a valid primary product image before publishing.';
        }

        $options = $product->options()->with('values')->orderBy('sort_order')->get();
        $activeVariants = $product->variants()->where('is_active', true)->with('optionValues.option')->get();
        if ($activeVariants->isEmpty()) {
            $errors['variants'] = 'Add at least one available product variation before publishing.';
        }
        foreach ($activeVariants as $index => $variant) {
            if ((float) $variant->price < 0 || $variant->stock === null || (int) $variant->stock < 0) {
                $errors["variants.{$index}"] = 'Every available variation needs a valid price and stock quantity.';
            }
            if (! in_array($variant->payment_method, ['cod', 'online', 'cod_online'], true)) {
                $errors["variants.{$index}.payment_method"] = 'Choose a valid payment method for each variation.';
            }
        }
        if ($options->count() > 3 || ($options->isNotEmpty() && $options->contains(fn ($option): bool => blank($option->name) || $option->values->isEmpty()))) {
            $errors['options'] = 'Every variation type needs a name and at least one option value (maximum three types).';
        }
        if ($options->isNotEmpty()) {
            $expectedOptionIds = $options->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            foreach ($activeVariants as $index => $variant) {
                $actualOptionIds = $variant->optionValues->pluck('product_option_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                if ($actualOptionIds !== $expectedOptionIds) {
                    $errors["variants.{$index}.values"] = 'Select one valid value for every variation type.';
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, ProductOptionValue> */
    private function syncOptions(Product $product, array $optionRows): array
    {
        $valuesByName = [];
        $keptOptionIds = [];
        $sort = 0;

        foreach ($optionRows as $row) {
            $name = trim((string) Arr::get($row, 'name'));
            $rawValues = trim((string) Arr::get($row, 'values'));

            if ($name === '' || $rawValues === '') {
                continue;
            }

            $option = $product->options()->updateOrCreate(
                ['name' => $name],
                ['sort_order' => $sort++],
            );
            $keptOptionIds[] = $option->id;

            $valueSort = 0;
            foreach ($this->splitValues($rawValues) as $value) {
                $valueModel = $option->values()->updateOrCreate(
                    ['value' => $value],
                    ['sort_order' => $valueSort++],
                );
                $valuesByName[$this->optionValueKey($name, $value)] = $valueModel;
            }
        }

        $product->options()->whereNotIn('id', $keptOptionIds)->delete();

        return $valuesByName;
    }

    private function assertVariationOptions(array $optionRows): void
    {
        $names = [];
        $valid = 0;

        foreach ($optionRows as $row) {
            $name = trim((string) Arr::get($row, 'name'));
            $values = $this->splitValues((string) Arr::get($row, 'values'));
            if ($name === '' && $values === []) {
                continue;
            }

            if ($name === '' || $values === []) {
                throw ValidationException::withMessages([
                    'options' => 'Every variation type needs a name and at least one option value.',
                ]);
            }

            $nameKey = mb_strtolower($name);
            if (isset($names[$nameKey])) {
                throw ValidationException::withMessages([
                    'options' => 'Variation type names must be unique.',
                ]);
            }

            $names[$nameKey] = true;
            $valid++;
        }

        if ($valid === 0) {
            throw ValidationException::withMessages([
                'options' => 'Add at least one variation type and option value.',
            ]);
        }
    }

    /**
     * @param array<string, ProductOptionValue> $optionValuesByKey
     * @param array<string, ProductImage> $newImageReferences
     */
    private function syncVariants(
        Product $product,
        SaveSellerProductRequest $request,
        array $optionValuesByKey,
        array $newImageReferences = [],
    ): void
    {
        $options = $product->options()->with('values')->orderBy('sort_order')->get();
        $protectedVariantIds = $this->unfinishedOrderVariantIds($product);
        $variantRows = collect((array) $request->input('variants', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row) => filled(Arr::get($row, 'sku')) || filled(Arr::get($row, 'values')) || filled(Arr::get($row, 'price')) || filled(Arr::get($row, 'stock')) || filled(Arr::get($row, 'weight_kg')) || filled(Arr::get($row, 'flash_sale_price')) || filled(Arr::get($row, 'flash_sale_allocation')))
            ->values();

        if ($variantRows->isEmpty() && $options->isNotEmpty()) {
            $variantRows = $this->cartesianVariantRows($product, $request);
        }

        if ($variantRows->count() > 100) {
            throw ValidationException::withMessages([
                'variants' => 'A product can have at most 100 variation combinations.',
            ]);
        }

        if ($variantRows->isEmpty()) {
            $variantRows = collect([[
                'id' => $request->input('single_variant_id'),
                'sku' => $request->input('sku') ?: 'LK-'.$product->id.'-DEFAULT',
                'price' => $request->input('price'),
                'stock' => $request->input('stock'),
                'weight_kg' => $request->input('weight_kg'),
                'values' => '',
                'discount_type' => $request->input('discount_type', 'none'),
                'discount_value' => $request->input('discount_value', 0),
                'flash_sale_price' => $request->input('flash_sale_price'),
                'flash_sale_allocation' => $request->input('flash_sale_allocation'),
                'is_active' => true,
            ]]);
        }

        $keptIds = [];
        $seenSkus = [];
        $seenCombinations = [];
        $index = 0;

        foreach ($variantRows as $row) {
            $variantId = Arr::get($row, 'id');
            $variant = null;
            if (filled($variantId)) {
                $variant = $product->variants()->whereKey((int) $variantId)->first();
                if (! $variant) {
                    throw ValidationException::withMessages([
                        'variants' => 'One or more variant rows do not belong to this product.',
                    ]);
                }
            }
            $sku = trim((string) Arr::get($row, 'sku')) ?: ($variant?->sku ?: 'LK-'.$product->id.'-'.Str::upper(Str::random(6)));

            if (isset($seenSkus[mb_strtolower($sku)])) {
                throw ValidationException::withMessages([
                    'variants' => "Variant SKU {$sku} is duplicated.",
                ]);
            }
            $seenSkus[mb_strtolower($sku)] = true;

            $duplicateSku = ProductVariant::query()
                ->where('sku', $sku)
                ->when($variantId, fn ($query) => $query->whereKeyNot((int) $variantId))
                ->exists();

            if ($duplicateSku) {
                throw ValidationException::withMessages([
                    'variants' => "Variant SKU {$sku} is already used.",
                ]);
            }

            $valueIds = $this->variantValueIds(
                (string) Arr::get($row, 'values', ''),
                $options,
                $optionValuesByKey,
            );
            if ($options->isNotEmpty() && count($valueIds) !== $options->count()) {
                throw ValidationException::withMessages([
                    'variants' => 'Each variation must select one value for every variation type.',
                ]);
            }

            $combinationKey = implode('-', $valueIds);
            if ($combinationKey !== '' && isset($seenCombinations[$combinationKey])) {
                throw ValidationException::withMessages([
                    'variants' => 'Each variation combination must be unique.',
                ]);
            }
            if ($combinationKey !== '') {
                $seenCombinations[$combinationKey] = true;
            }

            $price = Arr::get($row, 'price');
            $price = $price !== null && $price !== '' ? $price : $request->input('price');
            $stock = Arr::get($row, 'stock');
            $stock = $stock !== null && $stock !== '' ? $stock : $request->input('stock', 0);
            if ($price === null || $price === '') {
                throw ValidationException::withMessages([
                    'variants' => 'Every variation needs a price.',
                ]);
            }
            if ($stock === null || $stock === '') {
                throw ValidationException::withMessages([
                    'variants' => 'Every variation needs a stock quantity.',
                ]);
            }
            $discountValue = Arr::get($row, 'discount_value');
            $discountValue = $discountValue !== null && $discountValue !== ''
                ? (float) $discountValue
                : (float) ($request->input('discount_value') ?: 0);
            $discountType = $this->normalizeDiscountType(
                Arr::get($row, 'discount_type', $request->input('discount_type')),
                $discountValue,
            );
            $flashSalePrice = Arr::get($row, 'flash_sale_price');
            $flashSaleAllocation = Arr::get($row, 'flash_sale_allocation');
            $paymentMethod = strtolower((string) (Arr::get($row, 'payment_method') ?: $request->input('payment_method', 'cod_online')));
            $productImage = $this->resolveVariantImage(
                $product,
                Arr::get($row, 'product_image_ref'),
                $newImageReferences,
            );

            $variant ??= new ProductVariant(['product_id' => $product->id]);
            $variant->fill([
                'product_id' => $product->id,
                'product_image_id' => $productImage?->id,
                'sku' => $sku,
                'price' => (float) $price,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'flash_sale_price' => filled($flashSalePrice) ? (float) $flashSalePrice : null,
                'flash_sale_allocation' => filled($flashSaleAllocation) ? (int) $flashSaleAllocation : null,
                'payment_method' => $paymentMethod ?: 'cod_online',
                'stock' => (int) $stock,
                'weight_kg' => Arr::get($row, 'weight_kg', $request->input('weight_kg')),
                'is_default' => $index === 0,
                'is_active' => filter_var(Arr::get($row, 'is_active', $request->input('is_active', true)), FILTER_VALIDATE_BOOLEAN),
            ]);
            $variant->save();

            $variant->optionValues()->sync($valueIds);
            $keptIds[] = $variant->id;
            $index++;
        }

        if ($protectedVariantIds->diff($keptIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'variants' => 'A variation tied to an order in progress cannot be removed.',
            ]);
        }
        $product->variants()->whereNotIn('id', $keptIds)->update(['is_active' => false, 'is_default' => false]);
    }

    /**
     * Resolve a saved image reference or a temporary upload reference to the
     * product image record created during this save request.
     *
     * @param array<string, ProductImage> $newImageReferences
     */
    private function resolveVariantImage(Product $product, mixed $reference, array $newImageReferences): ?ProductImage
    {
        $reference = trim((string) $reference);
        if ($reference === '') {
            return null;
        }

        if (isset($newImageReferences[$reference])) {
            return $newImageReferences[$reference];
        }

        if (str_starts_with($reference, 'image:')) {
            $imageId = (int) Str::after($reference, 'image:');
            return $product->images()->whereKey($imageId)->first();
        }

        throw ValidationException::withMessages([
            'variants' => 'One or more assigned product images are no longer available. Choose the image again.',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function cartesianVariantRows(Product $product, SaveSellerProductRequest $request): Collection
    {
        $options = $product->options()->with('values')->orderBy('sort_order')->get();
        $sets = $options->map(fn ($option) => $option->values->pluck('value')->all())->filter()->values()->all();

        if ($sets === []) {
            return collect();
        }

        $combinations = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach ($set as $value) {
                    $next[] = [...$combination, $value];
                }
            }
            $combinations = $next;
        }

        return collect($combinations)->map(function (array $values) use ($product, $request): array {
            $suffix = collect($values)->map(fn ($value) => Str::slug($value))->implode('-');
            return [
                'sku' => ($request->input('sku') ?: 'LK-'.$product->id).'-'.Str::upper($suffix ?: Str::random(5)),
                'price' => $request->input('price'),
                'stock' => $request->input('stock'),
                'weight_kg' => $request->input('weight_kg'),
                'values' => implode(',', $values),
                'discount_type' => $request->input('discount_type', 'none'),
                'discount_value' => $request->input('discount_value', 0),
                'flash_sale_price' => $request->input('flash_sale_price'),
                'flash_sale_allocation' => $request->input('flash_sale_allocation'),
                'payment_method' => $request->input('payment_method', 'cod_online'),
                'is_active' => true,
            ];
        });
    }

    private function normalizeDiscountType(mixed $type, float $value): string
    {
        $type = strtolower(trim((string) $type));

        if (in_array($type, ['none', 'percentage', 'fixed'], true)) {
            return $type;
        }

        return $value > 0 ? 'percentage' : 'none';
    }

    /** @param array<string, ProductOptionValue> $optionValuesByKey */
    private function variantValueIds(string $raw, Collection $options, array $optionValuesByKey): array
    {
        if ($raw === '') {
            return [];
        }

        $values = collect(preg_split('/[,|]+/', $raw) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
        $ids = [];

        foreach ($options->values() as $index => $option) {
            $value = $values[$index] ?? null;
            $model = $value === null
                ? null
                : ($optionValuesByKey[$this->optionValueKey($option->name, $value)] ?? null);

            if (! $model) {
                throw ValidationException::withMessages([
                    'variants' => "The variation value at position ".($index + 1).' is not valid for '.$option->name.'.',
                ]);
            }

            $ids[] = $model->id;
        }

        return $ids;
    }

    /** @return array<int, string> */
    private function splitValues(string $raw): array
    {
        return collect(preg_split('/[,|]+/', $raw) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique(fn ($value) => mb_strtolower($value))
            ->values()
            ->all();
    }

    /**
     * Store only the product images selected at the top of the form.
     * Variation rows reference these records; they never upload their own files.
     *
     * @param array<int, string> $storedPaths
     * @return array<string, ProductImage>
     */
    private function storeImages(
        SellerProfile $seller,
        Product $product,
        SaveSellerProductRequest $request,
        array &$storedPaths,
    ): array
    {
        $mainImage = $request->file('image');
        $extraImages = $request->file('images', []);
        $baseFiles = collect([$mainImage])
            ->merge(is_array($extraImages) ? $extraImages : [$extraImages])
            ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
            ->values();

        if ($baseFiles->isEmpty()) {
            return [];
        }

        $existing = $product->images()->count();
        if ($existing + $baseFiles->count() > 10) {
            throw ValidationException::withMessages([
                'images' => 'A product may have at most 10 images.',
            ]);
        }

        $uploadedPrimary = $mainImage instanceof UploadedFile && $mainImage->isValid();
        if ($uploadedPrimary) {
            $product->images()->update(['is_primary' => false]);
        }

        $references = [];
        foreach ($baseFiles as $index => $file) {
            $sort = (int) $product->images()->max('sort_order') + 1;
            $path = $this->images->store($file, "sellers/{$seller->id}/products/{$product->id}");
            $storedPaths[] = $path;
            $isPrimary = $uploadedPrimary
                ? $index === 0
                : $existing === 0 && $index === 0;

            $image = $product->images()->create([
                'file_path' => $path,
                'alt_text' => $product->name,
                'product_option_value_id' => null,
                'is_primary' => $isPrimary,
                'sort_order' => $sort,
            ]);

            $reference = $index === 0 && $uploadedPrimary
                ? 'upload:primary'
                : 'upload:additional:'.($uploadedPrimary ? $index - 1 : $index);
            $references[$reference] = $image;
        }

        return $references;
    }

    private function removeImages(Product $product, array $imageIds, array &$deletedPaths): void
    {
        $imageIds = collect($imageIds)->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($imageIds->isEmpty()) {
            return;
        }

        $images = $product->images()->whereIn('id', $imageIds)->get();
        if ($images->count() !== $imageIds->count()) {
            throw ValidationException::withMessages([
                'delete_image_ids' => 'One or more selected images do not belong to this product.',
            ]);
        }

        $this->assertImagesNotUsedByUnfinishedOrderVariants($product, $imageIds->all());

        $product->variants()->whereIn('product_image_id', $imageIds)->update(['product_image_id' => null]);

        foreach ($images as $image) {
            if (filled($image->file_path)) {
                $deletedPaths[] = $image->file_path;
            }

            $image->delete();
        }
    }

    private function optionValueKey(string $optionName, string $value): string
    {
        return mb_strtolower(trim($optionName).'|'.trim($value));
    }

    public function deleteImage(SellerProfile $seller, Product $product, ProductImage $image): void
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        abort_unless((int) $image->product_id === (int) $product->id, 403);
        $deletedPath = null;

        DB::transaction(function () use ($seller, $product, $image, &$deletedPath): void {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedProduct->seller_profile_id === (int) $seller->id, 403);
            $lockedImage = ProductImage::query()->whereKey($image->id)->where('product_id', $lockedProduct->id)->lockForUpdate()->firstOrFail();
            $this->assertImagesNotUsedByUnfinishedOrderVariants($lockedProduct, [(int) $lockedImage->id]);

            $remainingValidImage = $lockedProduct->images()->whereKeyNot($lockedImage->id)->get()
                ->contains(fn (ProductImage $candidate): bool => filled($candidate->file_path)
                    && Storage::disk('public')->exists($candidate->file_path));
            if ($lockedProduct->status === 'ACTIVE' && ! $remainingValidImage) {
                throw ValidationException::withMessages([
                    'image' => 'An active product must retain a valid primary image. Archive the product before removing its final image.',
                ]);
            }

            $lockedProduct->variants()->where('product_image_id', $lockedImage->id)->update(['product_image_id' => null]);
            $deletedPath = $lockedImage->file_path;
            $lockedImage->delete();
            $this->promotePrimaryImageIfNeeded($lockedProduct);
        });

        if (filled($deletedPath)) {
            try {
                Storage::disk('public')->delete($deletedPath);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @param array<int, int> $imageIds */
    private function assertImagesNotUsedByUnfinishedOrderVariants(Product $product, array $imageIds): void
    {
        $protectedVariants = $this->unfinishedOrderVariantIds($product);
        if ($protectedVariants->isNotEmpty() && $product->variants()
            ->whereIn('id', $protectedVariants)
            ->whereIn('product_image_id', $imageIds)
            ->exists()) {
            throw ValidationException::withMessages([
                'delete_image_ids' => 'An image assigned to a variation used by an order in progress cannot be removed.',
            ]);
        }
    }

    private function uniqueSlug(string $name, Product $product): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $counter = 2;

        while (Product::withTrashed()->where('slug', $slug)->whereKeyNot($product->id ?? 0)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
