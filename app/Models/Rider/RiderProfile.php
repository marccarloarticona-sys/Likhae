<?php

namespace App\Models\Rider;

use App\Models\Admin\AuditLog;
use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiderProfile extends Model
{
    protected $table = 'rider_profiles';

    protected $fillable = [
        'user_id',
        'logistics_center_id',
        'vehicle_type',
        'plate_number',
        'drivers_license_number',
        'status',
        'approved_by_user_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    /** Transitional alias. */
    public function provider(): BelongsTo
    {
        return $this->logisticsCenter();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function areaAssignments(): HasMany
    {
        return $this->hasMany(RiderAreaAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RiderAssignment::class);
    }

    public function earnings(): HasMany
    {
        return $this->hasMany(RiderEarning::class);
    }

    public function moderationLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'auditable_id')
            ->where('auditable_type', self::class)
            ->latest('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['ACTIVE', 'WARNED']);
    }
}
