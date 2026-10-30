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
        $idempotencyKey = $this->header('Idempotency-Key', $this->input('idempotency_key'));

        $this->merge([
            'product_type' => strtolower((string) $this->input('product_type', 'single')),
            'status' => strtoupper((string) $this->input('status', $this->input('listing_status', 'DRAFT'))),
            'idempotency_key' => is_string($idempotencyKey) ? strtolower(trim($idempotencyKey)) : null,
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
            'description' => [Rule::requiredIf(fn (): bool => $this->isPublishing()), 'nullable', 'string', 'max:11000'],
            'purchase_limit_per_buyer' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'ARCHIVED'])],
            'idempotency_key' => ['nullable', 'uuid'],
            'price' => [Rule::requiredIf(fn (): bool => $this->isPublishing() && $this->input('product_type') === 'single'), 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'stock' => [Rule::requiredIf(fn (): bool => $this->isPublishing() && $this->input('product_type') === 'single'), 'nullable', 'integer', 'min:0', 'max:999999'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.001', 'max:9999999.999'],
            'sku' => ['nullable', 'string', 'max:100'],
            'single_variant_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(['cod', 'online', 'cod_online'])],
            'is_active' => ['nullable', 'boolean'],

            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'description_images' => ['nullable', 'array', 'max:10'],
            'description_images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
            'variants.*.payment_method' => ['nullable', Rule::in(['cod', 'online', 'cod_online'])],
            'variants.*.is_active' => ['nullable', 'boolean'],
            'variants.*.product_image_ref' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'image.image' => 'Choose a valid primary product image. Description pictures cannot be used as the product image.',
            'image.mimes' => 'Primary product image must be a JPG, PNG, or WebP file.',
            'image.max' => 'Primary product image must be 5 MB or smaller.',
            'images.*.image' => 'Choose valid product images in JPG, PNG, or WebP format.',
            'images.*.mimes' => 'Product images must be JPG, PNG, or WebP files.',
            'images.*.max' => 'Each product image must be 5 MB or smaller.',
            'description_images.*.image' => 'Choose a valid description picture in JPG, PNG, or WebP format.',
            'description_images.*.mimes' => 'Description pictures must be JPG, PNG, or WebP files.',
            'description_images.*.max' => 'Each description picture must be 5 MB or smaller.',
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
            $this->validateDescriptionImages($validator);
            if ($this->isPublishing()) {
                $this->validatePublicationRequirements($validator);
            }

        });
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

    private function validateDescriptionImages($validator): void
    {
        $description = (string) $this->input('description', '');
        $readableDescription = preg_replace('/\[\[(?:image|description-image):[^\]]+\]\]/', '', $description) ?? $description;
        if (mb_strlen($readableDescription) > 10000) {
            $validator->errors()->add('description', 'Product description must be 10,000 characters or fewer, not counting inserted pictures.');
        }

        $legacyMatchCount = preg_match_all('/\[\[image:([^\]]+)\]\]/', $description, $legacyMatches);
        $descriptionMatchCount = preg_match_all('/\[\[description-image:([^\]]+)\]\]/', $description, $descriptionMatches);
        if (! $legacyMatchCount && ! $descriptionMatchCount) {
            return;
        }

        if (($legacyMatchCount ?: 0) + ($descriptionMatchCount ?: 0) > 10) {
            $validator->errors()->add('description', 'Add up to 10 pictures in a product description.');
        }

        $product = $this->editingProduct();
        $deletedImageIds = collect((array) $this->input('delete_image_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->all();
        $additionalUploads = $this->file('images', []);
        $additionalUploads = is_array($additionalUploads) ? $additionalUploads : [$additionalUploads];

        foreach ($legacyMatches[1] ?? [] as $reference) {
            if (preg_match('/^image:(\d+)$/', $reference, $imageMatch)) {
                $imageId = (int) $imageMatch[1];
                $belongsToProduct = $product
                    && $product->images()->whereKey($imageId)->exists()
                    && ! in_array($imageId, $deletedImageIds, true);

                if (! $belongsToProduct) {
                    $validator->errors()->add('description', 'Remove or reselect a description picture that is no longer in this product’s image library.');
                }

                continue;
            }

            if ($reference === 'upload:primary') {
                $file = $this->file('image');
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    $validator->errors()->add('description', 'Reupload the primary picture used in the description before saving.');
                }

                continue;
            }

            if (preg_match('/^upload:additional:(\d+)$/', $reference, $uploadMatch)) {
                $file = $additionalUploads[(int) $uploadMatch[1]] ?? null;
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    $validator->errors()->add('description', 'Reupload each additional picture used in the description before saving.');
                }

                continue;
            }

            $validator->errors()->add('description', 'A description picture reference is invalid. Choose an image from this product’s uploaded image library.');
        }

        $descriptionUploads = $this->file('description_images', []);
        $descriptionUploads = is_array($descriptionUploads) ? $descriptionUploads : [$descriptionUploads];
        foreach ($descriptionMatches[1] ?? [] as $reference) {
            if (preg_match('/^stored:((?:[A-Za-z0-9]{40}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:jpg|jpeg|png|webp))$/i', $reference, $storedMatch)) {
                $filename = $storedMatch[1];
                $path = $product
                    ? "sellers/{$product->seller_profile_id}/products/{$product->id}/descriptions/{$filename}"
                    : null;
                if (! $path || ! Storage::disk('public')->exists($path)) {
                    $validator->errors()->add('description', 'Remove or reupload a description picture that is no longer available.');
                }

                continue;
            }

            if (preg_match('/^upload:(\d+)$/', $reference, $uploadMatch)) {
                $file = $descriptionUploads[(int) $uploadMatch[1]] ?? null;
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    $validator->errors()->add('description', 'Reupload each picture inserted into the description before saving.');
                }

                continue;
            }

            $validator->errors()->add('description', 'A description picture reference is invalid. Upload a separate picture for the description.');
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
            $validator->errors()->add('image', 'Upload a primary product image (JPG, PNG, or WebP, up to 5 MB) before publishing. Description pictures do not count as the product image.');
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
