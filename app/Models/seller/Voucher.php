<?php

namespace App\Models\Seller;

use App\Models\Buyer\BuyerVoucherClaim;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    protected $fillable = [
        'seller_profile_id',
        'platform_code_key',
        'benefit_scope',
        'campaign_budget_total',
        'seller_discount_budget_total',
        'applies_to',
        'visibility',
        'code',
        'name',
        'image_path',
        'image_alt',
        'theme_color',
        'discount_type',
        'discount_value',
        'minimum_order_amount',
        'maximum_discount_amount',
        'usage_limit',
        'per_user_limit',
        'starts_at',
        'ends_at',
        'is_active',
        'requires_review',
        'review_reason',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_discount_amount' => 'decimal:2',
            'campaign_budget_total' => 'decimal:2',
            'seller_discount_budget_total' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'requires_review' => 'boolean',
            'visibility' => 'string',
        ];
    }

    public function hasValidDiscountDefinition(): bool
    {
        $type = strtoupper(trim((string) ($this->getRawOriginal('discount_type') ?? $this->discount_type)));
        $rawValue = $this->getRawOriginal('discount_value') ?? $this->discount_value;
        if (! in_array($type, ['PERCENT', 'FIXED'], true)
            || ! is_numeric($rawValue)
            || ! is_finite((float) $rawValue)
            || (float) $rawValue <= 0) {
            return false;
        }

        if ($type === 'PERCENT' && (float) $rawValue >= 100) {
            return false;
        }

        $maximum = $this->getRawOriginal('maximum_discount_amount') ?? $this->maximum_discount_amount;
        $minimum = $this->getRawOriginal('minimum_order_amount') ?? $this->minimum_order_amount;

        return ($maximum === null || (is_numeric($maximum) && is_finite((float) $maximum) && (float) $maximum > 0))
            && ($minimum === null || (is_numeric($minimum) && is_finite((float) $minimum) && (float) $minimum >= 0));
    }

    public function requiresReview(): bool
    {
        return (bool) ($this->attributes['requires_review'] ?? false) || ! $this->hasValidDiscountDefinition();
    }

    public function reviewReason(): ?string
    {
        if ((bool) ($this->attributes['requires_review'] ?? false)) {
            return $this->attributes['review_reason'] ?? 'This legacy voucher needs review before it can be used.';
        }

        return $this->hasValidDiscountDefinition()
            ? null
            : 'Discount terms are invalid. Review the voucher before enabling it.';
    }

    public function getSafeThemeColorAttribute(): ?string
    {
        $color = (string) $this->theme_color;

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1
            ? strtoupper($color)
            : null;
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function buyerClaims(): HasMany
    {
        return $this->hasMany(BuyerVoucherClaim::class);
    }

    public function eligibleProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'voucher_products')->withTimestamps();
    }

    public function eligibleVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'voucher_variants', 'voucher_id', 'product_variant_id')->withTimestamps();
    }
}
