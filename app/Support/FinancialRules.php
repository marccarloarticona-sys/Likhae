<?php

namespace App\Support;

/**
 * Decimal-safe policy calculations for new Step 6/7 financial records.
 * Amounts are carried as integer cents and returned as two-decimal strings.
 */
final class FinancialRules
{
    public const COD_METHOD = 'COD';
    public const COMMISSION_RATE = 0.10;
    public const COD_RIDER_SHARE_PERCENTAGE = '60.0000';
    public const DEFAULT_RIDER_POOL_PERCENTAGE = '60.0000';

    private function __construct() {}

    public static function cents(string|int|float|null $amount): int
    {
        $value = $amount ?? 0;
        // Callers should pass DECIMAL strings. Floats are normalized to a
        // fixed decimal string at the boundary so all actual arithmetic below
        // remains integer-centavo arithmetic.
        $text = is_float($value) ? number_format($value, 8, '.', '') : trim((string) $value);
        if (! preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $text, $matches)) {
            throw new \InvalidArgumentException('Money amounts must be numeric decimal values.');
        }

        $whole = (int) $matches[2];
        $fraction = $matches[3] ?? '';
        $cents = ($whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        if (isset($fraction[2]) && $fraction[2] >= '5') {
            $cents++;
        }

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }

    public static function amount(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '')
            .intdiv($absolute, 100)
            .'.'
            .str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function commissionCents(int $commissionableProductCents, string|int|float $rate = self::COMMISSION_RATE): int
    {
        $rateText = number_format((float) $rate, 6, '.', '');
        $rateUnits = (int) round((float) $rateText * 1_000_000);

        return intdiv(($commissionableProductCents * $rateUnits) + 500_000, 1_000_000);
    }

