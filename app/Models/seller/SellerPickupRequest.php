<?php

namespace App\Models\Seller;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderLocalTrip;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class SellerPickupRequest extends Model
{
    protected $fillable = [
        'seller_profile_id',
        'pickup_address_id',
        'preferred_logistics_center_id',
        'seller_selected_fallback',
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

    public function preferredLogisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class, 'preferred_logistics_center_id');
    }

    public function riderLocalTrip(): BelongsTo
    {
        return $this->belongsTo(RiderLocalTrip::class, 'rider_local_trip_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(SellerPickupRequestShipment::class, 'seller_pickup_request_id');
    }

    public function centerOffers(): HasMany
    {
        return $this->hasMany(SellerPickupCenterOffer::class, 'seller_pickup_request_id')->orderBy('attempt_number');
    }

    public function hasAcceptedOriginCenter(int $centerId): bool
    {
        $offers = $this->centerOffers()
            ->where('logistics_center_id', $centerId)
            ->where('status', SellerPickupCenterOffer::STATUS_ACCEPTED);
        if (Schema::hasColumn('seller_pickup_center_offers', 'leg_type')) {
            $offers->where('leg_type', SellerPickupCenterOffer::LEG_PICKUP);
        }

        return $offers->exists();
    }

    protected function casts(): array
    {
        return ['seller_selected_fallback' => 'boolean'];
    }
}
