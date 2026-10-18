<?php

namespace App\Models\Finance;

use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ShippingRateVersion extends Model
{
    public const SCOPE_GLOBAL = 'GLOBAL';
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'scope',
        'shipping_fee',
        'rider_pool_percentage',
        'logistics_percentage',
        'pickup_rider_weight',
        'hub_to_hub_rider_weight',
        'last_mile_rider_weight',
        'effective_from',
        'effective_until',
        'status',
        'approved_by_admin_user_id',
        'source_request_id',
    ];

    protected function casts(): array
    {
        return [
            'shipping_fee' => 'decimal:2',
            'rider_pool_percentage' => 'decimal:4',
            'logistics_percentage' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_GLOBAL);
    }

    public function scopeEffectiveAt(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query->global()
            ->where('effective_from', '<=', $at)
            ->where(fn (Builder $versions) => $versions
                ->whereNull('effective_until')
                ->orWhere('effective_until', '>', $at));
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_admin_user_id');
    }

    public function sourceRequest(): BelongsTo
    {
        return $this->belongsTo(ShippingRateChangeRequest::class, 'source_request_id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(SellerOrder::class, 'shipping_rate_version_id');
    }
}
