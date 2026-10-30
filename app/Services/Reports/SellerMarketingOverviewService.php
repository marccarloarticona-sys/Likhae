<?php

namespace App\Services\Reports;

use App\Models\Buyer\OrderItem;
use App\Models\Seller\Product;
use App\Models\Seller\SellerPromotion;
use App\Models\Seller\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Builds seller marketing metrics from campaign definitions and recognized order snapshots. */
final class SellerMarketingOverviewService
{
    public function __construct(private readonly SellerSalesSummaryService $salesSummary) {}

    /** @return array<string, mixed> */
    public function forSeller(int $sellerProfileId): array
    {
        $now = now();
        $campaigns = collect();
        $vouchers = collect();
        if (Schema::hasTable('vouchers')) {
            $vouchers = Voucher::query()->where('seller_profile_id', $sellerProfileId)->get();
            $redemptionsByVoucher = DB::table('seller_orders')
                ->whereIn('voucher_id', $vouchers->modelKeys())
                ->where('status', '<>', 'CANCELLED')
                ->selectRaw('voucher_id, COUNT(*) as total')
                ->groupBy('voucher_id')
                ->pluck('total', 'voucher_id');
            $voucherClaims = Schema::hasTable('buyer_voucher_claims')
                ? (int) DB::table('buyer_voucher_claims')->whereIn('voucher_id', $vouchers->modelKeys())->count()
                : 0;
            $voucherRedemptions = (int) $redemptionsByVoucher->sum();

            foreach ($vouchers as $voucher) {
                $redemptions = (int) $redemptionsByVoucher->get($voucher->id, 0);
                $campaigns->push([
                    'type' => 'Voucher',
                    'name' => $voucher->name,
                    'code' => $voucher->code,
                    'starts_at' => $voucher->starts_at,
                    'ends_at' => $voucher->ends_at,
                    'status' => $this->voucherStatus($voucher, $now, $redemptions),
                    'redemptions' => $redemptions,
                ]);
            }
        } else {
            $voucherRedemptions = 0;
            $voucherClaims = 0;
        }

        $from = Carbon::create(2000, 1, 1, 0, 0, 0, (string) config('app.timezone', 'UTC'));
        $recognizedIds = $this->salesSummary
            ->recognizedOrderIdsQuery($sellerProfileId, $from, $now->copy()->endOfDay())
            ->distinct()
            ->pluck('seller_order_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $recognizedItems = $recognizedIds === []
            ? collect()
            : OrderItem::query()->whereIn('seller_order_id', $recognizedIds)->get();

        $sellerFundedDiscountCost = 0.0;
        $promotionAttributedRevenue = 0.0;
        $flashSaleUnits = 0;
        $promotionRedemptions = [];
        foreach ($recognizedItems as $item) {
            $priceDiscount = (float) ($item->discount_amount ?? 0);
            $offerDiscount = (float) ($item->promotion_discount_amount ?? 0);
            $sellerVoucherDiscount = (float) ($item->seller_voucher_discount ?? 0);
            $sellerFundedDiscountCost += $priceDiscount + $offerDiscount + $sellerVoucherDiscount;
            $flashQuantity = max(0, (int) ($item->flash_sale_quantity ?? 0));
            $flashSaleUnits += $flashQuantity;

            $snapshots = collect((array) ($item->promotion_snapshot ?? []));
            foreach ($snapshots as $snapshot) {
                if (! is_array($snapshot)) {
                    continue;
                }
                $promotionId = (int) ($snapshot['promotion_id'] ?? 0);
                if ($promotionId > 0) {
                    $promotionRedemptions[$promotionId] = ($promotionRedemptions[$promotionId] ?? 0)
                        + max(0, (int) ($snapshot['redemption_sets'] ?? 0));
                }
            }

            if ($priceDiscount > 0 || $offerDiscount > 0 || $sellerVoucherDiscount > 0 || $flashQuantity > 0 || $snapshots->isNotEmpty()) {
                // Attribute each recognized item once. Platform-funded voucher amounts are deliberately excluded.
                $historicGross = (float) ($item->original_unit_price ?? $item->unit_price) * max(0, (int) $item->quantity);
                $promotionAttributedRevenue += max(0, $historicGross - $priceDiscount - $offerDiscount - $sellerVoucherDiscount);
            }
        }

        $bundleRedemptions = 0;
        $buyXGetYRedemptions = 0;
        $activeSpecialOffers = 0;
        $scheduledSpecialOffers = 0;
        if (Schema::hasTable('seller_promotions')) {
            $promotions = SellerPromotion::query()->where('seller_profile_id', $sellerProfileId)->latest('id')->get();
            foreach ($promotions as $promotion) {
                $redemptions = (int) ($promotionRedemptions[$promotion->id] ?? 0);
                if ($promotion->promotion_type === SellerPromotion::TYPE_BUNDLE) {
                    $bundleRedemptions += $redemptions;
                } else {
                    $buyXGetYRedemptions += $redemptions;
                }
                $status = $promotion->state;
                $activeSpecialOffers += $status === 'ACTIVE' ? 1 : 0;
                $scheduledSpecialOffers += $status === 'SCHEDULED' ? 1 : 0;
                $campaigns->push([
                    'type' => $promotion->promotion_type === SellerPromotion::TYPE_BUNDLE ? 'Bundle Deal' : 'Buy X Get Y',
                    'name' => $promotion->name,
                    'code' => null,
                    'starts_at' => $promotion->starts_at,
                    'ends_at' => $promotion->ends_at,
                    'status' => str_replace('_', ' ', strtolower($status)),
                    'redemptions' => $redemptions,
                ]);
            }
        }

        $productDiscounts = collect();
        $flashCampaigns = collect();
        $products = Product::query()
            ->where('seller_profile_id', $sellerProfileId)
            ->with(['variants' => fn ($query) => $query->where('is_active', true)])
            ->get();
        foreach ($products as $product) {
            $hasConfiguredDiscount = $product->variants->contains(fn ($variant): bool =>
                (float) ($variant->discount_value ?? 0) > 0
                && in_array(strtolower((string) ($variant->discount_type ?? 'percentage')), ['percentage', 'percent', 'fixed'], true));
            if ($hasConfiguredDiscount) {
                $status = ! (bool) ($product->discounts_enabled ?? true) ? 'paused'
                    : ($product->discount_starts_at?->isFuture() ? 'scheduled'
                        : ($product->discount_ends_at?->isPast() ? 'expired' : 'active'));
                $productDiscounts->push([
                    'type' => 'Product Discount',
                    'name' => $product->name,
                    'code' => null,
                    'starts_at' => $product->discount_starts_at,
                    'ends_at' => $product->discount_ends_at,
                    'status' => $status,
                    'redemptions' => null,
                ]);
            }

            $hasFlashConfig = $product->variants->contains(fn ($variant): bool =>
                $variant->flash_sale_price !== null && (int) ($variant->flash_sale_allocation ?? 0) > 0);
            if ($hasFlashConfig) {
                $status = (bool) ($product->flash_sale_is_paused ?? false) ? 'paused'
                    : ($product->flash_sale_starts_at?->isFuture() ? 'scheduled'
                        : ($product->flash_sale_ends_at?->isPast() ? 'expired' : 'active'));
                $flashCampaigns->push([
                    'type' => 'Flash Sale',
                    'name' => $product->name,
                    'code' => null,
                    'starts_at' => $product->flash_sale_starts_at,
                    'ends_at' => $product->flash_sale_ends_at,
                    'status' => $status,
                    'redemptions' => null,
                ]);
            }
        }
        $activeCampaignCount = $campaigns->where('status', 'active')->count()
            + $productDiscounts->where('status', 'active')->count()
            + $flashCampaigns->where('status', 'active')->count();
        $scheduledCampaignCount = $campaigns->where('status', 'scheduled')->count()
            + $productDiscounts->where('status', 'scheduled')->count()
            + $flashCampaigns->where('status', 'scheduled')->count();
        $campaigns = $campaigns->concat($productDiscounts)->concat($flashCampaigns)
            ->sortByDesc(fn (array $campaign): int => optional($campaign['starts_at'])->getTimestamp() ?? 0)
            ->take(100)
            ->values();

        return [
            'voucher_claims' => $voucherClaims,
            'voucher_redemptions' => $voucherRedemptions,
            'bundle_redemptions' => $bundleRedemptions,
            'buy_x_get_y_redemptions' => $buyXGetYRedemptions,
            'promotion_attributed_revenue' => round($promotionAttributedRevenue, 2),
            'seller_funded_discount_cost' => round($sellerFundedDiscountCost, 2),
            'recognized_flash_sale_units' => $flashSaleUnits,
            'active_campaigns' => $activeCampaignCount,
            'scheduled_campaigns' => $scheduledCampaignCount,
            'active_product_discounts' => $productDiscounts->where('status', 'active')->count(),
            'scheduled_product_discounts' => $productDiscounts->where('status', 'scheduled')->count(),
            'active_flash_sales' => $flashCampaigns->where('status', 'active')->count(),
            'scheduled_flash_sales' => $flashCampaigns->where('status', 'scheduled')->count(),
            'active_special_offers' => $activeSpecialOffers,
            'scheduled_special_offers' => $scheduledSpecialOffers,
            'campaign_history' => $campaigns,
        ];
    }

    private function voucherStatus(Voucher $voucher, Carbon $now, int $redemptions): string
    {
        if ($voucher->requiresReview()) {
            return 'review required';
        }
        if (! $voucher->is_active) {
            return 'paused';
        }
        if ($voucher->starts_at?->isFuture()) {
            return 'scheduled';
        }
        if ($voucher->ends_at && $voucher->ends_at->lt($now)) {
            return 'expired';
        }
        if ($voucher->usage_limit !== null && $redemptions >= $voucher->usage_limit) {
            return 'exhausted';
        }

        return 'active';
    }
}
