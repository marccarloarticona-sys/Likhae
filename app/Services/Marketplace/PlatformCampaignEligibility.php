<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\CartItem;
use App\Models\Buyer\Order;
use App\Models\Seller\Voucher;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Campaign rules shared by Rewards, checkout preview, and order placement. */
class PlatformCampaignEligibility
{
    public function controlsReady(): bool
    {
        return Schema::hasColumn('vouchers', 'distribution_mode')
            && Schema::hasColumn('vouchers', 'target_mode')
            && Schema::hasColumn('vouchers', 'buyer_audience')
            && Schema::hasColumn('vouchers', 'campaign_status');
    }

    public function distribution(Voucher $voucher): string
    {
        return $this->controlsReady() ? strtoupper((string) $voucher->distribution_mode) : 'CODE';
    }

    public function status(Voucher $voucher): string
    {
        if ($this->controlsReady() && strtoupper((string) $voucher->campaign_status) === 'DRAFT') {
            return 'DRAFT';
        }
        if ($this->controlsReady() && strtoupper((string) $voucher->campaign_status) === 'ENDED') {
            return 'ENDED';
        }
        if (! $voucher->is_active || ($this->controlsReady() && strtoupper((string) $voucher->campaign_status) === 'PAUSED')) {
            return 'PAUSED';
        }
        if ($voucher->ends_at && $voucher->ends_at->lt(now())) {
            return 'ENDED';
        }
        if ($voucher->starts_at && $voucher->starts_at->gt(now())) {
            return 'SCHEDULED';
        }

        return 'ACTIVE';
    }

    public function audienceEligible(Voucher $voucher, ?User $buyer): bool
    {
        $audience = $this->controlsReady() ? strtoupper((string) $voucher->buyer_audience) : 'ALL';
        if ($audience === 'ALL') {
            return true;
        }
        if (! $buyer) {
            return false;
        }

        // Reward points and the buyer order history use the same completed-order rule.
        $hasCompletedOrder = Order::query()->where('buyer_user_id', $buyer->id)->where('status', 'COMPLETED')->exists();

        return $audience === 'FIRST_TIME' ? ! $hasCompletedOrder : $hasCompletedOrder;
    }

    public function itemEligible(Voucher $voucher, CartItem $item): bool
    {
        if (! $this->controlsReady()) {
            return true;
        }
        $mode = strtoupper((string) $voucher->target_mode);
        if ($mode === 'ALL') {
            return true;
        }
        $item->loadMissing('productVariant.product.category');
        $product = $item->productVariant?->product;
        if (! $product) {
            return false;
        }

        return match ($mode) {
            'PRODUCTS' => in_array((int) $product->id, array_map('intval', (array) $voucher->target_product_ids), true),
            'SELLERS' => in_array((int) $product->seller_profile_id, array_map('intval', (array) $voucher->target_seller_ids), true),
            'CATEGORIES' => in_array((int) $product->category_id, array_map('intval', (array) $voucher->target_category_ids), true)
                || in_array((int) ($product->category?->parent_id ?? 0), array_map('intval', (array) $voucher->target_category_ids), true),
            default => false,
        };
    }

    /** @param Collection<int, CartItem> $items */
    public function hasEligibleItem(Voucher $voucher, Collection $items): bool
    {
        return $items->contains(fn (CartItem $item): bool => $this->itemEligible($voucher, $item));
    }

    public function stacksWithSeller(Voucher $voucher): bool
    {
        return ! $this->controlsReady() || (bool) $voucher->stack_with_seller;
    }

    public function stacksWithShipping(Voucher $voucher): bool
    {
        return ! $this->controlsReady() || (bool) $voucher->stack_with_shipping;
    }
}
