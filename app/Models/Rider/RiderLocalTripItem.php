<?php

namespace App\Models\Rider;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiderLocalTripItem extends Model
{
    public const QUEUED = 'QUEUED';
    public const LOADED = 'LOADED';
    public const COMPLETED = 'COMPLETED';
    public const FAILED = 'FAILED';
    public const CANCELLED = 'CANCELLED';

    protected $fillable = [
        'rider_local_trip_id', 'rider_assignment_id', 'added_by_user_id', 'loaded_at', 'verified_at',
        'completed_at', 'status', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'loaded_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo { return $this->belongsTo(RiderLocalTrip::class, 'rider_local_trip_id'); }
    public function riderAssignment(): BelongsTo { return $this->belongsTo(RiderAssignment::class); }
    public function addedBy(): BelongsTo { return $this->belongsTo(User::class, 'added_by_user_id'); }
}
