<?php

namespace App\Models\Logistics;

use App\Models\Rider\RiderAreaAssignment;
use App\Models\Rider\RiderProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceArea extends Model
{
    protected $fillable = [
        'logistics_center_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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

    public function locations(): HasMany
    {
        return $this->hasMany(ServiceAreaLocation::class);
    }

    public function riderAssignments(): HasMany
    {
        return $this->hasMany(RiderAreaAssignment::class);
    }

    public function riders(): BelongsToMany
    {
        return $this->belongsToMany(
            RiderProfile::class,
            'rider_area_assignments',
            'service_area_id',
            'rider_profile_id',
        )->withPivot(['id', 'assigned_by_user_id', 'is_active', 'assigned_at', 'ended_at'])
            ->withTimestamps();
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForAddress(Builder $query, \App\Models\Buyer\Address $address): Builder
    {
        return $this->scopeForCodes(
            $query,
            $address->province_code,
            $address->municipality_code,
            $address->barangay_code,
        );
    }

    public function scopeForCodes(
        Builder $query,
        ?string $provinceCode,
        ?string $municipalityCode,
        ?string $barangayCode,
    ): Builder {
        return $query->whereHas('locations', function (Builder $location) use ($provinceCode, $municipalityCode, $barangayCode): void {
            $location->where('province_code', trim((string) $provinceCode))
                ->where('municipality_code', trim((string) $municipalityCode))
                ->where('barangay_code', trim((string) $barangayCode));
        });
    }
}
