<?php

namespace App\Services\Marketplace;

use App\Models\Admin\Notification;
use App\Models\Buyer\FlashSaleReminder;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FlashSaleReminderService
{
    public const LEAD_MINUTES = 10;

    public function register(User $buyer, Product $product): FlashSaleReminder
    {
        if (! Schema::hasTable('flash_sale_reminders')) {
            throw ValidationException::withMessages(['product' => 'Flash sale reminders are temporarily unavailable.']);
        }

        return DB::transaction(function () use ($buyer, $product): FlashSaleReminder {
            $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $this->assertReminderEligible($lockedProduct);
            $startsAt = $lockedProduct->flash_sale_starts_at;
            $existing = FlashSaleReminder::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('product_id', $lockedProduct->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'PENDING') {
                return $existing;
            }

            if ($existing) {
                $existing->fill([
                    'product_name' => $lockedProduct->name,
                    'product_slug' => $lockedProduct->slug,
                    'sale_starts_at' => $startsAt,
                    'scheduled_at' => $startsAt->copy()->subMinutes(self::LEAD_MINUTES),
                    'status' => 'PENDING',
                    'sent_at' => null,
                    'cancelled_at' => null,
                ])->save();

                return $existing;
            }

            return FlashSaleReminder::query()->create([
                'buyer_user_id' => $buyer->id,
                'product_id' => $lockedProduct->id,
                'product_name' => $lockedProduct->name,
                'product_slug' => $lockedProduct->slug,
                'sale_starts_at' => $startsAt,
                'scheduled_at' => $startsAt->copy()->subMinutes(self::LEAD_MINUTES),
                'status' => 'PENDING',
            ]);
        }, attempts: 3);
    }

    public function cancel(User $buyer, Product $product): bool
    {
        return (bool) FlashSaleReminder::query()
            ->where('buyer_user_id', $buyer->id)
            ->where('product_id', $product->id)
            ->where('status', 'PENDING')
            ->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
    }

    /** Update pending reminders whenever a Seller edits or unpublishes a sale. */
    public function syncProduct(Product $product): void
    {
        if (! Schema::hasTable('flash_sale_reminders')) {
            return;
        }

        $product->loadMissing(['variants', 'sellerProfile.user']);
        $eligible = $product->status === 'ACTIVE'
            && $product->sellerProfile?->status === 'ACTIVE'
            && (bool) $product->sellerProfile?->store_visibility
            && $product->sellerProfile?->user?->status === User::STATUS_ACTIVE
            && ! (bool) ($product->flash_sale_is_paused ?? false)
            && $product->flash_sale_starts_at?->isFuture()
            && (! $product->flash_sale_ends_at || $product->flash_sale_ends_at->isFuture())
            && $product->variants->contains(fn (ProductVariant $variant): bool => $this->variantHasDiscount($variant));

        $query = FlashSaleReminder::query()->where('product_id', $product->id)->where('status', 'PENDING');
        if (! $eligible) {
            $query->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);

            return;
        }

        $startsAt = $product->flash_sale_starts_at;
        $query->update([
            'product_name' => $product->name,
            'product_slug' => $product->slug,
            'sale_starts_at' => $startsAt,
            'scheduled_at' => $startsAt->copy()->subMinutes(self::LEAD_MINUTES),
            'updated_at' => now(),
        ]);
    }

    public function sendDue(int $limit = 250): int
    {
        if (! Schema::hasTable('flash_sale_reminders') || ! Schema::hasTable('notifications')) {
            return 0;
        }

        $sent = 0;
        $ids = FlashSaleReminder::query()
            ->where('status', 'PENDING')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            $wasSent = DB::transaction(function () use ($id): bool {
                $reminder = FlashSaleReminder::query()->whereKey($id)->lockForUpdate()->first();
                if (! $reminder || $reminder->status !== 'PENDING') {
                    return false;
                }

                if ($reminder->sale_starts_at->lte(now())) {
                    $reminder->forceFill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save();

                    return false;
                }

                $buyer = User::query()->whereKey($reminder->buyer_user_id)->first();
                if (! $buyer || ! $buyer->isAccountType(User::TYPE_BUYER)
                    || ! (bool) data_get($buyer->notification_preferences, 'promotion_updates', true)) {
                    $reminder->forceFill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save();

                    return false;
                }

                $product = $reminder->product;
                if (! $product || ! $this->productSaleIsEligible($product)) {
                    $reminder->forceFill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save();

                    return false;
                }

                if (! $product->flash_sale_starts_at->equalTo($reminder->sale_starts_at)) {
                    $reminder->forceFill([
                        'product_name' => $product->name,
                        'product_slug' => $product->slug,
                        'sale_starts_at' => $product->flash_sale_starts_at,
                        'scheduled_at' => $product->flash_sale_starts_at->copy()->subMinutes(self::LEAD_MINUTES),
                    ])->save();

                    return false;
                }

                Notification::query()->create([
                    'user_id' => $buyer->id,
                    'type' => 'FLASH_SALE_REMINDER',
                    'title' => 'Flash sale starts soon',
                    'message' => 'The flash sale for '.$reminder->product_name.' starts in about 10 minutes.',
                    'reference_type' => 'FLASH_SALE_REMINDER',
                    'reference_id' => $reminder->id,
                    'action_url' => route('buyer.product-details', ['slug' => $reminder->product_slug]),
                ]);
                $reminder->forceFill(['status' => 'SENT', 'sent_at' => now()])->save();

                return true;
            }, attempts: 3);

            $sent += $wasSent ? 1 : 0;
        }

        return $sent;
    }

    public function reminderStatesFor(User $buyer, iterable $products): array
    {
        if (! Schema::hasTable('flash_sale_reminders')) {
            return [];
        }

        $productIds = collect($products)->pluck('id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($productIds->isEmpty()) {
            return [];
        }

        return FlashSaleReminder::query()
            ->where('buyer_user_id', $buyer->id)
            ->whereIn('product_id', $productIds)
            ->where('status', 'PENDING')
            ->pluck('status', 'product_id')
            ->all();
    }

    private function assertReminderEligible(Product $product): void
    {
        $product->loadMissing(['sellerProfile.user', 'variants']);
        $startsAt = $product->flash_sale_starts_at;
        $valid = $product->status === 'ACTIVE'
            && $product->sellerProfile?->status === 'ACTIVE'
            && (bool) $product->sellerProfile?->store_visibility
            && $product->sellerProfile?->user?->status === User::STATUS_ACTIVE
            && ! (bool) ($product->flash_sale_is_paused ?? false)
            && $startsAt?->isFuture()
            && $startsAt->greaterThan(now()->addMinutes(self::LEAD_MINUTES))
            && (! $product->flash_sale_ends_at || $product->flash_sale_ends_at->isFuture())
            && $product->variants->contains(fn (ProductVariant $variant): bool => $this->variantHasDiscount($variant));

        if (! $valid) {
            throw ValidationException::withMessages([
                'product' => 'Reminders are available for upcoming flash sales at least 10 minutes before they start.',
            ]);
        }
    }

    private function variantHasDiscount(ProductVariant $variant): bool
    {
        if (! $variant->is_active || (int) $variant->stock < 1 || (float) $variant->price <= 0) {
            return false;
        }

        $hasDedicatedFlashAllocation = $variant->flash_sale_price !== null
            && (float) $variant->flash_sale_price > 0
            && $variant->flash_sale_remaining > 0;

        return $hasDedicatedFlashAllocation
            || ($variant->resolved_discount_type !== 'none' && (float) $variant->discount_value > 0);
    }

    private function productSaleIsEligible(Product $product): bool
    {
        $product->loadMissing(['sellerProfile.user', 'variants']);

        return $product->status === 'ACTIVE'
            && $product->sellerProfile?->status === 'ACTIVE'
            && (bool) $product->sellerProfile?->store_visibility
            && $product->sellerProfile?->user?->status === User::STATUS_ACTIVE
            && ! (bool) ($product->flash_sale_is_paused ?? false)
            && $product->flash_sale_starts_at?->isFuture()
            && (! $product->flash_sale_ends_at || $product->flash_sale_ends_at->isFuture())
            && $product->variants->contains(fn (ProductVariant $variant): bool => $this->variantHasDiscount($variant));
    }
}
