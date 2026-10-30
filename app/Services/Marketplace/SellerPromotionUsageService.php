<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\OrderItem;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerPromotion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SellerPromotionUsageService
{
    public function remainingRedemptions(SellerPromotion $promotion, ?User $buyer = null): int
    {
        $remaining = $promotion->max_redemptions === null
            ? PHP_INT_MAX
            : max(0, (int) $promotion->max_redemptions - (int) $promotion->redemptions_used);

        if ($buyer && $promotion->max_per_buyer !== null) {
            $remaining = min($remaining, max(0, (int) $promotion->max_per_buyer - $this->usedByBuyer($promotion, $buyer)));
        }

        return $remaining;
    }

    public function usedByBuyer(SellerPromotion $promotion, User $buyer): int
    {
        if (! Schema::hasColumn('order_items', 'promotion_snapshot')) {
            return 0;
        }

        $snapshots = OrderItem::query()
            ->whereNotNull('promotion_snapshot')
            ->whereHas('sellerOrder', fn ($sellerOrders) => $sellerOrders
                ->where('seller_profile_id', $promotion->seller_profile_id)
                ->where('status', '<>', 'CANCELLED')
                ->whereHas('order', fn ($orders) => $orders
                    ->where('buyer_user_id', $buyer->id)
                    ->where('status', '<>', 'CANCELLED')))
            ->get(['promotion_snapshot']);

        return $snapshots->sum(function (OrderItem $item) use ($promotion): int {
            return collect((array) $item->promotion_snapshot)
                ->filter(fn (array $snapshot): bool => (int) ($snapshot['promotion_id'] ?? 0) === (int) $promotion->id)
                ->sum(fn (array $snapshot): int => max(0, (int) ($snapshot['redemption_sets'] ?? 0)));
        });
    }

    public function record(SellerPromotion $promotion, int $sets): void
    {
        if ($sets < 1 || ! Schema::hasColumn('seller_promotions', 'redemptions_used')) {
            return;
        }

        $promotion->redemptions_used = (int) $promotion->redemptions_used + $sets;
        $promotion->save();
    }

    public function releaseForSellerOrder(SellerOrder $sellerOrder, ?Collection $items = null): void
    {
        if (! Schema::hasColumn('seller_promotions', 'redemptions_used')) {
            return;
        }

        $items ??= $sellerOrder->items()->get();
        $released = [];
        foreach ($items as $item) {
            foreach ((array) $item->promotion_snapshot as $snapshot) {
                $promotionId = (int) ($snapshot['promotion_id'] ?? 0);
                $sets = max(0, (int) ($snapshot['redemption_sets'] ?? 0));
                if ($promotionId > 0 && $sets > 0) {
                    $released[$promotionId] = ($released[$promotionId] ?? 0) + $sets;
                }
            }
        }

        foreach ($released as $promotionId => $sets) {
            $promotion = SellerPromotion::query()->whereKey($promotionId)->lockForUpdate()->first();
            if (! $promotion) {
                continue;
            }
            $promotion->redemptions_used = max(0, (int) $promotion->redemptions_used - $sets);
            $promotion->save();
        }
    }
}
