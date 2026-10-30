<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerPromotion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SellerPromotionBuyerService
{
    /** @var array<int, Collection<int, SellerPromotion>> */
    private array $offersBySeller = [];

    /** @return Collection<int, SellerPromotion> */
    public function forSeller(int $sellerProfileId, ?User $buyer = null): Collection
    {
        $cacheKey = $sellerProfileId.':'.($buyer?->id ?? 'guest');
        if (isset($this->offersBySeller[$cacheKey])) {
            return $this->offersBySeller[$cacheKey];
        }
        if (! Schema::hasTable('seller_promotions')
            || ! Schema::hasTable('seller_promotion_components')
            || ! Schema::hasColumn('seller_promotions', 'reward_product_id')
            || ! Schema::hasColumn('seller_promotions', 'reward_discount_percent')
            || ! Schema::hasColumn('seller_promotions', 'max_redemptions')
            || ! Schema::hasColumn('seller_promotions', 'max_per_buyer')
            || ! Schema::hasColumn('seller_promotions', 'redemptions_used')
            || ! Schema::hasColumn('seller_promotion_components', 'choice_group')) {
            return $this->offersBySeller[$cacheKey] = collect();
        }

        $offers = SellerPromotion::query()
            ->where('seller_profile_id', $sellerProfileId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('max_redemptions')->orWhereColumn('redemptions_used', '<', 'max_redemptions'))
            ->with([
                'components.productVariant.product',
                'buyVariant.product',
                'rewardVariant.product',
                'rewardProduct.variants' => fn ($variants) => $variants->where('is_active', true)->with('optionValues.option'),
            ])
            ->orderBy('id')
            ->get()
            ->filter(fn (SellerPromotion $offer): bool => $this->hasAvailableTargets($offer))
            ->filter(function (SellerPromotion $offer) use ($buyer): bool {
                if (! $buyer) {
                    return true;
                }
                $remaining = app(SellerPromotionUsageService::class)->remainingRedemptions($offer, $buyer);
                $offer->setAttribute('buyer_redemptions_remaining', $remaining);

                return $remaining > 0;
            })
            ->values();

        return $this->offersBySeller[$cacheKey] = $offers;
    }

    /** @return Collection<int, SellerPromotion> */
    public function forProduct(Product $product, ?User $buyer = null): Collection
    {
        return $this->forSeller((int) $product->seller_profile_id, $buyer)
            ->filter(function (SellerPromotion $offer) use ($product): bool {
                if ($offer->promotion_type === SellerPromotion::TYPE_BUNDLE) {
                    return $offer->components->contains(fn ($component): bool => (int) $component->productVariant?->product_id === (int) $product->id);
                }

                return (int) $offer->buyVariant?->product_id === (int) $product->id
                    || (int) ($offer->reward_product_id ?? $offer->rewardVariant?->product_id ?? 0) === (int) $product->id;
            })
            ->values();
    }

    /** @return Collection<int, SellerPromotion> */
    public function forMarketplace(?User $buyer = null, int $limit = 12, ?string $type = null): Collection
    {
        if (! Schema::hasTable('seller_promotions')
            || ! Schema::hasTable('seller_promotion_components')
            || ! Schema::hasColumn('seller_promotions', 'reward_product_id')
            || ! Schema::hasColumn('seller_promotions', 'reward_discount_percent')
            || ! Schema::hasColumn('seller_promotions', 'max_redemptions')
            || ! Schema::hasColumn('seller_promotions', 'max_per_buyer')
            || ! Schema::hasColumn('seller_promotions', 'redemptions_used')
            || ! Schema::hasColumn('seller_promotion_components', 'choice_group')) {
            return collect();
        }

        return SellerPromotion::query()
            ->where('is_active', true)
            ->when($type !== null, fn ($query) => $query->where('promotion_type', $type))
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn ($query) => $query->whereNull('max_redemptions')->orWhereColumn('redemptions_used', '<', 'max_redemptions'))
            ->whereHas('sellerProfile', fn ($seller) => $seller
                ->where('status', 'ACTIVE')
                ->where('store_visibility', true)
                ->where('vacation_mode', false)
                ->whereHas('user', fn ($user) => $user
                    ->where('account_type', User::TYPE_SELLER)
                    ->where('status', User::STATUS_ACTIVE)))
            ->with([
                'sellerProfile',
                'components.productVariant.product.images',
                'buyVariant.product.images',
                'rewardVariant.product.images',
                'rewardProduct.images',
                'rewardProduct.variants' => fn ($variants) => $variants
                    ->where('is_active', true)
                    ->with('optionValues.option'),
            ])
            ->orderByRaw('CASE WHEN starts_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(24, min(120, $limit * 8)))
            ->get()
            ->filter(fn (SellerPromotion $offer): bool => $this->hasAvailableTargets($offer))
            ->filter(function (SellerPromotion $offer) use ($buyer): bool {
                if (! $buyer) {
                    return true;
                }

                $remaining = app(SellerPromotionUsageService::class)->remainingRedemptions($offer, $buyer);
                $offer->setAttribute('buyer_redemptions_remaining', $remaining);

                return $remaining > 0;
            })
            ->take(max(0, $limit))
            ->values();
    }

    private function hasAvailableTargets(SellerPromotion $offer): bool
    {
        if ($offer->promotion_type === SellerPromotion::TYPE_BUNDLE) {
            return $offer->components->isNotEmpty()
                && $offer->components
                    ->groupBy(fn ($component): string => filled($component->choice_group) ? 'choice:'.$component->choice_group : 'variant:'.$component->id)
                    ->every(fn ($options): bool => $options->contains(fn ($component): bool => $this->variantAvailable($component->productVariant, $offer)
                        && (int) $component->productVariant->stock >= (int) $component->quantity));
        }

        $buyAvailable = $this->variantAvailable($offer->buyVariant, $offer);
        if (! $buyAvailable || (int) $offer->buyVariant->stock < max(1, (int) $offer->buy_quantity)) {
            return false;
        }

        if ($offer->reward_variant_id) {
            return $this->variantAvailable($offer->rewardVariant, $offer)
                && (int) $offer->rewardVariant->stock >= max(1, (int) $offer->reward_quantity);
        }

        return $offer->rewardProduct
            && $offer->rewardProduct->status === 'ACTIVE'
            && $offer->rewardProduct->variants->contains(fn (ProductVariant $variant): bool => (int) $variant->stock > 0
                && (int) $variant->stock >= max(1, (int) $offer->reward_quantity)
                && (float) $variant->final_price >= 0.01);
    }

    private function variantAvailable(?ProductVariant $variant, SellerPromotion $offer): bool
    {
        return $variant !== null
            && (bool) $variant->is_active
            && (int) $variant->product?->seller_profile_id === (int) $offer->seller_profile_id
            && $variant->product?->status === 'ACTIVE'
            && (int) $variant->stock > 0
            && (float) $variant->final_price >= 0.01;
    }
}
