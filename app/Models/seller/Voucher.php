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
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'maximum_discount_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'visibility' => 'string',
        ];
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
}
