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
        'reward_quantity',
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
}
