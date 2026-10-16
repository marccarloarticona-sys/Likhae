<?php

namespace App\Models\Seller;

use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\Dispute;
use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Communication\Conversation;
use App\Models\Finance\SellerSettlement;
use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SellerOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'seller_order_number',
        'order_id',
        'seller_profile_id',
        'buyer_note',
        'voucher_id',
        'status',
        'item_subtotal',
        'voucher_discount',
        'shipping_fee',
        'grand_total',
        'protection_total',
    ];

    protected function casts(): array
    {
        return [
            'item_subtotal' => 'decimal:2',
            'voucher_discount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'protection_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    /** Transitional alias. */
    public function seller(): BelongsTo
    {
        return $this->sellerProfile();
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class)->oldestOfMany();
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    public function commissionTransaction(): HasOne
    {
        return $this->hasOne(CommissionTransaction::class);
    }

    public function sellerSettlement(): HasOne
    {
        return $this->hasOne(SellerSettlement::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['COMPLETED', 'CANCELLED']);
    }

    public function sellerReturnRefundRequests(): HasMany
    {
        return $this->hasMany(SellerReturnRefundRequest::class);
    }
}
