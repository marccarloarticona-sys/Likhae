<?php

namespace App\Models\Buyer;

use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerOrder;
use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'flash_sale_quantity',
        'flash_sale_campaign_key',
        'quantity',
        'discount_amount',
        'promotion_discount_amount',
        'promotion_snapshot',
        'seller_voucher_discount',
        'platform_voucher_discount',
        'platform_shipping_voucher_discount',
        'reward_points_discount',
        'funding_snapshot_version',
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
            'flash_sale_quantity' => 'integer',
            'discount_amount' => 'decimal:2',
            'promotion_discount_amount' => 'decimal:2',
            'promotion_snapshot' => 'array',
            'seller_voucher_discount' => 'decimal:2',
            'platform_voucher_discount' => 'decimal:2',
            'platform_shipping_voucher_discount' => 'decimal:2',
            'reward_points_discount' => 'decimal:2',
            'funding_snapshot_version' => 'integer',
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

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function returnRefundRequestItems(): HasMany
    {
        return $this->hasMany(ReturnRefundRequestItem::class);
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
