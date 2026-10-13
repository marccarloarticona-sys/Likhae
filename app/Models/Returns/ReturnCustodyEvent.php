<?php

namespace App\Models\Returns;

use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnCustodyEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_refund_request_id', 'seller_return_refund_request_id', 'event_type', 'from_party', 'to_party',
        'shipment_id', 'rider_assignment_id', 'transfer_reference', 'location_label', 'actor_user_id',
        'occurred_at', 'notes', 'metadata',
    ];

    protected function casts(): array { return ['occurred_at' => 'datetime', 'metadata' => 'array']; }
    public function request(): BelongsTo { return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id'); }
    public function sellerRequest(): BelongsTo { return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
