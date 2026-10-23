<?php

namespace App\Models\Logistics;

use App\Models\Buyer\Address;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsCenter extends Model
{
    protected $table = 'logistics_centers';

    protected $hidden = ['payout_method', 'payout_account_type', 'payout_bank_name', 'payout_account_name', 'payout_account_number', 'payout_destinations'];

    /**
     * Legacy per-center fee retained for old records only. GLOBAL approved
     * shipping_rate_versions provide the base rate; named region tiers provide
     * cross-region pricing. Distance, parcel weight, dimensions, and service-
     * level pricing are outside the current policy.
     */

    protected $fillable = [
        'owner_user_id',
        'address_id',
        'code',
        'business_name',
        'business_registration_number',
        'dti_registration_number',
        'tin',
        'status',
        'approved_by_user_id',
        'approved_at',
        'payout_method',
        'payout_account_type',
        'payout_bank_name',
        'payout_account_name',
        'payout_account_number',
        'payout_destinations',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'shipping_fee' => 'decimal:2',
            'payout_account_name' => 'encrypted',
            'payout_account_number' => 'encrypted',
            'payout_destinations' => 'encrypted:array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function riders(): HasMany
    {
        return $this->hasMany(RiderProfile::class);
    }

    public function riderApplications(): HasMany
    {
        return $this->hasMany(RiderApplicationData::class, 'target_logistics_center_id');
    }

    public function serviceAreas(): HasMany
    {
        return $this->hasMany(ServiceArea::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function localTrips(): HasMany
    {
        return $this->hasMany(\App\Models\Rider\RiderLocalTrip::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'ACTIVE');
    }

    public function scopeApprovedActive(Builder $query): Builder
    {
        return $query
            ->active()
            ->whereNotNull('approved_by_user_id')
            ->whereNotNull('approved_at');
    }

    public function scopeEligibleForRiderAddress(
        Builder $query,
        string $provinceCode,
        string $municipalityCode,
    ): Builder {
        return $query
            ->approvedActive()
            ->whereHas('address', function (Builder $address) use ($provinceCode, $municipalityCode): void {
                $address
                    ->where('province_code', trim($provinceCode))
                    ->where('municipality_code', trim($municipalityCode));
            });
    }
}
