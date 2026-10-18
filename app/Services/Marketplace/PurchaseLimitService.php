<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Cart;
use App\Models\Seller\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseLimitService
{
    public function purchasedQuantity(int $buyerId, int $productId, bool $lockForUpdate = false): int
    {
        $query = DB::table('order_items')
            ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_user_id', $buyerId)
            ->where('order_items.product_id', $productId)
            ->where('orders.status', '<>', 'CANCELLED')
            ->where('seller_orders.status', '<>', 'CANCELLED');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return (int) ($query->selectRaw('COALESCE(SUM(order_items.quantity), 0) AS purchased_quantity')
            ->value('purchased_quantity') ?? 0);
    }

    public function cartQuantity(
        User $buyer,
        int $productId,
        ?int $excludeCartItemId = null,
        bool $lockForUpdate = false,
    ): int
    {
        $query = DB::table('cart_items')
            ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
            ->join('product_variants', 'product_variants.id', '=', 'cart_items.product_variant_id')
            ->where('carts.buyer_user_id', $buyer->id)
            ->where('carts.status', Cart::STATUS_ACTIVE)
            ->where('product_variants.product_id', $productId)
            ->when($excludeCartItemId !== null, fn ($query) => $query->where('cart_items.id', '<>', $excludeCartItemId));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return (int) $query->sum('cart_items.quantity');
    }

    public function remainingAllowance(User $buyer, Product $product): ?int
    {
        $limit = $product->purchase_limit_per_buyer;
        if ($limit === null) {
            return null;
        }

        return max(0, (int) $limit
            - $this->purchasedQuantity((int) $buyer->id, (int) $product->id)
            - $this->cartQuantity($buyer, (int) $product->id));
    }

    public function cartWithinLimit(User $buyer, Product $product, int $cartQuantity): bool
    {
        $limit = $product->purchase_limit_per_buyer;
        if ($limit === null) {
            return true;
        }

        return $this->purchasedQuantity((int) $buyer->id, (int) $product->id)
            + max(0, $cartQuantity) <= (int) $limit;
    }

    public function assertWithinLimit(
        User $buyer,
        Product $product,
        int $additionalQuantity,
        int $existingCartQuantity = 0,
        string $attribute = 'quantity',
        bool $lockHistoricalRows = false,
    ): void {
        $limit = $product->purchase_limit_per_buyer;
        if ($limit === null) {
            return;
        }

        $alreadyPurchased = $this->purchasedQuantity(
            (int) $buyer->id,
            (int) $product->id,
            $lockHistoricalRows,
        );
        $projectedQuantity = $alreadyPurchased + $existingCartQuantity + $additionalQuantity;
        if ($projectedQuantity <= (int) $limit) {
            return;
        }

        $available = max(0, (int) $limit - $alreadyPurchased - $existingCartQuantity);
        throw ValidationException::withMessages([
            $attribute => "This product has a limit of {$limit} units per Buyer. You can add up to {$available} more.",
        ]);
    }
}
