<?php

namespace App\Models\Seller;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPromotionComponent extends Model
{
    protected $fillable = [
        'seller_promotion_id',
        'product_variant_id',
        'quantity',
        'choice_group',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(SellerPromotion::class, 'seller_promotion_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
