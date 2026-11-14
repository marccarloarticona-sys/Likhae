<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Product;

class ProductStockPayloadService
{
    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, array<string, mixed>>
     */
    public function forProducts(array $productIds): array
    {
        $ids = collect($productIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $visibleProducts = Product::query()
            ->visible()
            ->whereIn('id', $ids)
            ->with([
                'sellerProfile:id,vacation_mode',
                'variants' => fn ($query) => $query
                    ->select(['id', 'product_id', 'stock', 'is_active'])
                    ->orderBy('id'),
            ])
            ->get(['id', 'seller_profile_id'])
            ->keyBy('id');

        return $ids
            ->mapWithKeys(function (int $id) use ($visibleProducts): array {
                /** @var Product|null $product */
                $product = $visibleProducts->get($id);

                if (! $product) {
                    return [$id => [
                        'product_id' => $id,
                        'visible' => false,
                        'seller_on_vacation' => false,
                        'total_stock' => 0,
                        'variants' => [],
                    ]];
                }

                $variants = $product->variants->mapWithKeys(function ($variant): array {
                    $isActive = (bool) $variant->is_active;

                    return [(string) $variant->id => [
                        'stock' => $isActive ? max(0, (int) $variant->stock) : 0,
                        'is_active' => $isActive,
                    ]];
                });

                return [$id => [
                    'product_id' => (int) $product->id,
                    'visible' => true,
                    'seller_on_vacation' => (bool) $product->sellerProfile?->vacation_mode,
                    'total_stock' => (int) $product->variants
                        ->where('is_active', true)
                        ->sum('stock'),
                    'variants' => $variants->all(),
                ]];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    public function forProduct(int $productId): array
    {
        return $this->forProducts([$productId])[$productId] ?? [
            'product_id' => $productId,
            'visible' => false,
            'seller_on_vacation' => false,
            'total_stock' => 0,
            'variants' => [],
        ];
    }
}
