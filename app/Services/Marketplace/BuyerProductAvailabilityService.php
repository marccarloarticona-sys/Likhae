<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;

class BuyerProductAvailabilityService
{
    /**
     * @return array{available: bool, restricted: bool, code: string, message: string}
     */
    public function inspect(
        ?ProductVariant $variant,
        ?Product $product,
        ?SellerProfile $seller,
        ?User $sellerUser,
        int $requestedQuantity = 1,
    ): array {
        if (! $product || $product->trashed()
            || in_array($product->status, ['DRAFT', 'ARCHIVED'], true)
            || $product->archived_at !== null) {
            return $this->unavailable('LISTING_UNAVAILABLE', 'This product is no longer available.', restricted: true);
        }

        if (! $seller) {
            return $this->unavailable('LISTING_UNAVAILABLE', 'This product is no longer available.', restricted: true);
        }

        if ($product->status !== 'ACTIVE') {
            // SUSPENDED listings include moderation holds. Keep the reason
            // private while giving the Buyer a truthful neutral message.
            return $this->unavailable('LISTING_TEMPORARILY_UNAVAILABLE', 'This product is temporarily unavailable.', restricted: true);
        }

        if ($product->published_at?->isFuture()) {
            return $this->unavailable('LISTING_NOT_YET_AVAILABLE', 'This product is not available yet.', restricted: true);
        }

        if ($seller->status !== 'ACTIVE'
            || ! $sellerUser
            || ! $sellerUser->isActive()
            || ! $sellerUser->isAccountType(User::TYPE_SELLER)) {
            return $this->unavailable('SHOP_UNAVAILABLE', 'This shop is currently unavailable.', restricted: true);
        }

        if ($seller->vacation_mode) {
            return $this->unavailable(
                'SELLER_ON_VACATION',
                'This seller is currently on vacation and is not accepting purchases.',
                restricted: false,
            );
        }

        if (! $variant) {
            return $this->unavailable('VARIANT_UNAVAILABLE', 'This product is no longer available.', restricted: true);
        }

        if (! $variant->is_active) {
            return $this->unavailable('VARIANT_UNAVAILABLE', 'This variation is currently unavailable.', restricted: false);
        }

        if ((int) $variant->stock < 1) {
            return $this->unavailable('OUT_OF_STOCK', 'This variation is out of stock.', restricted: false);
        }

        if ((int) $variant->stock < max(1, $requestedQuantity)) {
            return $this->unavailable('INSUFFICIENT_STOCK', 'Requested quantity exceeds available stock.', restricted: false);
        }

        return [
            'available' => true,
            'restricted' => false,
            'code' => 'AVAILABLE',
            'message' => 'Available',
        ];
    }

    /**
     * @return array{available: bool, restricted: bool, code: string, message: string}
     */
    private function unavailable(string $code, string $message, bool $restricted): array
    {
        return [
            'available' => false,
            'restricted' => $restricted,
            'code' => $code,
            'message' => $message,
        ];
    }
}