    /** @param array<int, int> $weights @return array<int, int> */
    public static function allocateCents(int $amountCents, array $weights): array
    {
        if ($amountCents < 0) {
            throw new \InvalidArgumentException('An allocation amount cannot be negative.');
        }
        $weights = array_map(fn ($weight): int => max(0, (int) $weight), array_values($weights));
        $totalWeight = array_sum($weights);
        if ($totalWeight === 0) {
            if ($amountCents !== 0) {
                throw new \InvalidArgumentException('A positive amount cannot be allocated across zero-value lines.');
            }

            return array_fill(0, count($weights), 0);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;
        foreach ($weights as $index => $weight) {
            $numerator = $amountCents * $weight;
            $share = intdiv($numerator, $totalWeight);
            $shares[$index] = $share;
            $allocated += $share;
            $remainders[] = ['index' => $index, 'remainder' => $numerator % $totalWeight];
        }
        usort($remainders, fn (array $left, array $right): int =>
            ($right['remainder'] <=> $left['remainder']) ?: ($left['index'] <=> $right['index']));
        $remaining = $amountCents - $allocated;
        for ($index = 0; $index < $remaining; $index++) {
            $shares[$remainders[$index]['index']]++;
        }

        return $shares;
    }

    /**
     * @param list<array{original_unit_price:string|int|float,charged_unit_price:string|int|float,quantity:int}> $items
     * @return array<string, string|bool>
     */
    public static function allocation(
        array $items,
        string|int|float $voucherDiscount = 0,
        string|int|float $shippingFee = 50,
        string|int|float $riderPoolPercentage = self::DEFAULT_RIDER_POOL_PERCENTAGE,
        string|int|float $shippingVoucherDiscount = 0,
        ?array $fundingSnapshot = null,
        string|int|float $commissionRate = self::COMMISSION_RATE,
        string|int|float $additionalCareFee = 0,
    ): array
    {
        $original = 0;
        $charged = 0;

        foreach ($items as $item) {
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $original += self::cents($item['original_unit_price'] ?? 0) * $quantity;
            $charged += self::cents($item['charged_unit_price'] ?? 0) * $quantity;
        }

        $voucher = self::cents($voucherDiscount);
        $shippingDiscount = self::cents($shippingVoucherDiscount);
        $commission = self::commissionCents($original, $commissionRate);
        $shipping = self::cents($shippingFee);
        $additionalCare = max(0, self::cents($additionalCareFee));
        $deliveryEarnings = self::deliveryEarningsCents($shippingFee, $additionalCareFee);
        // Split each fee component independently. Inter-hub route percentages
        // are applied to base shipping later; Additional Care keeps its
        // existing Rider/Logistics allocation policy.
        // Keep the parent Rider/Logistics pools balanced first; the Rider
        // amount is then divided 1:2 across Pickup and Delivery roles.
        $shippingAllocation = self::shippingAllocationCents($shipping, $riderPoolPercentage);
        $additionalCareAllocation = self::shippingAllocationCents($additionalCare, $riderPoolPercentage);
        $allocation = [
            'rider_cents' => $shippingAllocation['rider_cents'] + $additionalCareAllocation['rider_cents'],
            'logistics_cents' => $shippingAllocation['logistics_cents'] + $additionalCareAllocation['logistics_cents'],
        ];
        if ($fundingSnapshot === null) {
            // Historical combined discounts do not identify who funded them.
            $discount = max(0, $original - $charged) + $voucher + $shippingDiscount;
            $discountFundingUnresolved = $discount > 0;
            $productAmount = max(0, $charged - $voucher);
            $sellerPayable = $discountFundingUnresolved ? null : self::amount(max(0, $charged - $commission));
        } else {
            $sellerVoucher = self::cents($fundingSnapshot['seller_voucher_discount'] ?? 0);
            $platformVoucher = self::cents($fundingSnapshot['platform_voucher_discount'] ?? 0);
            $sellerPromotion = self::cents($fundingSnapshot['seller_promotion_discount'] ?? 0);
            $rewardPoints = self::cents($fundingSnapshot['reward_points_discount'] ?? 0);
            $sellerMarkdown = max(0, $original - $charged);
            $discount = $sellerMarkdown + $sellerPromotion + $sellerVoucher + $platformVoucher + $rewardPoints + $shippingDiscount;
            $discountFundingUnresolved = false;
            $productAmount = max(0, $charged - $sellerPromotion - $sellerVoucher - $platformVoucher - $rewardPoints);
            $sellerGross = max(0, $original - $sellerMarkdown - $sellerPromotion - $sellerVoucher);
            $sellerPayable = self::amount(max(0, $sellerGross - $commission));
        }

        return [
            'original_product_amount' => self::amount($original),
            'product_amount' => self::amount($productAmount),
            'discount_amount' => self::amount($discount),
            'shipping_fee' => self::amount($shipping),
            'additional_care_amount' => self::amount($additionalCare),
            'delivery_earnings_amount' => self::amount($deliveryEarnings),
            'platform_commission' => self::amount($commission),
            'seller_payable' => $sellerPayable,
            'logistics_allocation' => self::amount($allocation['logistics_cents']),
            'rider_allocation' => self::amount($allocation['rider_cents']),
            'discount_funding_unresolved' => $discountFundingUnresolved,
        ];
    }

    /** @return array{rider_cents:int, logistics_cents:int} */
    public static function shippingAllocation(string|int|float $shippingFee, string|int|float $riderPoolPercentage): array
    {
        return self::shippingAllocationCents(self::cents($shippingFee), $riderPoolPercentage);
    }

    public static function deliveryEarningsCents(
        string|int|float|null $shippingFee,
        string|int|float|null $additionalCareFee = 0,
    ): int {
        return self::cents($shippingFee) + max(0, self::cents($additionalCareFee));
    }

    /** @return array{0:int,1:int,2:int} Rider pool 60%, Origin 30%, Destination 10% of the shipping fee. */
    public static function interHubDeliverySplitCents(int $shippingFeeCents): array
    {
        $split = self::shippingAllocationCents($shippingFeeCents, self::DEFAULT_RIDER_POOL_PERCENTAGE);
        [$origin, $destination] = self::allocateCents($split['logistics_cents'], [30, 10]);

        return [$split['rider_cents'], $origin, $destination];
    }

    /** @return array{rider_cents:int, logistics_cents:int} */
    public static function shippingAllocationCents(int $shippingCents, string|int|float $riderPoolPercentage): array
    {
        $percentageUnits = self::percentageUnits($riderPoolPercentage);
        if ($percentageUnits < 0 || $percentageUnits > 1_000_000) {
            throw new \InvalidArgumentException('The Rider Pool percentage must be between 0 and 100.');
        }

        // Percentages are stored to four decimal places. The integer formula
        // rounds half up in centavos and gives every remainder to Logistics.
        $riderCents = intdiv(($shippingCents * $percentageUnits) + 500_000, 1_000_000);

        return [
            'rider_cents' => $riderCents,
            'logistics_cents' => $shippingCents - $riderCents,
        ];
    }

    /**
     * Allocate Rider Pool centavos by versioned weights and completed roles.
     * Amounts for legs with no completed Rider remain unresolved in the pool.
     *
     * @param array{PICKUP?:list<int>,HUB_TO_HUB?:list<int>,DELIVERY?:list<int>} $completedRidersByRole
     * @param array{PICKUP:int,HUB_TO_HUB:int,DELIVERY:int} $weights
     * @return array{participants:list<array{rider_profile_id:int,participant_role:string,share_cents:int}>,allocated_cents:int,unresolved_cents:int,leg_cents:array<string,int>}
     */
    public static function riderLegDistribution(int $riderPoolCents, array $completedRidersByRole, array $weights): array
    {
        $roles = ['PICKUP', 'HUB_TO_HUB', 'DELIVERY'];
        $eligibleRiders = [];
        $activeRoles = [];
        $totalWeight = 0;
        foreach ($roles as $role) {
            $eligibleRiders[$role] = collect($completedRidersByRole[$role] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
            $weight = max(0, (int) ($weights[$role] ?? 0));
            if ($eligibleRiders[$role]->isNotEmpty() && $weight > 0) {
                $activeRoles[] = $role;
                $totalWeight += $weight;
            }
        }
        if ($riderPoolCents < 0) {
            throw new \InvalidArgumentException('The Rider Pool amount must be non-negative.');
        }

        $legCents = array_fill_keys($roles, 0);
        if ($totalWeight === 0) {
            return [
                'participants' => [],
                'allocated_cents' => 0,
                'unresolved_cents' => $riderPoolCents,
                'leg_cents' => $legCents,
            ];
        }

        $remainders = [];
        $baseTotal = 0;
        foreach ($activeRoles as $position => $role) {
            $weight = max(0, (int) ($weights[$role] ?? 0));
            $numerator = $riderPoolCents * $weight;
            $base = intdiv($numerator, $totalWeight);
            $legCents[$role] = $base;
            $baseTotal += $base;
            $remainders[] = ['role' => $role, 'remainder' => $numerator % $totalWeight, 'position' => $position];
        }
        usort($remainders, fn (array $left, array $right): int =>
            ($right['remainder'] <=> $left['remainder']) ?: ($left['position'] <=> $right['position']));
        $remainingCents = $riderPoolCents - $baseTotal;
        for ($index = 0; $index < $remainingCents; $index++) {
            $role = $remainders[$index]['role'];
            $legCents[$role]++;
        }

        $participants = [];
        $allocatedCents = 0;
        foreach ($activeRoles as $role) {
            $riders = $eligibleRiders[$role];
            if ($legCents[$role] === 0) {
                continue;
            }

            $baseShare = intdiv($legCents[$role], $riders->count());
            $extraCentavos = $legCents[$role] % $riders->count();
            foreach ($riders as $index => $riderId) {
                $share = $baseShare + ($index < $extraCentavos ? 1 : 0);
                if ($share === 0) {
                    continue;
                }
                $participants[] = [
                    'rider_profile_id' => $riderId,
                    'participant_role' => $role,
                    'share_cents' => $share,
                ];
                $allocatedCents += $share;
            }
        }

        return [
            'participants' => $participants,
            'allocated_cents' => $allocatedCents,
            'unresolved_cents' => $riderPoolCents - $allocatedCents,
            'leg_cents' => $legCents,
        ];
    }

    /**
     * Splits the 60% Rider share of base shipping into Pickup and Delivery in
     * a 1:2 ratio (20% and 40% of the fee). Additional Care's Rider share
     * continues to follow saved role weights among completed Rider legs.
     *
     * @param array{PICKUP?:list<int>,HUB_TO_HUB?:list<int>,DELIVERY?:list<int>} $completedRidersByRole
     * @param array{PICKUP:int,HUB_TO_HUB:int,DELIVERY:int} $additionalCareWeights
     * @return array{participants:list<array{rider_profile_id:int,participant_role:string,share_cents:int}>,allocated_cents:int,unresolved_cents:int,leg_cents:array<string,int>}
     */
    public static function riderPoolRoleDistribution(
        int $shippingRiderPoolCents,
        int $additionalCareRiderPoolCents,
        array $completedRidersByRole,
        array $additionalCareWeights,
    ): array {
        if ($shippingRiderPoolCents < 0 || $additionalCareRiderPoolCents < 0) {
            throw new \InvalidArgumentException('Rider Pool components must be non-negative.');
        }

        [$pickupShippingCents, $deliveryShippingCents] = self::allocateCents($shippingRiderPoolCents, [1, 2]);
        $careDistribution = self::riderLegDistribution(
            $additionalCareRiderPoolCents,
            $completedRidersByRole,
            $additionalCareWeights,
        );
        $eligibleRiders = [];
        foreach (['PICKUP', 'DELIVERY'] as $role) {
            $eligibleRiders[$role] = collect($completedRidersByRole[$role] ?? [])
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->sort()
                ->values();
        }

        $shippingByRole = ['PICKUP' => $pickupShippingCents, 'DELIVERY' => $deliveryShippingCents];
        $sharesByParticipant = [];
        $legCents = ['PICKUP' => 0, 'HUB_TO_HUB' => 0, 'DELIVERY' => 0];
        $allocatedCents = 0;

        foreach ($shippingByRole as $role => $roleCents) {
            $riders = $eligibleRiders[$role];
            if ($roleCents <= 0 || $riders->isEmpty()) {
                continue;
            }

            $baseShare = intdiv($roleCents, $riders->count());
            $extraCentavos = $roleCents % $riders->count();
            foreach ($riders as $index => $riderId) {
                $share = $baseShare + ($index < $extraCentavos ? 1 : 0);
                if ($share <= 0) {
                    continue;
                }
                $key = $role.':'.$riderId;
                $sharesByParticipant[$key] = [
                    'rider_profile_id' => $riderId,
                    'participant_role' => $role,
                    'share_cents' => $share,
                ];
                $legCents[$role] += $share;
                $allocatedCents += $share;
            }
        }

        foreach ($careDistribution['participants'] as $participant) {
            $key = $participant['participant_role'].':'.$participant['rider_profile_id'];
            if (! isset($sharesByParticipant[$key])) {
                $sharesByParticipant[$key] = $participant;
            } else {
                $sharesByParticipant[$key]['share_cents'] += $participant['share_cents'];
            }
            $legCents[$participant['participant_role']] += $participant['share_cents'];
            $allocatedCents += $participant['share_cents'];
        }

        return [
            'participants' => array_values($sharesByParticipant),
            'allocated_cents' => $allocatedCents,
            'unresolved_cents' => $shippingRiderPoolCents + $additionalCareRiderPoolCents - $allocatedCents,
            'leg_cents' => $legCents,
        ];
    }

    private static function percentageUnits(string|int|float $percentage): int
    {
        $value = trim((string) $percentage);
        if (! preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('Percentage must be a decimal with up to four places.');
        }

        $whole = (int) $matches[1];
        $fraction = (int) str_pad($matches[2] ?? '', 4, '0');

        return ($whole * 10_000) + $fraction;
    }
}
