<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\ProductView;
use App\Models\Seller\Product;
use App\Models\User;
use Illuminate\Support\Collection;

class ProductViewService
{
    /** @param array<int, mixed> $productIds */
    public function importLegacySessionViews(User $buyer, array $productIds): void
    {
        if (! $buyer->isAccountType(User::TYPE_BUYER)) {
            return;
        }

        $orderedIds = collect($productIds)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->take(30)
            ->values();

        if ($orderedIds->isEmpty()) {
            return;
        }

        $validIds = Product::query()
            ->whereIn('id', $orderedIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
        $positions = $orderedIds->flip();
        $now = now();

        $rows = $validIds->map(function (int $productId) use ($buyer, $positions, $now): array {
            $viewedAt = $now->copy()->subSeconds((int) $positions->get($productId, 0));

            return [
                'buyer_user_id' => $buyer->id,
                'product_id' => $productId,
                'viewed_at' => $viewedAt,
                'created_at' => $viewedAt,
                'updated_at' => $viewedAt,
            ];
        })->all();

        if ($rows !== []) {
            ProductView::query()->insertOrIgnore($rows);
        }
    }

    public function record(User $buyer, Product $product): void
    {
        if (! $buyer->isAccountType(User::TYPE_BUYER)) {
            return;
        }

        $now = now();

        ProductView::query()->upsert(
            [[
                'buyer_user_id' => $buyer->id,
                'product_id' => $product->id,
                'viewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['buyer_user_id', 'product_id'],
            ['viewed_at', 'updated_at'],
        );
    }

    /** @return Collection<int, int> */
    public function recentProductIds(User $buyer, int $limit = 30): Collection
    {
        return ProductView::query()
            ->where('buyer_user_id', $buyer->id)
            ->orderByDesc('viewed_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->pluck('product_id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }
}
