<?php

namespace App\Models\Seller;

use App\Models\Buyer\Address;
use App\Models\Rider\RiderLocalTrip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SellerPickupRequest extends Model
{
    protected $fillable = [
        'seller_profile_id',
        'pickup_address_id',
        'rider_local_trip_id',
        'status',
    ];

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function pickupAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'pickup_address_id');
    }

    public function riderLocalTrip(): BelongsTo
    {
        return $this->belongsTo(RiderLocalTrip::class, 'rider_local_trip_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SellerPickupRequestShipment::class, 'seller_pickup_request_id');
    }
}
