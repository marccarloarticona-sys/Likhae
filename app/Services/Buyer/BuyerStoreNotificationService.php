<?php

namespace App\Services\Buyer;

use App\Models\Admin\Notification;
use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\SellerFollower;
use App\Models\Buyer\WishlistItem;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BuyerStoreNotificationService
{
    public function variantCreated(ProductVariant $variant): void
    {
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
                $this->notifyRelevantRestockFollowers($product, $variant, $stockBefore, $stockAfter);
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
            if ($variant->is_active && $newFinalPrice < (float) $variant->price && $newFinalPrice < $oldFinalPrice) {
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
                    $this->notifySellerDiscount($product, $variant, $event, $newFinalPrice);
                }
            }
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
    }

    private function notifyRelevantRestockFollowers(Product $product, ProductVariant $variant, int $stockBefore, int $stockAfter): void
    {
        $wishlistBuyerIds = WishlistItem::query()
            ->where('product_id', $product->id)
            ->pluck('user_id');
        $cartBuyerIds = CartItem::query()
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->where('carts.status', Cart::STATUS_ACTIVE)
            ->where('cart_items.product_variant_id', $variant->id)
            ->pluck('carts.buyer_user_id');

        $buyerIds = $wishlistBuyerIds->merge($cartBuyerIds)->unique()->values();
        if ($buyerIds->isEmpty()) {
            return;
        }

        $followers = SellerFollower::query()
            ->where('seller_profile_id', $product->seller_profile_id)
            ->whereIn('buyer_user_id', $buyerIds)
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

        foreach ($followers as $buyerId) {
            $this->recordForCurrentFollower(
                (int) $buyerId,
                $product,
                'STORE_RESTOCK',
                'Back in stock',
                $product->name.' is back in stock at '.$product->sellerProfile->business_name.'.',
                $referenceType,
                (int) $variant->id,
            );
        }
    }

    private function notifySellerDiscount(Product $product, ?ProductVariant $variant, string $event, ?float $salePrice = null): void
    {
        $referenceType = $this->eventReference('STORE_DISCOUNT', $event);
        $message = $product->name.' now has a seller discount at '.$product->sellerProfile->business_name.'.';
        if ($variant) {
            $message .= ' The current price is ₱'.number_format($salePrice ?? (float) $variant->final_price, 2).'.';
        }

        $buyerIds = SellerFollower::query()
            ->where('seller_profile_id', $product->seller_profile_id)
            ->pluck('buyer_user_id');

        foreach ($buyerIds as $buyerId) {
            $this->recordForCurrentFollower(
                (int) $buyerId,
                $product,
                'STORE_DISCOUNT',
                'A store discount is available',
                $message,
                $referenceType,
                (int) $product->id,
            );
        }
    }

    private function recordForCurrentFollower(
        int $buyerId,
        Product $product,
        string $type,
        string $title,
        string $message,
        string $referenceType,
        int $referenceId,
    ): void {
        DB::transaction(function () use ($buyerId, $product, $type, $title, $message, $referenceType, $referenceId): void {
            $buyer = User::query()->whereKey($buyerId)->lockForUpdate()->first();
            if (! $buyer || ! $buyer->isActive() || ! $buyer->isAccountType(User::TYPE_BUYER)) {
                return;
            }

            $stillFollowing = SellerFollower::query()
                ->where('buyer_user_id', $buyerId)
                ->where('seller_profile_id', $product->seller_profile_id)
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
                'action_url' => '/buyer/products/'.$product->slug,
            ]);
        });
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
