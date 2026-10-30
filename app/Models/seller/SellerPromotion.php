<?php

namespace App\Models\Seller;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPromotion extends Model
{
    public const TYPE_BUNDLE = 'BUNDLE';
    public const TYPE_BUY_X_GET_Y = 'BUY_X_GET_Y';

    protected $fillable = [
        'seller_profile_id',
        'name',
        'promotion_type',
        'bundle_price',
        'buy_variant_id',
        'buy_quantity',
        'reward_variant_id',
        'reward_product_id',
        'reward_quantity',
        'reward_discount_percent',
        'max_redemptions',
        'max_per_buyer',
        'redemptions_used',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'bundle_price' => 'decimal:2',
            'buy_quantity' => 'integer',
            'reward_quantity' => 'integer',
            'reward_discount_percent' => 'decimal:2',
            'max_redemptions' => 'integer',
            'max_per_buyer' => 'integer',
            'redemptions_used' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function components(): HasMany
    {
        return $this->hasMany(SellerPromotionComponent::class);
    }

    public function buyVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'buy_variant_id');
    }

    public function rewardVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'reward_variant_id');
    }

    public function rewardProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'reward_product_id');
    }

    public function getStateAttribute(): string
    {
        if (! $this->is_active) {
            return 'PAUSED';
        }
        if ($this->ends_at && $this->ends_at->lessThanOrEqualTo(now())) {
            return 'EXPIRED';
        }
        if ($this->starts_at && $this->starts_at->greaterThan(now())) {
            return 'SCHEDULED';
        }
        if ($this->max_redemptions !== null && (int) $this->redemptions_used >= (int) $this->max_redemptions) {
            return 'SOLD_OUT';
        }

        return 'ACTIVE';
    }
}
