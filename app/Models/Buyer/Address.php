<?php

namespace App\Models\Buyer;

use App\Models\Logistics\LogisticsApplicationData;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'contact_number',
        'province_code',
        'province_name',
        'region_code',
        'region_name',
        'municipality_code',
        'municipality_name',
        'barangay_code',
        'barangay_name',
        'postal_code',
        'house_number',
        'street_address',
        'landmark',
        'latitude',
        'longitude',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class, 'business_address_id');
    }

    public function logisticsCenter(): HasOne
    {
        return $this->hasOne(LogisticsCenter::class, 'address_id');
    }

    public function logisticsApplications(): HasMany
    {
        return $this->hasMany(LogisticsApplicationData::class, 'business_address_id');
    }

    public function formatted(): string
    {
        return collect([
            $this->house_number,
            $this->street_address,
            $this->barangay_name,
            $this->municipality_name,
            $this->province_name,
            $this->postal_code,
        ])->filter(fn ($value) => filled($value))->implode(', ');
    }
}
