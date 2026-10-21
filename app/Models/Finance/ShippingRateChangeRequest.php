<?php

namespace App\Models\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShippingRateChangeRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    public const REASONS = [
        'FUEL_COST_INCREASE' => 'Fuel cost increase',
        'RIDER_COMPENSATION' => 'Rider compensation adjustment',
        'OPERATIONAL_COST' => 'Operational cost increase',
        'ROUTE_EXPANSION' => 'Route expansion',
        'HUB_EXPANSION' => 'Hub expansion',
        'INFLATION' => 'Inflation / cost adjustment',
        'SERVICE_COVERAGE' => 'Service coverage expansion',
        'OTHER' => 'Other',
    ];

    protected $fillable = [
        'requesting_logistics_user_id',
        'idempotency_key',
        'current_shipping_fee',
        'requested_shipping_fee',
        'change_type',
        'change_amount',
        'change_percentage',
        'current_rider_pool_percentage',
        'requested_rider_pool_percentage',
        'reason_code',
        'reason_details',
        'requested_effective_at',
        'status',
        'reviewed_by_admin_user_id',
        'reviewed_at',
        'admin_decision_reason',
        'approved_effective_at',
    ];

    protected function casts(): array
    {
        return [
            'current_shipping_fee' => 'decimal:2',
            'requested_shipping_fee' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'change_percentage' => 'decimal:4',
            'current_rider_pool_percentage' => 'decimal:4',
            'requested_rider_pool_percentage' => 'decimal:4',
            'requested_effective_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_effective_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requesting_logistics_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_user_id');
    }

    public function rateVersion(): HasOne
    {
        return $this->hasOne(ShippingRateVersion::class, 'source_request_id');
    }
}
