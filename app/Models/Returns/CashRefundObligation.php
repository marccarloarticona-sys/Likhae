<?php

namespace App\Models\Returns;

use App\Models\Buyer\Order;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashRefundObligation extends Model
{
    use HasFactory;

    public const APPROVED = 'APPROVED';
    public const CASH_READY = 'CASH_READY';
    public const CASH_PAID = 'CASH_PAID';
    public const COMPLETED = 'COMPLETED';

    protected $fillable = [
        'seller_return_refund_request_id', 'return_refund_request_id', 'buyer_user_id', 'order_id', 'seller_order_id',
        'approved_amount', 'product_refund_amount', 'shipping_refund_amount', 'protection_refund_amount',
        'shipping_refund_policy', 'protection_refund_policy', 'state', 'idempotency_key',
        'approved_by_user_id', 'approved_at', 'cash_ready_by_user_id', 'cash_ready_at',
        'paid_by_user_id', 'paid_at', 'payment_reference', 'payment_reference_hash',
        'buyer_acknowledged_by_user_id', 'buyer_acknowledged_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_amount' => 'decimal:2', 'product_refund_amount' => 'decimal:2',
            'shipping_refund_amount' => 'decimal:2', 'protection_refund_amount' => 'decimal:2',
            'approved_at' => 'datetime', 'cash_ready_at' => 'datetime', 'paid_at' => 'datetime',
            'buyer_acknowledged_at' => 'datetime',
        ];
    }

    public function sellerRequest(): BelongsTo { return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id'); }
    public function request(): BelongsTo { return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id'); }
    public function buyer(): BelongsTo { return $this->belongsTo(User::class, 'buyer_user_id'); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function sellerOrder(): BelongsTo { return $this->belongsTo(SellerOrder::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by_user_id'); }
    public function cashReadyBy(): BelongsTo { return $this->belongsTo(User::class, 'cash_ready_by_user_id'); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by_user_id'); }
}
