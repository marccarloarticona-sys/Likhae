<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Voucher;
use App\Services\Finance\PlatformCampaignBudgetService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlatformCampaignCatalog
{
    private const HOMEPAGE_CAROUSEL_LIMIT = 8;

    /** @return Collection<int, Voucher> Published, active campaigns explicitly placed in the homepage carousel. */
    public function homepageCarousel(): Collection
    {
        $requiredColumns = [
            'id', 'name', 'seller_profile_id', 'platform_code_key', 'visibility', 'is_active', 'starts_at', 'ends_at',
            'discount_type', 'discount_value', 'minimum_order_amount', 'maximum_discount_amount',
            'usage_limit', 'requires_review', 'review_reason', 'campaign_budget_total',
            'homepage_carousel_enabled', 'homepage_carousel_desktop_image_path',
            'homepage_carousel_mobile_image_path', 'homepage_carousel_headline',
            'homepage_carousel_description', 'homepage_carousel_cta_label',
            'homepage_carousel_cta_destination', 'homepage_carousel_priority',
        ];
        if (! Schema::hasTable('vouchers')
            || collect($requiredColumns)->contains(fn (string $column): bool => ! Schema::hasColumn('vouchers', $column))) {
            return collect();
        }

        $now = now();
        $campaigns = Voucher::query()
            ->whereNull('seller_profile_id')
            ->whereNotNull('platform_code_key')
            ->where('visibility', 'PUBLIC')
            ->where('is_active', true)
            ->where('homepage_carousel_enabled', true)
            ->whereNotNull('homepage_carousel_desktop_image_path')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('homepage_carousel_priority')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(self::HOMEPAGE_CAROUSEL_LIMIT)
            ->get($requiredColumns);

        if ($campaigns->isEmpty()) {
            return collect();
        }

        $redemptionCounts = VoucherRedemptionCounter::countsFor($campaigns);
        $budgets = app(PlatformCampaignBudgetService::class);
        $selectableCampaignIds = $budgets->selectableIds($campaigns);

        return $campaigns
            ->filter(fn (Voucher $campaign): bool => ! $campaign->requiresReview()
                && ($campaign->usage_limit === null
                    || $redemptionCounts->get($campaign->id, 0) < $campaign->usage_limit)
                && $selectableCampaignIds->contains($campaign->id))
            ->values();
    }

    /**
     * Return LIKHAE platform campaigns that are active now or scheduled to start.
     * Paused, expired, and globally exhausted campaigns are omitted.
     *
     * @return Collection<int, Voucher>
     */
    public function available(): Collection
    {
        if (! Schema::hasColumn('vouchers', 'platform_code_key')) {
            return collect();
        }

        $now = now();
        $campaigns = Voucher::query()
            ->whereNull('seller_profile_id')
            ->whereNotNull('platform_code_key')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderByRaw('CASE WHEN starts_at IS NULL OR starts_at <= ? THEN 0 ELSE 1 END', [$now])
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();

        $redemptionCounts = VoucherRedemptionCounter::countsFor($campaigns);

        return $campaigns
            ->filter(fn (Voucher $campaign): bool => ! $campaign->requiresReview()
                && ($campaign->usage_limit === null
                    || $redemptionCounts->get($campaign->id, 0) < $campaign->usage_limit))
            ->filter(fn (Voucher $campaign): bool => app(PlatformCampaignBudgetService::class)->selectable($campaign))
            ->values();
    }
}
