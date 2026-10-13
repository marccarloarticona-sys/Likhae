<?php

namespace App\Models\Buyer;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'sku',
        'variant_description',
        'unit_price',
        'original_unit_price',
        'quantity',
        'discount_amount',
        'line_total',
        'protection_selected',
        'protection_fee',
        'protection_rule_id',
        'protection_scope',
        'protection_rule_snapshot',
        'protection_handling_flag',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'original_unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'protection_selected' => 'boolean',
            'protection_fee' => 'decimal:2',
            'protection_rule_snapshot' => 'array',
            'protection_handling_flag' => 'boolean',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function hasAdditionalCare(): bool
    {
        return (bool) ($this->protection_selected ?? false);
    }
}
