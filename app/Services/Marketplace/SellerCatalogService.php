<?php

namespace App\Services\Marketplace;

use App\Http\Requests\SaveSellerProductRequest;
use App\Models\Seller\Product;
use App\Models\Seller\ProductImage;
use App\Models\Seller\ProductOptionValue;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SellerCatalogService
{
    public function __construct(private readonly ImageOptimizationService $images) {}

    public function save(SellerProfile $seller, SaveSellerProductRequest $request, ?Product $product = null): Product
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($seller, $request, $product, &$storedPaths): Product {
                $product ??= new Product(['seller_profile_id' => $seller->id]);

                if ((int) $product->seller_profile_id !== (int) $seller->id) {
                    abort(403);
                }

                $status = strtoupper((string) $request->input('status', 'DRAFT'));
                if ($product->exists && $product->status === 'SUSPENDED' && $status !== 'SUSPENDED') {
                    // Sellers may correct the listing while it is suspended, but
                    // only Admin can reinstate or republish it.
                    $status = 'SUSPENDED';
                }

                $product->fill([
                    'seller_profile_id' => $seller->id,
                    'category_id' => (int) $request->input('category_id'),
                    'name' => $request->string('name')->toString(),
                    'slug' => $this->uniqueSlug($request->string('name')->toString(), $product),
                    'description' => $request->input('description'),
                    'status' => $status,
                    'published_at' => $status === 'ACTIVE' ? ($product->published_at ?: now()) : $product->published_at,
                    'archived_at' => $status === 'ARCHIVED' ? now() : null,
                ]);

                $product->save();

                if ($request->input('product_type') === 'variations') {
                    $this->assertVariationOptions((array) $request->input('options', []));
                }

                $optionValuesByKey = $this->syncOptions($product, (array) $request->input('options', []));
                $this->removeImages($product, (array) $request->input('delete_image_ids', []));
                $newImageReferences = $this->storeImages(
                    $seller,
                    $product,
                    $request,
                    $storedPaths,
                );
                $this->syncVariants($product, $request, $optionValuesByKey, $newImageReferences);

                return $product->load(['category', 'images.optionValue.option', 'options.values.images', 'variants.optionValues.option', 'variants.productImage']);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function archiveOrRestore(SellerProfile $seller, Product $product): Product
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        abort_unless($product->status !== 'SUSPENDED', 403, 'Suspended listings require Admin review before they can be reinstated.');

        $newStatus = $product->status === 'ARCHIVED' ? 'ACTIVE' : 'ARCHIVED';
        $product->update([
            'status' => $newStatus,
            'archived_at' => $newStatus === 'ARCHIVED' ? now() : null,
            'published_at' => $newStatus === 'ACTIVE' ? ($product->published_at ?: now()) : $product->published_at,
        ]);

        return $product;
    }

    public function updateSingleStock(SellerProfile $seller, Product $product, int $stock): void
    {
        abort_unless((int) $product->seller_profile_id === (int) $seller->id, 403);
        abort_unless($product->variants()->where('is_active', true)->count() === 1, 409, 'Edit variant stock from the product editor.');

        $product->variants()->where('is_active', true)->firstOrFail()->update(['stock' => $stock]);
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
        $variantRows = collect((array) $request->input('variants', []))
            ->filter(fn ($row) => filled(Arr::get($row, 'sku')) || filled(Arr::get($row, 'values')) || filled(Arr::get($row, 'price')) || filled(Arr::get($row, 'stock')))
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
                'values' => '',
                'discount_type' => $request->input('discount_type', 'none'),
                'discount_value' => $request->input('discount_value', 0),
                'is_active' => true,
            ]]);
        }

        $keptIds = [];
        $seenSkus = [];
        $seenCombinations = [];
        $index = 0;

        foreach ($variantRows as $row) {
            $sku = trim((string) Arr::get($row, 'sku')) ?: 'LK-'.$product->id.'-'.Str::upper(Str::random(6));
            $variantId = Arr::get($row, 'id');

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

            $variant = null;
            if (filled($variantId)) {
                $variant = $product->variants()->whereKey((int) $variantId)->first();
                if (! $variant) {
                    throw ValidationException::withMessages([
                        'variants' => 'One or more variant rows do not belong to this product.',
                    ]);
                }
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
                'payment_method' => $paymentMethod ?: 'cod_online',
                'stock' => (int) $stock,
                'is_default' => $index === 0,
                'is_active' => filter_var(Arr::get($row, 'is_active', $request->input('is_active', true)), FILTER_VALIDATE_BOOLEAN),
            ]);
            $variant->save();

            $variant->optionValues()->sync($valueIds);
            $keptIds[] = $variant->id;
            $index++;
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
                'values' => implode(',', $values),
                'discount_type' => $request->input('discount_type', 'none'),
                'discount_value' => $request->input('discount_value', 0),
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

    private function removeImages(Product $product, array $imageIds): void
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

        $product->variants()->whereIn('product_image_id', $imageIds)->update(['product_image_id' => null]);

        foreach ($images as $image) {
            if (filled($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
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

        if (filled($image->file_path)) {
            Storage::disk('public')->delete($image->file_path);
        }

        $image->delete();
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
