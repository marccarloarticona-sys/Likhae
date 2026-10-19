<?php

namespace App\Services\Marketplace;

use App\Models\Buyer\Review;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RewardPointsService
{
    public const POINTS_PER_PESO = 100;
    public const MINIMUM_REDEMPTION = 100;

    public function balance(User $buyer): int
    {
        $earned = ($buyer->orders()->where('status', 'COMPLETED')->count() * 50)
            + (Review::query()->where('buyer_user_id', $buyer->id)->count() * 20);
        $redeemed = SellerOrder::query()
            ->where('status', '<>', 'CANCELLED')
            ->whereHas('order', fn ($query) => $query
                ->where('buyer_user_id', $buyer->id)
                ->where('status', '<>', 'CANCELLED'))
            ->sum('reward_points_redeemed');

        return max(0, (int) $earned - (int) $redeemed);
    }

    public function maximumRedeemablePoints(User $buyer, int $eligibleAmountCents): int
    {
        $wholePesoPointLimit = intdiv(max(0, $eligibleAmountCents), 100) * self::POINTS_PER_PESO;
        $balanceLimit = intdiv($this->balance($buyer), self::POINTS_PER_PESO) * self::POINTS_PER_PESO;

        return min($wholePesoPointLimit, $balanceLimit);
    }

    public function assertRedemptionIsValid(User $buyer, int $points, int $eligibleAmountCents): void
    {
        if ($points === 0) {
            return;
        }

        if ($points < self::MINIMUM_REDEMPTION || $points % self::POINTS_PER_PESO !== 0) {
            throw ValidationException::withMessages([
                'reward_points' => 'Redeem at least 100 points in 100-point increments. Every 100 points gives PHP 1 off.',
            ]);
        }

        $balance = $this->balance($buyer);
        if ($points > $balance) {
            throw ValidationException::withMessages([
                'reward_points' => 'You do not have enough reward points for this redemption. Refresh checkout to see your current balance.',
            ]);
        }

        $maximum = $this->maximumRedeemablePoints($buyer, $eligibleAmountCents);
        if ($points > $maximum) {
            throw ValidationException::withMessages([
                'reward_points' => 'The points discount cannot exceed the eligible product total after vouchers.',
            ]);
        }
    }
}
