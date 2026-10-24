<?php

namespace App\Http\Requests;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SaveSellerProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAccountType('SELLER') === true;
    }

    protected function prepareForValidation(): void
    {
        $discountValue = $this->input('discount_value', $this->input('discount', $this->input('discount_percentage')));
        $discountType = strtolower((string) ($this->input('discount_type') ?? 'none'));
        $idempotencyKey = $this->header('Idempotency-Key', $this->input('idempotency_key'));

        $this->merge([
            'product_type' => strtolower((string) $this->input('product_type', 'single')),
            'status' => strtoupper((string) $this->input('status', $this->input('listing_status', 'DRAFT'))),
            'idempotency_key' => is_string($idempotencyKey) ? strtolower(trim($idempotencyKey)) : null,
            // Keep unknown values intact so the Rule::in validation below
            // rejects tampered input instead of silently treating it as none.
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
        ]);
    }

    public function rules(): array
    {
        $categoryRule = Rule::exists('categories', 'id');
        if (! $this->preservingUnavailableCategory()) {
            $categoryRule->where('is_active', true)->whereNull('archived_at');
        }

        return [
            'product_type' => ['required', Rule::in(['single', 'variations'])],
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer', $categoryRule],
            'description' => [Rule::requiredIf(fn (): bool => $this->isPublishing()), 'nullable', 'string', 'max:10000'],
            'purchase_limit_per_buyer' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'ARCHIVED'])],
            'idempotency_key' => ['nullable', 'uuid'],
            'flash_sale_starts_at' => ['nullable', 'date'],
            'flash_sale_ends_at' => ['nullable', 'date'],
            'flash_sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'flash_sale_allocation' => ['nullable', 'integer', 'min:1', 'max:999999'],

            'price' => [Rule::requiredIf(fn (): bool => $this->isPublishing() && $this->input('product_type') === 'single'), 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'stock' => [Rule::requiredIf(fn (): bool => $this->isPublishing() && $this->input('product_type') === 'single'), 'nullable', 'integer', 'min:0', 'max:999999'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:9999999.999'],
            'sku' => ['nullable', 'string', 'max:100'],
            'single_variant_id' => ['nullable', 'integer'],
            'discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payment_method' => ['nullable', Rule::in(['cod', 'online', 'cod_online'])],
            'is_active' => ['nullable', 'boolean'],

            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'delete_image_ids' => ['nullable', 'array', 'max:10'],
            'delete_image_ids.*' => ['integer', Rule::exists('product_images', 'id')],

            'options' => [Rule::requiredIf(fn (): bool => $this->isPublishing() && $this->input('product_type') === 'variations'), 'nullable', 'array', 'max:3'],
            'options.*.name' => ['required_with:options.*.values', 'nullable', 'string', 'max:100'],
            'options.*.values' => ['required_with:options.*.name', 'nullable', 'string', 'max:1000'],

            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'variants.*.weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:9999999.999'],
            'variants.*.values' => ['nullable', 'string', 'max:500'],
            'variants.*.discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'variants.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variants.*.flash_sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:999999.99'],
            'variants.*.flash_sale_allocation' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'variants.*.payment_method' => ['nullable', Rule::in(['cod', 'online', 'cod_online'])],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.product_image_ref' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $seller = $this->user()?->sellerProfile?->loadMissing('primaryCategory');
            $lineOfBusinessId = (int) ($seller?->primary_category_id ?? 0);
            $categoryId = (int) $this->input('category_id');

            $categoryAllowed = $lineOfBusinessId > 0 && Category::query()
                ->whereKey($categoryId)
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->where(function ($query): void {
                    $query->whereNull('parent_id')
                        ->orWhereHas('parent', fn ($parentQuery) => $parentQuery->where('is_active', true)->whereNull('archived_at'));
                })
                ->where(function ($query) use ($lineOfBusinessId): void {
                    $query->whereKey($lineOfBusinessId)
                        ->orWhere('parent_id', $lineOfBusinessId);
                })
                ->exists();

            // An existing product may retain an unavailable historical
            // category while its Seller edits other fields. A new product or
            // an explicit category change must still use an active category
            // in the Seller's registered line of business.
            if (! $categoryAllowed && ! $this->preservingUnavailableCategory()) {
                $validator->errors()->add('category_id', 'Choose a category within your approved line of business.');
            }

            $this->validateImageCount($validator);
            if ($this->isPublishing()) {
                $this->validatePublicationRequirements($validator);
            }

            $variantRows = collect((array) $this->input('variants', []))->filter(fn ($row): bool => is_array($row))->all();
            $this->validateDiscount($validator, $variantRows, 'variants', $this->input('price'));
            $this->validateDiscount($validator, [[
                'discount_type' => $this->input('discount_type'),
                'discount_value' => $this->input('discount_value'),
                'price' => $this->input('price'),
            ]], '', $this->input('price'));

            $this->validateFlashSaleConfiguration($validator);

            $startsAt = $this->input('flash_sale_starts_at');
            $endsAt = $this->input('flash_sale_ends_at');
            if ($startsAt && $endsAt && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
                $validator->errors()->add('flash_sale_ends_at', 'The sale end time must be after its start time.');
            }
            if ($startsAt || $endsAt) {
                $rows = $this->input('product_type') === 'variations'
                    ? (array) $this->input('variants', [])
                    : [[
                        'discount_value' => $this->input('discount_value'),
                        'discount_type' => $this->input('discount_type'),
                        'flash_sale_price' => $this->input('flash_sale_price'),
                    ]];
                $hasPromotion = collect($rows)->filter(fn ($row): bool => is_array($row))->contains(fn ($row): bool => (
                    (float) ($row['discount_value'] ?? 0) > 0
                    && strtolower((string) ($row['discount_type'] ?? 'none')) !== 'none'
                ) || filled($row['flash_sale_price'] ?? null));
                if (! $hasPromotion) {
                    $validator->errors()->add('flash_sale_starts_at', 'Set a Seller discount or flash-sale price before scheduling a sale.');
                }
            }
        });
    }

    private function validateFlashSaleConfiguration($validator): void
    {
        $isSingle = $this->input('product_type') === 'single';
        $rows = $isSingle
            ? [[
                'id' => $this->input('single_variant_id'),
                'price' => $this->input('price'),
                'stock' => $this->input('stock'),
                'discount_type' => $this->input('discount_type'),
                'discount_value' => $this->input('discount_value'),
                'flash_sale_price' => $this->input('flash_sale_price'),
                'flash_sale_allocation' => $this->input('flash_sale_allocation'),
            ]]
            : collect((array) $this->input('variants', []))->filter(fn ($row): bool => is_array($row))->values()->all();
        if (! $isSingle && $rows === [] && (filled($this->input('flash_sale_price')) || filled($this->input('flash_sale_allocation')))) {
            $rows = [[
                'price' => $this->input('price'),
                'stock' => $this->input('stock'),
                'discount_type' => $this->input('discount_type'),
                'discount_value' => $this->input('discount_value'),
                'flash_sale_price' => $this->input('flash_sale_price'),
                'flash_sale_allocation' => $this->input('flash_sale_allocation'),
            ]];
        }

        $product = $this->editingProduct();
        foreach ($rows as $index => $row) {
            $flashPrice = $row['flash_sale_price'] ?? null;
            $allocation = $row['flash_sale_allocation'] ?? null;
            if (blank($flashPrice) && blank($allocation)) {
                continue;
            }

            $prefix = $isSingle ? '' : "variants.{$index}.";
            $priceField = $prefix.'flash_sale_price';
            $allocationField = $prefix.'flash_sale_allocation';
            if (blank($flashPrice)) {
                $validator->errors()->add($priceField, 'Enter a flash-sale price when setting an allocation.');
            }
            if (blank($allocation)) {
                $validator->errors()->add($allocationField, 'Set how many units can use the flash-sale price.');
            }

            $basePrice = $row['price'] ?? $this->input('price');
            $discountType = strtolower((string) ($row['discount_type'] ?? $this->input('discount_type', 'none')));
            $discountValue = (float) ($row['discount_value'] ?? $this->input('discount_value', 0));
            if (filled($flashPrice) && is_numeric($basePrice)) {
                $basePrice = (float) $basePrice;
                $regularDiscount = match ($discountType) {
                    'fixed' => min($basePrice, max(0, $discountValue)),
                    'percentage' => min($basePrice, $basePrice * min(100, max(0, $discountValue)) / 100),
                    default => 0,
                };
                $regularPrice = round(max(0, $basePrice - $regularDiscount), 2);
                if ((float) $flashPrice >= $regularPrice) {
                    $validator->errors()->add($priceField, 'Flash-sale price must be lower than the current regular Seller price.');
                }
            }

            $stock = $row['stock'] ?? $this->input('stock');
            if (filled($allocation) && is_numeric($stock)) {
                $sold = 0;
                $variantId = $row['id'] ?? null;
                if ($product && filled($variantId)) {
                    $sold = (int) $product->variants()->whereKey((int) $variantId)->value('flash_sale_sold');
                }
                $allocationCount = (int) $allocation;
                if ($allocationCount < $sold) {
                    $validator->errors()->add($allocationField, 'Flash-sale allocation cannot be lower than units already sold at the flash price.');
                } elseif ($allocationCount - $sold > (int) $stock) {
                    $validator->errors()->add($allocationField, 'Flash-sale allocation cannot exceed the variant stock available for this sale.');
                }
            }
        }
    }

    private function validateDiscount($validator, array $rows, string $attribute, mixed $fallbackPrice = null): void
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = (float) ($row['discount_value'] ?? $row['discount'] ?? 0);
            $type = strtolower((string) ($row['discount_type'] ?? ($value > 0 ? 'percentage' : 'none')));
            $price = $row['price'] ?? $fallbackPrice;
            $prefix = $attribute !== '' ? $attribute.'.'.$index : '';

            if ($type === 'percentage' && $value > 100) {
                $validator->errors()->add(
                    $prefix !== '' ? $prefix.'.discount_value' : 'discount_value',
                    'Discount cannot exceed 100%.'
                );
            }

            if ($type === 'fixed' && $price !== null && $price !== '' && $value > (float) $price) {
                $validator->errors()->add(
                    $prefix !== '' ? $prefix.'.discount_value' : 'discount_value',
                    'Fixed discount cannot exceed the product price.'
                );
            }
        }
    }

    private function preservingUnavailableCategory(): bool
    {
        $product = $this->editingProduct();
        if (! $product) {
            return false;
        }

        $categoryId = (int) $this->input('category_id');
        if ($categoryId <= 0 || (int) $product->category_id !== $categoryId) {
            return false;
        }

        return ! Category::query()
            ->whereKey($categoryId)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->exists();
    }

    private function editingProduct(): ?Product
    {
        $product = $this->route('product');
        if (! $product instanceof Product) {
            return null;
        }

        return (int) $product->seller_profile_id === (int) $this->user()?->sellerProfile?->id
            ? $product
            : null;
    }

    private function isPublishing(): bool
    {
        return strtoupper((string) $this->input('status', 'DRAFT')) === 'ACTIVE';
    }

    private function validateImageCount($validator): void
    {
        $product = $this->editingProduct();
        $deleteIds = collect((array) $this->input('delete_image_ids', []))->map(fn ($id): int => (int) $id)->all();
        $existingCount = $product
            ? $product->images()->when($deleteIds !== [], fn ($query) => $query->whereNotIn('id', $deleteIds))->count()
            : 0;

        if ($existingCount + $this->uploadedImages()->count() > 10) {
            $validator->errors()->add('images', 'A product may have at most 10 images, including the primary image.');
        }
    }

    private function validatePublicationRequirements($validator): void
    {
        $product = $this->editingProduct();
        $deleteIds = collect((array) $this->input('delete_image_ids', []))->map(fn ($id): int => (int) $id)->all();
        $existingImages = $product
            ? $product->images()->when($deleteIds !== [], fn ($query) => $query->whereNotIn('id', $deleteIds))->get()
            : collect();
        $hasValidStoredImage = $existingImages->contains(fn ($image): bool => filled($image->file_path)
            && Storage::disk('public')->exists($image->file_path));
        $hasValidUpload = $this->uploadedImages()->isNotEmpty();
        $mayKeepLegacyImageGap = $product?->status === 'ACTIVE'
            && ! $hasValidStoredImage && ! $hasValidUpload && $deleteIds === [];

        if (! $hasValidStoredImage && ! $hasValidUpload && ! $mayKeepLegacyImageGap) {
            $validator->errors()->add('image', 'Upload a valid primary product image before publishing.');
        }

        if ($this->input('product_type') !== 'variations') {
            return;
        }

        $options = collect((array) $this->input('options', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row): bool => filled($row['name'] ?? null) || filled($row['values'] ?? null))
            ->values();
        if ($options->isEmpty()) {
            $validator->errors()->add('options', 'Add at least one variation type and option value before publishing.');
        }
        foreach ($options as $index => $option) {
            if (blank($option['name'] ?? null)) {
                $validator->errors()->add("options.{$index}.name", 'Enter a name for this variation type.');
            }
            if (blank($option['values'] ?? null)) {
                $validator->errors()->add("options.{$index}.values", 'Enter at least one value for this variation type.');
            }
        }

        $variants = collect((array) $this->input('variants', []))
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn ($row): bool => collect(['id', 'sku', 'values', 'price', 'stock'])
                ->contains(fn (string $field): bool => filled($row[$field] ?? null)))
            ->values();
        if ($variants->isEmpty()) {
            if (blank($this->input('price'))) {
                $validator->errors()->add('price', 'Enter a price for the variation combinations.');
            }
            if (blank($this->input('stock'))) {
                $validator->errors()->add('stock', 'Enter a stock quantity for the variation combinations.');
            }

            return;
        }
        foreach ($variants as $index => $variant) {
            if (blank($variant['values'] ?? null)) {
                $validator->errors()->add("variants.{$index}.values", 'Select a value for every variation type.');
            }
            if (blank($variant['price'] ?? null) && blank($this->input('price'))) {
                $validator->errors()->add("variants.{$index}.price", 'Enter a price for this variation.');
            }
            if (blank($variant['stock'] ?? null) && blank($this->input('stock'))) {
                $validator->errors()->add("variants.{$index}.stock", 'Enter a stock quantity for this variation.');
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int, UploadedFile> */
    private function uploadedImages(): \Illuminate\Support\Collection
    {
        $extra = $this->file('images', []);
        $extra = is_array($extra) ? $extra : [$extra];

        return collect([$this->file('image')])
            ->merge($extra)
            ->filter(fn ($file): bool => $file instanceof UploadedFile && $file->isValid())
            ->values();
    }
}
