<?php

namespace App\Models\Rider;

use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderLocalTrip extends Model
{
    public const TYPE_PICKUP = 'PICKUP';
    public const TYPE_DELIVERY = 'DELIVERY';
    public const LOADING = 'LOADING';
    public const READY = 'READY';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';
    public const EXCEPTION = 'EXCEPTION';

    protected $fillable = [
        'rider_profile_id', 'logistics_center_id', 'trip_type', 'province_code', 'municipality_code',
        'barangay_code', 'approved_parcel_capacity', 'status', 'created_by_user_id', 'started_at',
        'completed_at', 'cancelled_at', 'exception_notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_parcel_capacity' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function rider(): BelongsTo { return $this->belongsTo(RiderProfile::class, 'rider_profile_id'); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function items(): HasMany { return $this->hasMany(RiderLocalTripItem::class, 'rider_local_trip_id'); }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::COMPLETED, self::CANCELLED, self::EXCEPTION]);
    }
}
