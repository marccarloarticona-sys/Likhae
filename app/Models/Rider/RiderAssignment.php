<?php

namespace App\Models\Rider;

use App\Models\Logistics\DeliveryAttempt;
use App\Models\Logistics\ParcelScan;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RiderAssignment extends Model
{
    public const TYPE_PICKUP = 'PICKUP';
    public const TYPE_DELIVERY = 'DELIVERY';

    protected $fillable = [
        'shipment_id',
        'rider_profile_id',
        'assignment_type',
        'status',
        'assigned_by_user_id',
        'assigned_at',
        'accepted_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (RiderAssignment $assignment): void {
            if ($assignment->wasChanged('status') && $assignment->status !== 'IN_PROGRESS') {
                // A completed pickup rider continues to the sorting center
                // until the logistics receive scan is recorded.
                if ($assignment->assignment_type === self::TYPE_PICKUP
                    && $assignment->status === 'COMPLETED'
                    && $assignment->shipment?->current_status === 'PICKED_UP') {
                    return;
                }

                $assignment->liveLocation()->delete();
            }
        });
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function riderProfile(): BelongsTo
    {
        return $this->belongsTo(RiderProfile::class);
    }

    /** Transitional alias. */
    public function rider(): BelongsTo
    {
        return $this->riderProfile();
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ParcelScan::class);
    }

    public function shipmentEvents(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class);
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function localTripItems(): HasMany
    {
        return $this->hasMany(RiderLocalTripItem::class);
    }

    public function earning(): HasOne
    {
        return $this->hasOne(RiderEarning::class);
    }

    public function liveLocation(): HasOne
    {
        return $this->hasOne(RiderAssignmentLocation::class);
    }

    public function scopePickup(Builder $query): Builder
    {
        return $query->where('assignment_type', self::TYPE_PICKUP);
    }

    public function scopeDelivery(Builder $query): Builder
    {
        return $query->where('assignment_type', self::TYPE_DELIVERY);
    }
}
