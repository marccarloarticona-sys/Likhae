<?php

namespace App\Models\Buyer;

use App\Models\Admin\Dispute;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRefundRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_number',
        'dispute_id',
        'order_id',
        'buyer_user_id',
        'issue_category',
        'issue_reason',
        'solution',
        'description',
        'refund_method',
        'refundable_amount',
        'requested_amount',
        'buyer_email',
        'status',
        'submitted_at',
        'gcash_recipient_name',
        'gcash_mobile',
        'gcash_mobile_last4',
        'gcash_details_confirmed_at',
        'gcash_details_confirmed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'refundable_amount' => 'decimal:2',
            'requested_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'gcash_recipient_name' => 'encrypted',
            'gcash_mobile' => 'encrypted',
            'gcash_details_confirmed_at' => 'datetime',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRefundRequestItem::class);
    }

    public function sellerRequests(): HasMany
    {
        return $this->hasMany(SellerReturnRefundRequest::class, 'return_refund_request_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ReturnRefundEvidence::class, 'return_refund_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReturnRefundEvent::class, 'return_refund_request_id');
    }

    public function gcashMobileMasked(): string
    {
        $mobile = (string) $this->gcash_mobile;
        return $mobile === '' ? 'Not provided' : str_repeat('*', max(0, strlen($mobile) - 4)).substr($mobile, -4);
    }
}
