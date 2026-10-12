<?php

namespace App\Models\Seller;

use App\Models\Admin\Dispute;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerReturnRefundRequest extends Model
{
    use HasFactory;

    public const STATUS_SELLER_REVIEW = 'SELLER_REVIEW';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_REFUND_SENT = 'REFUND_SENT';
    public const STATUS_CONFIRMED = 'CONFIRMED';
    public const STATUS_ESCALATED = 'ESCALATED';
    public const STATUS_DISPUTE_RESOLVED = 'DISPUTE_RESOLVED';
    public const STATUS_ADMIN_REFUND_REQUIRED = 'ADMIN_REFUND_REQUIRED';

    public const FINAL_STATUSES = [self::STATUS_REJECTED, self::STATUS_CONFIRMED, self::STATUS_DISPUTE_RESOLVED];

    protected $fillable = [
        'return_refund_request_id',
        'seller_order_id',
        'seller_profile_id',
        'status',
        'requested_amount',
        'approved_amount',
        'reviewed_by_user_id',
        'reviewed_at',
        'decision_reason',
        'escalation_reason',
        'escalation_description',
        'escalated_by_user_id',
        'escalated_at',
        'dispute_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
            'escalated_at' => 'datetime',
        ];
    }

    public function scopeForSeller(Builder $query, int $sellerProfileId): Builder
    {
        return $query->where('seller_profile_id', $sellerProfileId);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id');
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRefundRequestItem::class, 'seller_return_refund_request_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(RefundPayout::class, 'seller_return_refund_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(\App\Models\Buyer\ReturnRefundEvent::class, 'seller_return_refund_request_id');
    }
}
