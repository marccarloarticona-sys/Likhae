<?php

namespace App\Models\Seller;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\LogisticsCoverageRoute;
use App\Models\Logistics\ServiceAreaLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPickupCenterOffer extends Model
{
    public const LEG_PICKUP = 'PICKUP';
    public const LEG_DELIVERY = 'DELIVERY';

    public const STATUS_OFFERED = 'OFFERED';
    public const STATUS_ACCEPTED = 'ACCEPTED';
    public const STATUS_DECLINED = 'DECLINED';
    public const STATUS_EXPIRED = 'EXPIRED';
    public const STATUS_NO_CAPACITY = 'NO_CAPACITY';
    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'seller_pickup_request_id',
        'logistics_center_id',
        'leg_type',
        'attempt_number',
        'status',
        'offered_at',
        'expires_at',
        'responded_at',
        'estimated_distance_meters',
        'estimated_travel_seconds',
        'estimate_method',
        'destination_logistics_center_id',
        'destination_service_area_location_id',
        'coverage_route_id',
        'service_date',
        'reserved_parcel_count',
        'route_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'service_date' => 'date',
            'route_snapshot' => 'array',
            'estimated_distance_meters' => 'integer',
            'estimated_travel_seconds' => 'integer',
            'reserved_parcel_count' => 'integer',
        ];
    }

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(SellerPickupRequest::class, 'seller_pickup_request_id');
    }

    public function logisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    public function destinationCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class, 'destination_logistics_center_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(ServiceAreaLocation::class, 'destination_service_area_location_id');
    }

    public function coverageRoute(): BelongsTo
    {
        return $this->belongsTo(LogisticsCoverageRoute::class, 'coverage_route_id');
    }
}
