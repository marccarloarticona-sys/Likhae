<?php

namespace App\Http\Requests;

use App\Models\Seller\Category;
use App\Models\Seller\Product;
use Illuminate\Foundation\Http\FormRequest;
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
        $discountType = strtolower((string) $this->input('discount_type', 'none'));

        $this->merge([
            'product_type' => strtolower((string) $this->input('product_type', 'single')),
            'status' => strtoupper((string) $this->input('status', $this->input('listing_status', 'DRAFT'))),
            'discount_type' => in_array($discountType, ['none', 'percentage', 'fixed'], true) ? $discountType : 'none',
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
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'ARCHIVED'])],

            'price' => ['required_if:product_type,single', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'stock' => ['required_if:product_type,single', 'nullable', 'integer', 'min:0', 'max:999999'],
            'sku' => ['nullable', 'string', 'max:100'],
            'single_variant_id' => ['nullable', 'integer'],
            'discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'payment_method' => ['nullable', Rule::in(['cod', 'online', 'cod_online'])],
            'is_active' => ['nullable', 'boolean'],

            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'images' => ['nullable', 'array', 'max:9'],
            'images.*' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'delete_image_ids' => ['nullable', 'array', 'max:10'],
            'delete_image_ids.*' => ['integer', Rule::exists('product_images', 'id')],

            'options' => ['required_if:product_type,variations', 'nullable', 'array', 'max:3'],
            'options.*.name' => ['required_with:options.*.values', 'nullable', 'string', 'max:100'],
            'options.*.values' => ['required_with:options.*.name', 'nullable', 'string', 'max:1000'],

            'variants' => ['nullable', 'array', 'max:100'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:100', 'distinct'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'variants.*.values' => ['nullable', 'string', 'max:500'],
            'variants.*.discount_type' => ['nullable', Rule::in(['none', 'percentage', 'fixed'])],
            'variants.*.discount_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
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

            $this->validateDiscount($validator, (array) $this->input('variants', []), 'variants', $this->input('price'));
            $this->validateDiscount($validator, [[
                'discount_type' => $this->input('discount_type'),
                'discount_value' => $this->input('discount_value'),
                'price' => $this->input('price'),
            ]], '', $this->input('price'));
        });
    }

    private function validateDiscount($validator, array $rows, string $attribute, mixed $fallbackPrice = null): void
    {
        foreach ($rows as $index => $row) {
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
}
