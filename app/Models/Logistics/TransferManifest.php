<?php

namespace App\Models\Logistics;

use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferManifest extends Model
{
    public const DRAFT = 'DRAFT';
    public const SEALED = 'SEALED';
    public const RELEASED = 'RELEASED';
    public const IN_TRANSIT = 'IN_TRANSIT';
    public const ARRIVED = 'ARRIVED';
    public const RECEIVED = 'RECEIVED';
    public const PARTIAL = 'PARTIAL';
    public const EXCEPTION = 'EXCEPTION';
    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'manifest_number', 'origin_logistics_center_id', 'destination_logistics_center_id', 'status',
        'created_by_user_id', 'assigned_driver_rider_profile_id', 'driver_accepted_by_user_id', 'driver_accepted_at', 'sealed_by_user_id', 'sealed_at',
        'released_by_user_id', 'released_at', 'departed_at', 'arrived_at', 'received_by_user_id',
        'received_at', 'cancelled_at', 'notes', 'exception_notes', 'transfer_commission_type',
        'transfer_commission_value', 'transfer_commission_base', 'transfer_commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'sealed_at' => 'datetime', 'released_at' => 'datetime', 'departed_at' => 'datetime',
            'arrived_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime', 'driver_accepted_at' => 'datetime',
            'transfer_commission_value' => 'decimal:4', 'transfer_commission_base' => 'decimal:2',
            'transfer_commission_amount' => 'decimal:2',
        ];
    }

    public function originLogisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class, 'origin_logistics_center_id'); }
    public function destinationLogisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class, 'destination_logistics_center_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function assignedDriver(): BelongsTo { return $this->belongsTo(RiderProfile::class, 'assigned_driver_rider_profile_id'); }
    public function driverAcceptedBy(): BelongsTo { return $this->belongsTo(User::class, 'driver_accepted_by_user_id'); }
    public function items(): HasMany { return $this->hasMany(TransferManifestItem::class, 'manifest_id'); }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::RECEIVED, self::CANCELLED]);
    }
}
