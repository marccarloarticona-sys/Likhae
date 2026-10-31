<?php

namespace App\Models\Finance;

use App\Models\Buyer\Order;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShipmentFinancialAllocation extends Model
{
    protected $fillable = [
        'shipment_id', 'seller_order_id', 'order_id', 'currency', 'original_product_amount',
        'product_amount', 'discount_amount', 'shipping_fee', 'platform_commission', 'seller_payable',
        'logistics_allocation', 'rider_allocation', 'discount_funding_status', 'status',
        'protection_amount', 'protection_account_status',
        'calculated_at', 'approved_by_user_id', 'approved_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'original_product_amount' => 'decimal:2', 'product_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'shipping_fee' => 'decimal:2',
            'platform_commission' => 'decimal:2', 'seller_payable' => 'decimal:2',
            'logistics_allocation' => 'decimal:2', 'rider_allocation' => 'decimal:2',
            'protection_amount' => 'decimal:2',
            'calculated_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function sellerOrder(): BelongsTo { return $this->belongsTo(SellerOrder::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function sellerSettlement(): HasOne { return $this->hasOne(SellerSettlement::class); }
    public function logisticsPool(): HasOne { return $this->hasOne(LogisticsEarningPool::class); }
    public function logisticsPools(): HasMany { return $this->hasMany(LogisticsEarningPool::class); }
    public function riderPool(): HasOne { return $this->hasOne(RiderEarningPool::class); }
}
