<?php

namespace App\Models\Buyer;

use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRefundEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_refund_request_id',
        'seller_return_refund_request_id',
        'actor_user_id',
        'event_type',
        'from_status',
        'to_status',
        'details',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id');
    }

    public function sellerRequest(): BelongsTo
    {
        return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
