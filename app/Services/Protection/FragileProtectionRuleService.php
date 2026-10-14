<?php

namespace App\Services\Protection;

use App\Models\Buyer\CartItem;
use App\Models\Protection\FragileProtectionRule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class FragileProtectionRuleService
{
    public function quoteForCartItem(CartItem $item, ?int $quantity = null): ?array
    {
        if (! Schema::hasTable('fragile_protection_rules')) {
            return null;
        }

        $item->loadMissing('productVariant.product.category.parent');
        $variant = $item->productVariant;
        $product = $variant?->product;
        $category = $product?->category;
        if (! $variant || ! $product || ! $category) {
            return null;
        }

        $rule = $this->applicableRule($product->id, $category->id, $category->parent_id, (float) $variant->final_price);
        if (! $rule) {
            return null;
        }

        $units = max(1, (int) ($quantity ?? $item->quantity));
        $unitFee = self::calculateFee($rule->toArray(), (float) $variant->final_price);
        if ($unitFee === null) {
            return null;
        }

        $fee = $rule->scope === FragileProtectionRule::SCOPE_PER_UNIT
            ? $unitFee * $units
            : $unitFee;

        return [
            'eligible' => true,
            'fee' => round($fee, 2),
            'unit_fee' => round($unitFee, 2),
            'quantity' => $units,
            'scope' => $rule->scope,
            'rule_id' => $rule->id,
            'risk_level' => $rule->risk_level,
            'rule_snapshot' => $this->snapshot($rule),
        ];
    }

    /**
     * Pure calculation used by checkout and DB-free tests. A rule without an
     * explicit supported scope is intentionally not chargeable.
     *
     * @param array<string, mixed> $rule
     */
    public static function calculateFee(array $rule, float $eligiblePrice): ?float
    {
        $scope = strtoupper(trim((string) ($rule['scope'] ?? '')));
        if (! in_array($scope, [FragileProtectionRule::SCOPE_PER_UNIT, FragileProtectionRule::SCOPE_PER_LINE], true)) {
            return null;
        }

        $price = max(0, round($eligiblePrice, 2));
        $minimumPrice = $rule['minimum_product_price'] ?? null;
        $maximumPrice = $rule['maximum_product_price'] ?? null;
        if ($minimumPrice !== null && $price < (float) $minimumPrice) {
            return null;
        }
        if ($maximumPrice !== null && $price > (float) $maximumPrice) {
            return null;
        }

        $type = strtoupper(trim((string) ($rule['calculation_type'] ?? '')));
        $percentage = max(0, (float) ($rule['percentage_rate'] ?? 0));
        $flat = max(0, (float) ($rule['flat_amount'] ?? 0));
        $fee = match ($type) {
            FragileProtectionRule::TYPE_PERCENTAGE => $percentage > 0 ? $price * $percentage / 100 : null,
            FragileProtectionRule::TYPE_FLAT => $flat > 0 ? $flat : null,
            FragileProtectionRule::TYPE_HYBRID => $percentage > 0
                ? $price * $percentage / 100
                : ($flat > 0 ? $flat : null),
            default => null,
        };
        if ($fee === null) {
            return null;
        }

        if ($type === FragileProtectionRule::TYPE_HYBRID && $flat > 0 && $percentage > 0) {
            $fee = max($fee, $flat);
        }
        $minimumFee = $rule['minimum_fee'] ?? null;
        $maximumFee = $rule['maximum_fee'] ?? null;
        if ($minimumFee !== null && $fee < (float) $minimumFee) {
            $fee = (float) $minimumFee;
        }
        if ($maximumFee !== null) {
            $fee = min($fee, (float) $maximumFee);
        }

        return round(max(0, $fee), 2);
    }

    private function applicableRule(int $productId, int $categoryId, ?int $parentCategoryId, float $price): ?FragileProtectionRule
    {
        $now = Carbon::now();
        $base = fn ($query) => $query
            ->where('active', true)
            ->where(fn ($date) => $date->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($date) => $date->whereNull('effective_until')->orWhere('effective_until', '>=', $now))
            ->where(fn ($band) => $band->whereNull('minimum_product_price')->orWhere('minimum_product_price', '<=', $price))
            ->where(fn ($band) => $band->whereNull('maximum_product_price')->orWhere('maximum_product_price', '>=', $price));

        $ruleQuery = FragileProtectionRule::query()->where('product_id', $productId);
        $rule = $base($ruleQuery)->latest('id')->first();
        if ($rule) {
            return $rule;
        }

        $ruleQuery = FragileProtectionRule::query()->where('subcategory_id', $categoryId);
        $rule = $base($ruleQuery)->latest('id')->first();
        if ($rule) {
            return $rule;
        }

        $categoryRuleId = $parentCategoryId ?: $categoryId;

        $ruleQuery = FragileProtectionRule::query()->where('category_id', $categoryRuleId);

        return $base($ruleQuery)->latest('id')->first();
    }

    /** @return array<string, mixed> */
    private function snapshot(FragileProtectionRule $rule): array
    {
        return [
            'id' => $rule->id,
            'category_id' => $rule->category_id,
            'subcategory_id' => $rule->subcategory_id,
            'product_id' => $rule->product_id,
            'risk_level' => $rule->risk_level,
            'calculation_type' => $rule->calculation_type,
            'scope' => $rule->scope,
            'percentage_rate' => $rule->percentage_rate,
            'flat_amount' => $rule->flat_amount,
            'minimum_fee' => $rule->minimum_fee,
            'maximum_fee' => $rule->maximum_fee,
            'minimum_product_price' => $rule->minimum_product_price,
            'maximum_product_price' => $rule->maximum_product_price,
            'active' => $rule->active,
            'effective_from' => $rule->effective_from?->toIso8601String(),
            'effective_until' => $rule->effective_until?->toIso8601String(),
        ];
    }
}
