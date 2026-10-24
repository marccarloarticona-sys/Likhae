<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Cart;
use App\Models\Buyer\CartItem;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(
        private readonly PurchaseLimitService $purchaseLimits,
        private readonly BuyerProductAvailabilityService $productAvailability,
    ) {}

    public function activeCart(User $buyer): Cart
    {
        return Cart::query()->firstOrCreate(
            [
                'buyer_user_id' => $buyer->id,
                'status' => Cart::STATUS_ACTIVE,
            ],
            [
                'converted_at' => null,
            ]
        );
    }

    public function items(User $buyer): Collection
    {
        return $this->activeCart($buyer)
            ->items()
            ->with(['productVariant.product.images', 'productVariant.productImage', 'productVariant.product.sellerProfile', 'productVariant.optionValues.option'])
            ->latest()
            ->get();
    }

    public function add(User $buyer, Product $product, int $variantId, int $quantity): CartItem
    {
        return DB::transaction(function () use ($buyer, $product, $variantId, $quantity): CartItem {
            $cart = $this->activeCart($buyer);
            $lockedCarts = Cart::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('status', Cart::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cart = $lockedCarts->firstWhere('id', $cart->id) ?? $cart;

            // Keep the same cart -> variant -> product lock order as checkout.
            $variant = ProductVariant::query()
                ->where('product_id', $product->id)
                ->where('is_active', true)
                ->whereKey($variantId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedProduct = Product::query()
                ->with('sellerProfile')
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $availability = $this->productAvailability->inspect(
                $variant,
                $lockedProduct,
                $lockedProduct->sellerProfile,
                $lockedProduct->sellerProfile?->user,
                $quantity,
            );
            if (! $availability['available']) {
                $attribute = $availability['code'] === 'INSUFFICIENT_STOCK'
                    ? 'quantity'
                    : 'product_variant_id';
                throw ValidationException::withMessages([
                    $attribute => $availability['message'],
                ]);
            }

            $item = $cart->items()->where('product_variant_id', $variant->id)->lockForUpdate()->first();
            if (! $item) {
                $item = $cart->items()->make(['product_variant_id' => $variant->id]);
            }
            $existingProductQuantity = $this->purchaseLimits->cartQuantity(
                $buyer,
                (int) $lockedProduct->id,
                lockForUpdate: true,
            );
            $this->purchaseLimits->assertWithinLimit(
                $buyer,
                $lockedProduct,
                $quantity,
                $existingProductQuantity,
                'quantity',
                lockHistoricalRows: true,
            );

            $newQuantity = (int) $item->quantity + $quantity;
            if ($newQuantity > $variant->stock) {
                throw ValidationException::withMessages([
                    'quantity' => 'Your cart quantity exceeds available stock.',
                ]);
            }

            $item->quantity = $newQuantity;
            $item->save();

            return $item;
        }, attempts: 3);
    }

    public function update(User $buyer, CartItem $item, int $quantity): CartItem
    {
        $this->ensureOwner($buyer, $item);

        return DB::transaction(function () use ($buyer, $item, $quantity): CartItem {
            $lockedCarts = Cart::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('status', Cart::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedItem = CartItem::query()
                ->whereKey($item->id)
                ->whereIn('cart_id', $lockedCarts->pluck('id'))
                ->lockForUpdate()
                ->first();
            abort_unless($lockedItem, 403);

            $variant = ProductVariant::query()
                ->whereKey($lockedItem->product_variant_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $variant) {
                throw ValidationException::withMessages([
                    'quantity' => 'This cart item is no longer available. Remove it from your cart.',
                ]);
            }

            $product = Product::query()
                ->with('sellerProfile')
                ->whereKey($variant->product_id)
                ->lockForUpdate()
                ->firstOrFail();
            $availability = $this->productAvailability->inspect(
                $variant,
                $product,
                $product->sellerProfile,
                $product->sellerProfile?->user,
                $quantity,
            );
            if (! $availability['available']) {
                throw ValidationException::withMessages([
                    'quantity' => $availability['message'],
                ]);
            }

            $existingProductQuantity = $this->purchaseLimits->cartQuantity(
                $buyer,
                (int) $product->id,
                (int) $lockedItem->id,
                lockForUpdate: true,
            );
            $this->purchaseLimits->assertWithinLimit(
                $buyer,
                $product,
                $quantity,
                $existingProductQuantity,
                'quantity',
                lockHistoricalRows: true,
            );

            $lockedItem->update(['quantity' => $quantity]);

            return $lockedItem;
        }, attempts: 3);
    }

    public function remove(User $buyer, CartItem $item): void
    {
        $this->ensureOwner($buyer, $item);
        $item->delete();
    }

    public function ensureOwner(User $buyer, CartItem $item): void
    {
        $item->loadMissing('cart');
        abort_unless((int) $item->cart->buyer_user_id === (int) $buyer->id, 403);
    }

    public function selectedItems(User $buyer, array $ids = []): Collection
    {
        $query = $this->activeCart($buyer)
            ->items()
            ->with(['productVariant.product.images', 'productVariant.productImage', 'productVariant.product.sellerProfile', 'productVariant.optionValues.option']);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }
}
