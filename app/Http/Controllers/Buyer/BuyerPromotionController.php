<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerPromotion;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\SellerPromotionUsageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BuyerPromotionController extends Controller
{
    public function addBundle(Request $request, int $promotion, CartService $cart): RedirectResponse
    {
        $data = $request->validate([
            'sets' => ['nullable', 'integer', 'min:1', 'max:99'],
            'choices' => ['nullable', 'array', 'max:10'],
            'choices.*' => ['required', 'integer', 'exists:product_variants,id'],
        ]);
        $sets = max(1, (int) ($data['sets'] ?? 1));

        DB::transaction(function () use ($request, $promotion, $sets, $cart, $data): void {
            $offer = $this->currentPromotion($promotion, SellerPromotion::TYPE_BUNDLE)
                ->load('components.productVariant.product');
            $componentGroups = $offer->components->groupBy(fn ($component): string => filled($component->choice_group)
                ? 'choice:'.$component->choice_group
                : 'variant:'.$component->id);
            $quantities = [];
            foreach ($componentGroups as $groupKey => $options) {
                $component = $options->first();
                $variantId = $component->choice_group
                    ? (int) ($data['choices'][$component->choice_group] ?? 0)
                    : (int) $component->product_variant_id;
                $variant = $options->firstWhere('product_variant_id', $variantId)?->productVariant;
                if (! $variant || ! $variant->is_active
                    || (int) $variant->product?->seller_profile_id !== (int) $offer->seller_profile_id
                    || $variant->product?->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['choices.'.$component->choice_group => 'Choose an available option for every mix-and-match group.']);
                }
                $quantities[(int) $variant->id] = ($quantities[(int) $variant->id] ?? 0) + ((int) $component->quantity * $sets);
            }
            if ($componentGroups->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'This bundle is no longer available.']);
            }

            $cart->addMany($request->user(), $quantities);
            $locked = SellerPromotion::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertOfferCanBeClaimed($locked);
            if (app(SellerPromotionUsageService::class)->remainingRedemptions($locked, $request->user()) < $sets) {
                throw ValidationException::withMessages(['cart' => 'This offer has reached its redemption limit.']);
            }
        }, attempts: 3);

        return back()->with('buyer_notice', 'Bundle items were added to your cart. The bundle price is confirmed at checkout.');
    }

    public function claimBuyXGetY(Request $request, int $promotion, CartService $cart): RedirectResponse
    {
        $data = $request->validate([
            'sets' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reward_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
        ]);
        $sets = max(1, (int) ($data['sets'] ?? 1));

        DB::transaction(function () use ($request, $promotion, $sets, $data, $cart): void {
            $offer = $this->currentPromotion($promotion, SellerPromotion::TYPE_BUY_X_GET_Y)
                ->load(['buyVariant.product', 'rewardVariant.product']);
            $buyVariant = $offer->buyVariant;
            if (! $buyVariant || ! $buyVariant->is_active
                || (int) $buyVariant->product?->seller_profile_id !== (int) $offer->seller_profile_id
                || $buyVariant->product?->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['cart' => 'The qualifying item for this offer is no longer available.']);
            }

            $rewardVariantId = $offer->reward_variant_id
                ? (int) $offer->reward_variant_id
                : (int) ($data['reward_variant_id'] ?? 0);
            $rewardVariant = ProductVariant::query()
                ->whereKey($rewardVariantId)
                ->where('is_active', true)
                ->with('product')
                ->first();
            $targetRewardProductId = (int) ($offer->reward_product_id ?? $offer->rewardVariant?->product_id ?? 0);
            if (! $rewardVariant || (int) $rewardVariant->product_id !== $targetRewardProductId
                || (int) $rewardVariant->product?->seller_profile_id !== (int) $offer->seller_profile_id
                || $rewardVariant->product?->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['reward_variant_id' => 'Choose an active reward variation from this Seller.']);
            }
            if ((float) $buyVariant->final_price < 0.01 || (float) $rewardVariant->final_price < 0.01) {
                throw ValidationException::withMessages(['cart' => 'Promotional items must have a normal selling price of at least PHP 0.01.']);
            }

            $buyVariantId = (int) $buyVariant->id;
            $rewardId = (int) $rewardVariant->id;
            $quantities = [$buyVariantId => (int) $offer->buy_quantity * $sets];
            $quantities[$rewardId] = ($quantities[$rewardId] ?? 0) + (int) $offer->reward_quantity * $sets;

            $cart->addMany($request->user(), $quantities);
            $locked = SellerPromotion::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $this->assertOfferCanBeClaimed($locked);
            if (app(SellerPromotionUsageService::class)->remainingRedemptions($locked, $request->user()) < $sets) {
                throw ValidationException::withMessages(['cart' => 'This offer has reached its redemption limit.']);
            }
        }, attempts: 3);

        return back()->with('buyer_notice', 'The qualifying item and reward were added to your cart. Your offer will be applied at checkout.');
    }

    private function currentPromotion(int $id, string $type): SellerPromotion
    {
        if (! Schema::hasColumn('seller_promotions', 'reward_product_id')
            || ! Schema::hasColumn('seller_promotions', 'reward_discount_percent')
            || ! Schema::hasColumn('seller_promotions', 'max_redemptions')
            || ! Schema::hasColumn('seller_promotions', 'max_per_buyer')
            || ! Schema::hasColumn('seller_promotions', 'redemptions_used')
            || ! Schema::hasColumn('seller_promotion_components', 'choice_group')) {
            throw ValidationException::withMessages(['cart' => 'Seller offers are temporarily unavailable. Refresh and try again later.']);
        }

        $offer = SellerPromotion::query()->whereKey($id)->firstOrFail();
        if ($offer->promotion_type !== $type) {
            throw ValidationException::withMessages(['cart' => 'This offer is no longer available.']);
        }
        $this->assertOfferCanBeClaimed($offer);

        return $offer;
    }

    private function assertOfferCanBeClaimed(SellerPromotion $offer): void
    {
        if (! $offer->is_active
            || ($offer->starts_at && $offer->starts_at->isFuture())
            || ($offer->ends_at && $offer->ends_at->lessThanOrEqualTo(now()))) {
            throw ValidationException::withMessages(['cart' => 'This offer is paused, expired, or not available yet.']);
        }
    }
}
