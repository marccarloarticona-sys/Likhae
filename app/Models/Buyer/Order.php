<?php

namespace App\Models\Buyer;

use App\Models\Admin\Dispute;
use App\Models\Communication\Conversation;
use App\Models\Finance\CodCollection;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use App\Models\Buyer\ReturnRefundRequest;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'buyer_user_id',
        'source_cart_id',
        'status',
        'currency',
        'subtotal',
        'discount_total',
        'shipping_total',
        'grand_total',
        'protection_total',
        'payment_status',
        'placed_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'protection_total' => 'decimal:2',
            'placed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function sourceCart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'source_cart_id');
    }

    public function address(): HasOne
    {
        return $this->hasOne(OrderAddress::class);
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class);
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(OrderItem::class, SellerOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function codCollections(): HasMany
    {
        return $this->hasMany(CodCollection::class);
    }

    public function financialLedgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function returnRefundRequests(): HasMany
    {
        return $this->hasMany(ReturnRefundRequest::class);
    }

    public function sellerReturnRefundRequests(): HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\Seller\SellerReturnRefundRequest::class,
            \App\Models\Buyer\ReturnRefundRequest::class,
            'order_id',
            'return_refund_request_id',
            'id',
            'id',
        );
    }
}
