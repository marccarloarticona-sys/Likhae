<?php

namespace App\Services\Marketplace;

use App\Models\Seller\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PlatformCampaignCatalog
{
    /** @return Collection<int, Voucher> A compact Home preview that includes upcoming events when present. */
    public function homePreview(int $limit = 4): Collection
    {
        $campaigns = $this->available();
        $upcoming = $campaigns->filter(fn (Voucher $campaign): bool => $campaign->starts_at?->isFuture() ?? false);

        if ($upcoming->isEmpty() || $limit < 2) {
            return $campaigns->take($limit)->values();
        }

        $active = $campaigns->reject(fn (Voucher $campaign): bool => $campaign->starts_at?->isFuture() ?? false);
        $featured = $active->take(max(1, $limit - 1))->concat($upcoming->take(1));

        if ($featured->count() < $limit) {
            $featured = $featured->concat(
                $campaigns
                    ->reject(fn (Voucher $campaign): bool => $featured->contains('id', $campaign->id))
                    ->take($limit - $featured->count()),
            );
        }

        return $featured->values();
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
            ->filter(fn (Voucher $campaign): bool => $campaign->usage_limit === null
                || $redemptionCounts->get($campaign->id, 0) < $campaign->usage_limit)
            ->values();
    }
}
