<?php

namespace App\Models\Buyer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\Logistics\Shipment;

class ReturnRefundRequestItem extends Model
{
    protected $fillable = [
        'return_refund_request_id',
        'order_item_id',
        'shipment_id',
        'quantity',
        'refundable_amount',
        'seller_return_refund_request_id',
    ];

    protected function casts(): array
    {
        return ['refundable_amount' => 'decimal:2'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ReturnRefundRequest::class, 'return_refund_request_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function sellerRequest(): BelongsTo
    {
        return $this->belongsTo(SellerReturnRefundRequest::class, 'seller_return_refund_request_id');
    }
}
