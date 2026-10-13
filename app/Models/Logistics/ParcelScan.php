<?php

namespace App\Models\Logistics;

use App\Models\Rider\RiderAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParcelScan extends Model
{
    protected $fillable = [
        'shipment_id',
        'scanned_by_user_id',
        'logistics_center_id',
        'rider_assignment_id',
        'transfer_leg_id',
        'scan_type',
        'scan_method',
        'scanned_code',
        'result',
        'notes',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }

    public function logisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    public function riderAssignment(): BelongsTo
    {
        return $this->belongsTo(RiderAssignment::class);
    }

    public function transferLeg(): BelongsTo
    {
        return $this->belongsTo(ShipmentTransferLeg::class, 'transfer_leg_id');
    }
}
