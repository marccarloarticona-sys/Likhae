<?php

namespace App\Models\Admin;

use App\Models\Buyer\Order;
use App\Models\Buyer\ReturnRefundRequest;
use App\Models\Logistics\Shipment;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dispute extends Model
{
    protected $fillable = [
        'dispute_number',
        'opened_by_user_id',
        'assigned_admin_user_id',
        'order_id',
        'seller_order_id',
        'shipment_id',
        'type',
        'subject',
        'description',
        'status',
        'opened_at',
        'resolved_at',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidence::class);
    }

    public function returnRefundRequest(): HasOne
    {
        return $this->hasOne(ReturnRefundRequest::class);
    }

    public function sellerReturnRefundRequest(): HasOne
    {
        return $this->hasOne(SellerReturnRefundRequest::class);
    }
}
