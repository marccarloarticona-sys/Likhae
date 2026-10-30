<?php

namespace App\Services\Marketplace;

use App\Models\Seller\ProductVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/** Resolves the single unit price used by buyer-facing catalog and checkout flows. */
class ProductPriceResolver
{
    /** @return array{regular_price:float,price:float,discount_amount:float,source:string,discount_type:string,discount_value:float} */
    public function resolve(ProductVariant $variant): array
    {
        $base = round(max(0, (float) $variant->getAttribute('price')), 2);
        $type = $variant->resolved_discount_type;
        $value = max(0, (float) ($variant->getAttribute('discount_value') ?? 0));
        $flash = $this->activeFlashSalePrice($variant);

        if ($flash !== null) {
            $price = $base > 0 ? max(0.01, min($base, $flash)) : 0.0;
            $source = 'flash_sale';
        } else {
            $price = $this->productDiscountPrice($variant);
            $source = $price < $base ? 'product_discount' : 'regular';
        }

        return [
            'regular_price' => $base,
            'price' => round($price, 2),
            'discount_amount' => round(max(0, $base - $price), 2),
            'source' => $source,
            'discount_type' => $type,
            'discount_value' => $value,
        ];
    }

    public function activeFlashSalePrice(ProductVariant $variant): ?float
    {
        $value = $variant->getAttribute('flash_sale_price');
        if ($value === null || (float) $value <= 0 || (int) ($variant->stock ?? 0) <= 0 || $this->remainingFlashSaleUnits($variant) <= 0) {
            return null;
        }

        $product = $variant->relationLoaded('product') ? $variant->getRelation('product') : $variant->product;
        if ((bool) ($product?->flash_sale_is_paused ?? false)) {
            return null;
        }
        if (! $this->windowIsActive($product)) {
            return null;
        }

        return round((float) $value, 2);
    }

    public function remainingFlashSaleUnits(ProductVariant $variant): int
    {
        $allocated = max(0, (int) ($variant->flash_sale_allocation ?? 0) - (int) ($variant->flash_sale_sold ?? 0));

        return min($allocated, max(0, (int) ($variant->stock ?? 0)));
    }

    public function productDiscountPrice(ProductVariant $variant): float
    {
        $base = round(max(0, (float) $variant->getAttribute('price')), 2);
        if ($base < 0.01) {
            return $base;
        }
        $product = $variant->relationLoaded('product') ? $variant->getRelation('product') : $variant->product;
        if (! $this->discountEnabled($product) || ! $this->discountWindowIsActive($variant, $product)) {
            return $base;
        }

        $value = max(0, (float) ($variant->getAttribute('discount_value') ?? 0));
        $amount = match ($variant->resolved_discount_type) {
            'fixed' => min($value, max(0, $base - 0.01)),
            'percentage' => min($base * min(100, $value) / 100, max(0, $base - 0.01)),
            default => 0.0,
        };

        return round(max(0.01, $base - $amount), 2);
    }

    /** SQL equivalent of resolve(), used for catalog range and price sorting. */
    public function sqlExpression(string $productAlias = 'products', string $variantAlias = 'product_variants'): string
    {
        $price = "{$variantAlias}.price";
        $type = "LOWER(TRIM(COALESCE({$variantAlias}.discount_type, '')))";
        $value = "COALESCE({$variantAlias}.discount_value, 0)";
        $window = '(1 = 1)';
        $now = str_replace("'", "''", now()->toDateTimeString());
        if (Schema::hasColumn('products', 'flash_sale_starts_at') && Schema::hasColumn('products', 'flash_sale_ends_at')) {
            $window = "(({$productAlias}.flash_sale_starts_at IS NULL OR {$productAlias}.flash_sale_starts_at <= '{$now}') AND ({$productAlias}.flash_sale_ends_at IS NULL OR {$productAlias}.flash_sale_ends_at > '{$now}'))";
        }
        if (Schema::hasColumn('products', 'flash_sale_is_paused')) {
            $window = "({$window} AND COALESCE({$productAlias}.flash_sale_is_paused, 0) = 0)";
        }

        $flashReady = Schema::hasColumn('product_variants', 'flash_sale_price')
            && Schema::hasColumn('product_variants', 'flash_sale_allocation')
            && Schema::hasColumn('product_variants', 'flash_sale_sold');
        $flashActive = $flashReady
            ? "({$variantAlias}.flash_sale_price IS NOT NULL AND {$variantAlias}.flash_sale_price > 0 AND {$variantAlias}.flash_sale_allocation > {$variantAlias}.flash_sale_sold AND {$variantAlias}.stock > 0 AND {$window})"
            : '(1 = 0)';
        $discountWindow = Schema::hasColumn('products', 'discount_starts_at') && Schema::hasColumn('products', 'discount_ends_at')
            ? "(({$productAlias}.discount_starts_at IS NULL OR {$productAlias}.discount_starts_at <= '{$now}') AND ({$productAlias}.discount_ends_at IS NULL OR {$productAlias}.discount_ends_at > '{$now}'))"
            : ($flashReady ? "({$variantAlias}.flash_sale_price IS NOT NULL OR {$window})" : $window);
        $enabled = Schema::hasColumn('products', 'discounts_enabled')
            ? "COALESCE({$productAlias}.discounts_enabled, 1) = 1"
            : '(1 = 1)';

        return "CAST(CASE
            WHEN {$flashActive} THEN CASE WHEN {$variantAlias}.flash_sale_price < {$price} THEN {$variantAlias}.flash_sale_price ELSE {$price} END
            WHEN {$enabled} AND {$discountWindow} AND {$price} > 0.01 AND {$type} = 'fixed' AND {$value} > 0 THEN CASE WHEN {$value} >= {$price} - 0.01 THEN 0.01 ELSE {$price} - {$value} END
            WHEN {$enabled} AND {$discountWindow} AND {$price} > 0 AND ({$type} = 'percentage' OR ({$type} NOT IN ('fixed', 'percentage', 'none') AND {$value} > 0)) AND {$value} > 0 THEN CASE WHEN {$value} >= 100 OR {$price} <= 0.01 THEN 0.01 ELSE {$price} * (1 - {$value} / 100) END
            ELSE {$price}
        END AS DECIMAL(12, 2))";
    }

    private function discountEnabled(mixed $product): bool
    {
        if (! $product || ! Schema::hasColumn('products', 'discounts_enabled')) {
            return true;
        }

        return (bool) ($product->getAttribute('discounts_enabled') ?? true);
    }

    private function discountWindowIsActive(ProductVariant $variant, mixed $product): bool
    {
        if ($product && Schema::hasColumn('products', 'discount_starts_at') && Schema::hasColumn('products', 'discount_ends_at')) {
            $now = now();
            $startsAt = $product->getAttribute('discount_starts_at');
            $endsAt = $product->getAttribute('discount_ends_at');

            return (! $startsAt || Carbon::parse($startsAt)->lessThanOrEqualTo($now))
                && (! $endsAt || Carbon::parse($endsAt)->greaterThan($now));
        }

        // Before the additive schedule columns exist, retain the legacy rule.
        return $variant->getAttribute('flash_sale_price') !== null || $this->windowIsActive($product);
    }

    private function windowIsActive(mixed $product): bool
    {
        if (! $product) {
            return true;
        }
        $now = now();
        $startsAt = $product->getAttribute('flash_sale_starts_at');
        $endsAt = $product->getAttribute('flash_sale_ends_at');

        return (! $startsAt || Carbon::parse($startsAt)->lessThanOrEqualTo($now))
            && (! $endsAt || Carbon::parse($endsAt)->greaterThan($now));
    }
}
