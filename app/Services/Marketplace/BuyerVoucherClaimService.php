<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\SellerFollower;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Finance\PlatformCampaignBudgetService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class BuyerVoucherClaimService
{
    /** @return Collection<int, Voucher> */
    public function claimablePlatformVouchers(User $buyer): Collection
    {
        if (! app(PlatformCampaignEligibility::class)->controlsReady()) {
            return collect();
        }
        $vouchers = Voucher::query()
            ->whereNull('seller_profile_id')->whereNotNull('platform_code_key')
            ->where('distribution_mode', 'CLAIMABLE')->where('visibility', 'PUBLIC')
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->orderBy('name')->get();
        $claimed = BuyerVoucherClaim::query()->where('buyer_user_id', $buyer->id)
            ->whereIn('voucher_id', $vouchers->modelKeys())->pluck('voucher_id')->map(fn ($id): int => (int) $id)->all();
        $global = VoucherRedemptionCounter::countsFor($vouchers);
        $buyerUses = VoucherRedemptionCounter::countsFor($vouchers, (int) $buyer->id);
        $eligibility = app(PlatformCampaignEligibility::class);

        return $vouchers->reject(fn (Voucher $voucher): bool => in_array((int) $voucher->id, $claimed, true)
            || $eligibility->status($voucher) !== 'ACTIVE'
            || ! $eligibility->audienceEligible($voucher, $buyer)
            || $voucher->requiresReview()
            || ($voucher->usage_limit !== null && $global->get($voucher->id, 0) >= $voucher->usage_limit)
            || ($voucher->per_user_limit !== null && $buyerUses->get($voucher->id, 0) >= $voucher->per_user_limit)
            || ! app(PlatformCampaignBudgetService::class)->selectable($voucher))->values();
    }

    /**
     * Seller vouchers available to claim now. Upcoming vouchers are not claimable:
     * this matches the existing Rewards policy, which lists only started vouchers.
     *
     * @return Collection<int, Voucher>
     */
    public function claimableSellerVouchers(User $buyer): Collection
    {
        if (! $buyer->isActive() || ! $buyer->isAccountType(User::TYPE_BUYER)) {
            return collect();
        }

        $vouchers = $this->sellerVoucherQuery()
            ->when(Schema::hasColumn('vouchers', 'visibility'), fn ($query) => $query->whereIn('visibility', ['PUBLIC', 'FOLLOWERS']))
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->whereHas('sellerProfile', fn ($query) => $query
                ->where('status', 'ACTIVE')
                ->whereHas('user', fn ($user) => $user
                    ->where('account_type', User::TYPE_SELLER)
                    ->where('status', User::STATUS_ACTIVE)))
            ->with('sellerProfile')
            ->when(Schema::hasTable('voucher_products'), fn ($query) => $query->with('eligibleProducts:id'))
            ->when(Schema::hasTable('voucher_variants'), fn ($query) => $query->with('eligibleVariants:id,product_id'))
            ->orderBy('name')
            ->get();

        if ($vouchers->isEmpty()) {
            return $vouchers;
        }

        $claimedIds = BuyerVoucherClaim::query()
            ->where('buyer_user_id', $buyer->id)
            ->whereIn('voucher_id', $vouchers->modelKeys())
            ->pluck('voucher_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $globalUses = VoucherRedemptionCounter::countsFor($vouchers);
        $buyerUses = VoucherRedemptionCounter::countsFor($vouchers, (int) $buyer->id);
        $followingSellerIds = Schema::hasTable('seller_followers')
            ? SellerFollower::query()->where('buyer_user_id', $buyer->id)->pluck('seller_profile_id')->map(fn ($id): int => (int) $id)->all()
            : [];

        return $vouchers->reject(fn (Voucher $voucher): bool => $voucher->requiresReview()
            || (Schema::hasColumn('vouchers', 'visibility') && $voucher->visibility === 'FOLLOWERS'
                && ! in_array((int) $voucher->seller_profile_id, $followingSellerIds, true))
            || in_array((int) $voucher->id, $claimedIds, true)
            || ($voucher->usage_limit !== null && $globalUses->get($voucher->id, 0) >= $voucher->usage_limit)
            || ($voucher->per_user_limit !== null && $buyerUses->get($voucher->id, 0) >= $voucher->per_user_limit)
        )->values();
    }

    /** @return Collection<int, BuyerVoucherClaim> */
    public function collectedSellerClaims(User $buyer): Collection
    {
        $query = BuyerVoucherClaim::query()
            ->where('buyer_user_id', $buyer->id)
            ->whereHas('voucher', fn ($query) => $query
                ->whereNotNull('seller_profile_id')
                ->when(
                    Schema::hasColumn('vouchers', 'platform_code_key'),
                    fn ($voucherQuery) => $voucherQuery->whereNull('platform_code_key'),
                ))
            ->with('voucher.sellerProfile.user');
        if (Schema::hasTable('voucher_products')) {
            $query->with('voucher.eligibleProducts:id');
        }
        if (Schema::hasTable('voucher_variants')) {
            $query->with('voucher.eligibleVariants:id,product_id');
        }

        return $query->latest('created_at')->get();
    }

    public function claim(User $buyer, int $voucherId): BuyerVoucherClaim
    {
        if (! $buyer->isActive() || ! $buyer->isAccountType(User::TYPE_BUYER)) {
            abort(403);
        }

        try {
            return DB::transaction(function () use ($buyer, $voucherId): BuyerVoucherClaim {
                $voucher = Voucher::query()
                    ->whereKey($voucherId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $platform = $voucher->seller_profile_id === null && $voucher->platform_code_key !== null;
                abort_unless($platform || $this->isSellerVoucher($voucher), 404);
                if ($platform) {
                    abort_unless(app(PlatformCampaignEligibility::class)->distribution($voucher) === 'CLAIMABLE', 404);
                }

                $existing = BuyerVoucherClaim::query()
                    ->where('buyer_user_id', $buyer->id)
                    ->where('voucher_id', $voucher->id)
                    ->first();

                if ($existing) {
                    return $existing;
                }

                if ($platform) {
                    $this->assertPlatformClaimable($voucher, $buyer);
                } else {
                    $this->assertClaimable($voucher, $buyer);
                }

                return BuyerVoucherClaim::query()->create([
                    'buyer_user_id' => $buyer->id,
                    'voucher_id' => $voucher->id,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            // The unique index remains authoritative if simultaneous requests
            // race. A committed row for this same Buyer is an idempotent success.
            $existing = BuyerVoucherClaim::query()
                ->where('buyer_user_id', $buyer->id)
                ->where('voucher_id', $voucherId)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function unavailableReason(Voucher $voucher, User $buyer, int $globalUses, int $buyerUses): ?string
    {
        if (! $this->isSellerVoucher($voucher)) {
            return 'This is a platform campaign, not a Seller voucher.';
        }

        if ($voucher->requiresReview()) {
            return 'This voucher is under review and cannot be used.';
        }

        if (Schema::hasColumn('vouchers', 'visibility') && $voucher->visibility === 'CODE_ONLY') {
            return 'This voucher is available by code only.';
        }

        $seller = $voucher->sellerProfile;
        if (! $seller
            || $seller->status !== 'ACTIVE'
            || ! $seller->user
            || ! $seller->user->isActive()
            || ! $seller->user->isAccountType(User::TYPE_SELLER)) {
            return 'This Seller is not currently eligible for orders.';
        }

        if (! $voucher->is_active) {
            return 'This voucher is paused.';
        }

        if ($voucher->starts_at?->isFuture()) {
            return 'This voucher is not available yet.';
        }

        if ($voucher->ends_at && $voucher->ends_at->lt(now())) {
            return 'This voucher has expired.';
        }

        if (Schema::hasColumn('vouchers', 'visibility') && $voucher->visibility === 'FOLLOWERS') {
            $isFollowing = Schema::hasTable('seller_followers')
                && SellerFollower::query()
                    ->where('buyer_user_id', $buyer->id)
                    ->where('seller_profile_id', $voucher->seller_profile_id)
                    ->exists();
            if (! $isFollowing) {
                return 'Follow this store to collect and use its follower reward.';
            }
        }

        if ($voucher->usage_limit !== null && $globalUses >= $voucher->usage_limit) {
            return 'This voucher has reached its usage limit.';
        }

        if ($voucher->per_user_limit !== null && $buyerUses >= $voucher->per_user_limit) {
            return 'You have reached the usage limit for this voucher.';
        }

        return null;
    }

    private function assertClaimable(Voucher $voucher, User $buyer): void
    {
        $voucher->loadMissing(['sellerProfile.user']);
        $usage = VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, (int) $buyer->id);
        $reason = $this->unavailableReason($voucher, $buyer, $usage['redemptions'], $usage['buyer_redemptions']);

        if ($reason !== null) {
            throw ValidationException::withMessages(['voucher' => $reason]);
        }
    }

    private function assertPlatformClaimable(Voucher $voucher, User $buyer): void
    {
        $eligibility = app(PlatformCampaignEligibility::class);
        $usage = VoucherRedemptionCounter::lockedUsageFor((int) $voucher->id, (int) $buyer->id);
        $reason = $eligibility->status($voucher) !== 'ACTIVE' ? 'This LIKHAE voucher is not active.' : null;
        $reason ??= ! $eligibility->audienceEligible($voucher, $buyer) ? 'This LIKHAE voucher is unavailable for your order history.' : null;
        $reason ??= $voucher->requiresReview() ? 'This LIKHAE voucher needs review.' : null;
        $reason ??= $voucher->usage_limit !== null && $usage['redemptions'] >= $voucher->usage_limit ? 'This LIKHAE voucher has reached its usage limit.' : null;
        $reason ??= $voucher->per_user_limit !== null && $usage['buyer_redemptions'] >= $voucher->per_user_limit ? 'You have reached the usage limit for this LIKHAE voucher.' : null;
        $reason ??= ! app(PlatformCampaignBudgetService::class)->selectable($voucher) ? 'This LIKHAE voucher has no available funding.' : null;
        if ($reason !== null) {
            throw ValidationException::withMessages(['voucher' => $reason]);
        }
    }

    private function sellerVoucherQuery()
    {
        return Voucher::query()
            ->whereNotNull('seller_profile_id')
            ->when(
                Schema::hasColumn('vouchers', 'platform_code_key'),
                fn ($query) => $query->whereNull('platform_code_key'),
            );
    }

    private function isSellerVoucher(Voucher $voucher): bool
    {
        return $voucher->seller_profile_id !== null
            && (! Schema::hasColumn('vouchers', 'platform_code_key') || $voucher->platform_code_key === null);
    }
}
