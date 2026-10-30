<?php

namespace App\Services\Buyer;

use App\Models\Admin\Notification;
use App\Models\Buyer\SellerFollower;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\Voucher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BuyerStoreNotificationService
{
    public function variantCreated(ProductVariant $variant): void
    {
        $this->queueFlashSaleUpdate((int) $variant->product_id);
        $product = $this->visibleProduct((int) $variant->product_id);
        $salePrice = $this->finalPrice(
            (float) $variant->getAttribute('price'),
            $variant->getAttribute('discount_type'),
            (float) $variant->getAttribute('discount_value'),
        );
        if (! $product || ! $variant->is_active || $salePrice >= (float) $variant->price) {
            return;
        }

        $event = implode('|', [
            'variant-created',
            $variant->id,
            $variant->created_at?->format('YmdHis.u'),
            $variant->price,
            $variant->discount_type,
            $variant->discount_value,
        ]);
        $this->notifySellerDiscount($product, $variant, $event, $salePrice);
    }

    public function variantUpdated(ProductVariant $variant): void
    {
        $original = $variant->getOriginal();
        $stockBefore = (int) ($original['stock'] ?? 0);
        $stockAfter = (int) $variant->stock;
        $product = null;

        if ($variant->wasChanged('stock') && $stockBefore <= 0 && $stockAfter > 0 && $variant->is_active) {
            $product = $this->visibleProduct((int) $variant->product_id);
            if ($product) {
                $this->notifyStoreFollowersOfRestock($product, $variant, $stockBefore, $stockAfter);
            }
        }

        if ($variant->wasChanged(['price', 'discount_type', 'discount_value', 'is_active'])) {
            $oldFinalPrice = $this->finalPrice(
                (float) ($original['price'] ?? 0),
                $original['discount_type'] ?? null,
                (float) ($original['discount_value'] ?? 0),
            );
            $newFinalPrice = $this->finalPrice(
                (float) $variant->getAttribute('price'),
                $variant->getAttribute('discount_type'),
                (float) $variant->getAttribute('discount_value'),
            );
            $isNewDiscount = $variant->is_active
                && $newFinalPrice < (float) $variant->price
                && (! (bool) ($original['is_active'] ?? false) || $newFinalPrice < $oldFinalPrice);
            $isPriceDrop = $variant->is_active && $newFinalPrice < $oldFinalPrice;
            if ($isNewDiscount || $isPriceDrop) {
                $product ??= $this->visibleProduct((int) $variant->product_id);
                if ($product) {
                    $event = implode('|', [
                        'variant-discount-updated',
                        $variant->id,
                        $variant->updated_at?->format('YmdHis.u'),
                        $oldFinalPrice,
                        $newFinalPrice,
                        $variant->discount_type,
                        $variant->discount_value,
                    ]);
                    $isDiscounted = $newFinalPrice < (float) $variant->price;
                    $message = $isDiscounted
                        ? $product->name.' now has a seller discount at '.$product->sellerProfile->business_name.'. The current price is ₱'.number_format($newFinalPrice, 2).'.'
                        : 'The price of '.$product->name.' at '.$product->sellerProfile->business_name.' has dropped to ₱'.number_format($newFinalPrice, 2).'.';
                    $this->notifyProductFollowers(
                        $product,
                        $isDiscounted ? 'A store discount is available' : 'A product price has dropped',
                        $message,
                        $event,
                        (int) $product->id,
                    );
                }
            }
        }

        if ($variant->wasChanged(['flash_sale_price', 'flash_sale_allocation'])) {
            $this->queueFlashSaleUpdate((int) $variant->product_id);
        }
    }

    public function productActivated(Product $product): void
    {
        $visibleProduct = $this->visibleProduct((int) $product->id);
        if (! $visibleProduct) {
            return;
        }

        $variants = ProductVariant::query()
            ->where('product_id', $visibleProduct->id)
            ->where('is_active', true)
            ->get();

        if ($variants->contains(fn (ProductVariant $variant): bool => $variant->discount_amount > 0)) {
            $event = implode('|', [
                'product-activated-with-discount',
                $visibleProduct->id,
                $visibleProduct->updated_at?->format('YmdHis.u'),
            ]);
            $this->notifySellerDiscount($visibleProduct, null, $event);
        }

        $this->queueFlashSaleUpdate((int) $product->id);
    }

    public function productFlashSaleScheduleChanged(Product $product): void
    {
        $this->queueFlashSaleUpdate((int) $product->id);
    }

    public function sellerPromotionChanged(int $promotionId): void
    {
        DB::afterCommit(function () use ($promotionId): void {
            $promotion = SellerPromotion::query()
                ->with(['sellerProfile.user', 'components.productVariant', 'buyVariant', 'rewardVariant'])
                ->find($promotionId);
            if (! $promotion || ! $promotion->is_active || ! $this->saleWindowIsRelevant($promotion->starts_at, $promotion->ends_at)) {
                return;
            }

            $seller = $this->visibleSeller((int) $promotion->seller_profile_id);
            if (! $seller) {
                return;
            }

            $variantIds = $promotion->promotion_type === SellerPromotion::TYPE_BUNDLE
                ? $promotion->components->pluck('product_variant_id')
                : collect([$promotion->buy_variant_id, $promotion->reward_variant_id]);
            $hasVisibleProduct = ProductVariant::query()
                ->whereIn('id', $variantIds->filter()->unique())
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('seller_profile_id', $seller->id)->visible())
                ->exists();
            if (! $hasVisibleProduct) {
                return;
            }

            $offer = $promotion->promotion_type === SellerPromotion::TYPE_BUNDLE
                ? 'Bundle deal'
                : 'Buy X, Get Y offer';
            $message = $offer.' “'.$promotion->name.'” is available at '.$seller->business_name.'.';
            if ($promotion->starts_at?->isFuture()) {
                $message .= ' It starts '.$promotion->starts_at->format('M j, Y g:i A').'.';
            }
            $event = implode('|', [
                'seller-promotion',
                $promotion->id,
                $promotion->updated_at?->format('YmdHis.u'),
                $promotion->name,
                $promotion->promotion_type,
            ]);

            $this->notifyStoreFollowers(
                $seller,
                'A store offer is available',
                $message,
                $event,
                (int) $promotion->id,
            );
        });
    }

    public function sellerVoucherChanged(int $voucherId): void
    {
        DB::afterCommit(function () use ($voucherId): void {
            $voucher = Voucher::query()->with('sellerProfile.user')->find($voucherId);
            if (! $voucher || ! $voucher->is_active || ! $this->saleWindowIsRelevant($voucher->starts_at, $voucher->ends_at)) {
                return;
            }

            $seller = $this->visibleSeller((int) $voucher->seller_profile_id);
            if (! $seller || ! $this->voucherHasVisibleProducts($voucher, $seller)) {
                return;
            }

            $discount = strtoupper((string) $voucher->discount_type) === 'PERCENT'
                ? number_format((float) $voucher->discount_value, 2).'% off'
                : '₱'.number_format((float) $voucher->discount_value, 2).' off';
            $message = $voucher->name.' at '.$seller->business_name.' offers '.$discount.'. Use code '.$voucher->code.' at checkout.';
            if ($voucher->starts_at?->isFuture()) {
                $message .= ' It starts '.$voucher->starts_at->format('M j, Y g:i A').'.';
            }
            $event = implode('|', [
                'seller-voucher',
                $voucher->id,
                $voucher->updated_at?->format('YmdHis.u'),
                $voucher->code,
                $voucher->discount_type,
                $voucher->discount_value,
                $this->voucherTargetFingerprint($voucher),
            ]);

            $this->notifyStoreFollowers(
                $seller,
                'A store voucher is available',
                $message,
                $event,
                (int) $voucher->id,
            );
        });
    }

    private function notifyStoreFollowersOfRestock(Product $product, ProductVariant $variant, int $stockBefore, int $stockAfter): void
    {
        $followers = SellerFollower::query()
            ->where('seller_profile_id', $product->seller_profile_id)
            ->pluck('buyer_user_id');
        if ($followers->isEmpty()) {
            return;
        }

        $event = implode('|', [
            'variant-restocked',
            $variant->id,
            $variant->updated_at?->format('YmdHis.u'),
            $stockBefore,
            $stockAfter,
        ]);
        $referenceType = $this->eventReference('STORE_RESTOCK', $event);
        $variantDescription = trim((string) $variant->description);
        $productLabel = $product->name.($variantDescription !== '' ? ' ('.$variantDescription.')' : '');

        foreach ($followers as $buyerId) {
            $this->recordForCurrentFollower(
                (int) $buyerId,
                (int) $product->seller_profile_id,
                'STORE_RESTOCK',
                'Back in stock',
                $productLabel.' is back in stock at '.$product->sellerProfile->business_name.'.',
                $referenceType,
                (int) $variant->id,
                '/buyer/products/'.$product->slug,
            );
        }
    }

    private function notifySellerDiscount(Product $product, ?ProductVariant $variant, string $event, ?float $salePrice = null): void
    {
        $message = $product->name.' now has a seller discount at '.$product->sellerProfile->business_name.'.';
        if ($variant) {
            $message .= ' The current price is ₱'.number_format($salePrice ?? (float) $variant->final_price, 2).'.';
        }

        $this->notifyProductFollowers($product, 'A store discount is available', $message, $event, (int) $product->id);
    }

    private function notifyProductFollowers(Product $product, string $title, string $message, string $event, int $referenceId): void
    {
        $seller = $product->sellerProfile;
        if (! $seller) {
            return;
        }

        $this->notifyStoreFollowers($seller, $title, $message, $event, $referenceId, '/buyer/products/'.$product->slug);
    }

    private function notifyStoreFollowers(
        SellerProfile $seller,
        string $title,
        string $message,
        string $event,
        int $referenceId,
        ?string $actionUrl = null,
    ): void {
        $referenceType = $this->eventReference('STORE_DISCOUNT', $event);
        $actionUrl ??= '/shops/'.$seller->id;
        $buyerIds = SellerFollower::query()
            ->where('seller_profile_id', $seller->id)
            ->pluck('buyer_user_id');

        foreach ($buyerIds as $buyerId) {
            $this->recordForCurrentFollower(
                (int) $buyerId,
                (int) $seller->id,
                'STORE_DISCOUNT',
                $title,
                $message,
                $referenceType,
                $referenceId,
                $actionUrl,
            );
        }
    }

    private function recordForCurrentFollower(
        int $buyerId,
        int $sellerProfileId,
        string $type,
        string $title,
        string $message,
        string $referenceType,
        int $referenceId,
        string $actionUrl,
    ): void {
        DB::transaction(function () use ($buyerId, $sellerProfileId, $type, $title, $message, $referenceType, $referenceId, $actionUrl): void {
            $buyer = User::query()->whereKey($buyerId)->lockForUpdate()->first();
            if (! $buyer || ! $buyer->isActive() || ! $buyer->isAccountType(User::TYPE_BUYER)) {
                return;
            }

            $stillFollowing = SellerFollower::query()
                ->where('buyer_user_id', $buyerId)
                ->where('seller_profile_id', $sellerProfileId)
                ->exists();
            if (! $stillFollowing) {
                return;
            }

            $identity = [
                'user_id' => $buyerId,
                'type' => $type,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ];
            if (Notification::query()->where($identity)->exists()) {
                return;
            }

            Notification::query()->create($identity + [
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
            ]);
        });
    }

    private function queueFlashSaleUpdate(int $productId): void
    {
        DB::afterCommit(function () use ($productId): void {
            $product = $this->visibleProduct($productId);
            if (! $product) {
                return;
            }

            $now = now();
            $startsAt = $product->flash_sale_starts_at;
            $endsAt = $product->flash_sale_ends_at;
            if (($endsAt && $endsAt->lessThanOrEqualTo($now)) || ($startsAt && $endsAt && $endsAt->lessThanOrEqualTo($startsAt))) {
                return;
            }

            $variants = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->get()
                ->filter(function (ProductVariant $variant): bool {
                    $price = (float) ($variant->flash_sale_price ?? 0);
                    $remaining = (int) $variant->flash_sale_remaining;
                    $regularPrice = $this->finalPrice(
                        (float) $variant->price,
                        $variant->discount_type,
                        (float) $variant->discount_value,
                    );

                    return $price > 0 && $price < $regularPrice && $remaining > 0 && (int) $variant->stock > 0;
                })
                ->values();
            if ($variants->isEmpty()) {
                return;
            }

            $event = implode('|', [
                'flash-sale',
                $product->id,
                $startsAt?->toIso8601String(),
                $endsAt?->toIso8601String(),
                $variants->map(fn (ProductVariant $variant): string => implode(':', [
                    $variant->id,
                    $variant->flash_sale_price,
                    $variant->flash_sale_allocation,
                    $variant->flash_sale_sold,
                ]))->implode(','),
            ]);
            $startsLater = $startsAt && $startsAt->isFuture();
            $lowestPrice = (float) $variants->min(fn (ProductVariant $variant): float => (float) $variant->flash_sale_price);
            $message = $startsLater
                ? 'A flash sale for '.$product->name.' at '.$product->sellerProfile->business_name.' starts '.$startsAt->format('M j, Y g:i A').'. Prices start at ₱'.number_format($lowestPrice, 2).'.'
                : 'A flash sale is now available for '.$product->name.' at '.$product->sellerProfile->business_name.'. Prices start at ₱'.number_format($lowestPrice, 2).'.';

            $this->notifyProductFollowers(
                $product,
                $startsLater ? 'Upcoming store flash sale' : 'A store flash sale is available',
                $message,
                $event,
                (int) $product->id,
            );
        });
    }

    private function voucherHasVisibleProducts(Voucher $voucher, SellerProfile $seller): bool
    {
        $appliesTo = strtoupper((string) ($voucher->getAttribute('applies_to') ?? 'STORE'));
        if ($appliesTo === 'STORE') {
            return true;
        }

        if ($appliesTo === 'PRODUCTS' && Schema::hasTable('voucher_products')) {
            $productIds = $voucher->eligibleProducts()->pluck('products.id');
        } elseif ($appliesTo === 'VARIANTS' && Schema::hasTable('voucher_variants')) {
            $productIds = $voucher->eligibleVariants()->pluck('product_id');
        } else {
            return false;
        }

        return $productIds->unique()->contains(fn ($productId): bool => $this->visibleProduct((int) $productId) !== null
            && (int) Product::query()->whereKey((int) $productId)->value('seller_profile_id') === (int) $seller->id);
    }

    private function voucherTargetFingerprint(Voucher $voucher): string
    {
        $appliesTo = strtoupper((string) ($voucher->getAttribute('applies_to') ?? 'STORE'));
        if ($appliesTo === 'PRODUCTS' && Schema::hasTable('voucher_products')) {
            return $voucher->eligibleProducts()->orderBy('products.id')->pluck('products.id')->implode(',');
        }
        if ($appliesTo === 'VARIANTS' && Schema::hasTable('voucher_variants')) {
            return $voucher->eligibleVariants()->orderBy('product_variants.id')->pluck('product_variants.id')->implode(',');
        }

        return $appliesTo;
    }

    private function visibleSeller(int $sellerId): ?SellerProfile
    {
        return SellerProfile::query()
            ->active()
            ->where('vacation_mode', false)
            ->whereHas('user', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            ->whereKey($sellerId)
            ->first();
    }

    private function saleWindowIsRelevant(mixed $startsAt, mixed $endsAt): bool
    {
        return ! ($endsAt && $endsAt->isPast());
    }

    private function visibleProduct(int $productId): ?Product
    {
        return Product::query()
            ->visible()
            ->with('sellerProfile')
            ->whereHas('sellerProfile', fn ($seller) => $seller->where('vacation_mode', false))
            ->whereKey($productId)
            ->first();
    }

    private function finalPrice(float $price, ?string $discountType, float $discountValue): float
    {
        $type = strtolower(trim((string) $discountType));
        if (! in_array($type, ['none', 'fixed', 'percentage'], true)) {
            $type = $discountValue > 0 ? 'percentage' : 'none';
        }

        $discount = match ($type) {
            'fixed' => min($price, max(0, $discountValue)),
            'percentage' => min($price, $price * min(100, max(0, $discountValue)) / 100),
            default => 0,
        };

        return round(max(0, $price - $discount), 2);
    }

    private function eventReference(string $prefix, string $event): string
    {
        return $prefix.'_'.substr(hash('sha256', $event), 0, 40);
    }
}
