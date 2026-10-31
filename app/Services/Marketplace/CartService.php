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
        return $this->upsertQuantity($buyer, $product, $variantId, $quantity, replaceExisting: false);
    }

    /** Persist exactly the requested quantity for a Buy Now checkout. */
    public function setForCheckout(User $buyer, Product $product, int $variantId, int $quantity): CartItem
    {
        return $this->upsertQuantity($buyer, $product, $variantId, $quantity, replaceExisting: true);
    }

    private function upsertQuantity(
        User $buyer,
        Product $product,
        int $variantId,
        int $quantity,
        bool $replaceExisting,
    ): CartItem {
        return DB::transaction(function () use ($buyer, $product, $variantId, $quantity, $replaceExisting): CartItem {
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
                $replaceExisting && $item->exists ? (int) $item->id : null,
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

            $newQuantity = $replaceExisting ? $quantity : (int) $item->quantity + $quantity;
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

    /** @param array<int|string, int> $variantQuantities */
    public function addMany(User $buyer, array $variantQuantities): void
    {
        $quantities = collect($variantQuantities)
            ->mapWithKeys(fn ($quantity, $variantId): array => [(int) $variantId => (int) $quantity])
            ->filter(fn (int $quantity, int $variantId): bool => $variantId > 0 && $quantity > 0)
            ->sortKeys();
        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages(['cart' => 'Choose at least one available item to add to your cart.']);
        }

        DB::transaction(function () use ($buyer, $quantities): void {
            $cart = $this->activeCart($buyer);
            $lockedCarts = Cart::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('status', Cart::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cart = $lockedCarts->firstWhere('id', $cart->id) ?? $cart;
            $existingItems = CartItem::query()
                ->where('cart_id', $cart->id)
                ->with('productVariant.product')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $variants = ProductVariant::query()
                ->whereIn('id', $quantities->keys())
                ->where('is_active', true)
                ->with('product.sellerProfile.user')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($variants->count() !== $quantities->count()) {
                throw ValidationException::withMessages(['cart' => 'One or more offer variations are no longer available. Refresh the offer and try again.']);
            }
            $products = Product::query()
                ->whereIn('id', $variants->pluck('product_id')->unique())
                ->with('sellerProfile.user')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $existingByVariant = $existingItems->groupBy('product_variant_id')->map(fn ($items) => $items->first());
            $addingByProduct = $quantities->groupBy(fn ($quantity, $variantId): int => (int) $variants->get($variantId)->product_id)
                ->map(fn ($rows): int => (int) $rows->sum());

            foreach ($addingByProduct as $productId => $addingQuantity) {
                $product = $products->get((int) $productId);
                if (! $product) {
                    throw ValidationException::withMessages(['cart' => 'An offer item is no longer available.']);
                }
                $existingProductQuantity = $this->purchaseLimits->cartQuantity($buyer, (int) $product->id, lockForUpdate: true);
                $this->purchaseLimits->assertWithinLimit(
                    $buyer,
                    $product,
                    (int) $addingQuantity,
                    $existingProductQuantity,
                    'quantity',
                    lockHistoricalRows: true,
                );
            }

            foreach ($quantities as $variantId => $quantity) {
                $variant = $variants->get((int) $variantId);
                $product = $products->get((int) $variant->product_id);
                $availability = $this->productAvailability->inspect(
                    $variant,
                    $product,
                    $product?->sellerProfile,
                    $product?->sellerProfile?->user,
                    (int) $quantity,
                );
                if (! $availability['available']) {
                    throw ValidationException::withMessages(['cart' => $availability['message']]);
                }

                $existing = $existingByVariant->get((int) $variantId);
                $newQuantity = (int) ($existing?->quantity ?? 0) + (int) $quantity;
                if ($newQuantity > (int) $variant->stock) {
                    throw ValidationException::withMessages([
                        'cart' => 'The requested offer quantities exceed available stock for '.($product?->name ?? 'one product').'.',
                    ]);
                }
                if ($existing) {
                    $existing->update(['quantity' => $newQuantity]);
                } else {
                    $cart->items()->create([
                        'product_variant_id' => $variant->id,
                        'quantity' => (int) $quantity,
                    ]);
                }
            }
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
