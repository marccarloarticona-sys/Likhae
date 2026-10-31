<?php

namespace App\Models\Finance;

use App\Models\Buyer\Order;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CodCollection extends Model
{
    public const PENDING = 'PENDING';
    public const COLLECTED = 'COLLECTED';
    public const FAILED = 'FAILED';
    public const REMITTED = 'REMITTED';
    public const REMITTED_RIDER_TOPUP = 'REMITTED_RIDER_TOPUP';
    public const REMITTED_DISCREPANCY = 'REMITTED_DISCREPANCY';
    public const DISCREPANCY = 'DISCREPANCY';
    public const VOID = 'VOID';

    protected $fillable = [
        'shipment_id', 'seller_order_id', 'order_id', 'rider_profile_id', 'logistics_center_id',
        'expected_amount', 'collected_amount', 'status', 'collection_reference', 'collected_at',
        'confirmed_by_user_id', 'recorded_by_user_id', 'notes', 'evidence_path',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'collected_amount' => 'decimal:2',
            'collected_at' => 'datetime',
        ];
    }

    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function sellerOrder(): BelongsTo { return $this->belongsTo(SellerOrder::class); }
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function riderProfile(): BelongsTo { return $this->belongsTo(RiderProfile::class); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function confirmedBy(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by_user_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
    public function remittanceItem(): HasOne { return $this->hasOne(RiderRemittanceItem::class, 'cod_collection_id', 'id'); }
}
