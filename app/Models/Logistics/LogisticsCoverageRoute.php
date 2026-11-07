<?php

namespace App\Models\Logistics;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsCoverageRoute extends Model
{
    public const TYPE_SECONDARY = 'SECONDARY';
    public const TYPE_EXTENDED = 'EXTENDED';
    public const SHIPMENT_MARKETPLACE = 'MARKETPLACE';

    protected $fillable = [
        'origin_logistics_center_id',
        'destination_service_area_location_id',
        'coverage_type',
        'shipment_type',
        'is_active',
        'valid_from',
        'valid_until',
        'max_parcels_per_day',
        'estimated_sla_hours',
        'minimum_pickup_lead_hours',
        'dispatch_weekdays',
        'dispatch_cutoff_time',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'updated_by_user_id',
        'approval_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'max_parcels_per_day' => 'integer',
            'estimated_sla_hours' => 'integer',
            'minimum_pickup_lead_hours' => 'integer',
            'dispatch_weekdays' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function originLogisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class, 'origin_logistics_center_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceAreaLocation::class, 'destination_service_area_location_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeApprovedActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereNotNull('approved_by_user_id')
            ->whereNotNull('approved_at');
    }

    public function scopeCurrentlyValid(Builder $query): Builder
    {
        return $query
            ->where('valid_from', '<=', now())
            ->where('valid_until', '>=', now());
    }
}
