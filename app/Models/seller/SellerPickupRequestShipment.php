<?php

namespace App\Models\Seller;

use App\Models\Logistics\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerPickupRequestShipment extends Model
{
    protected $table = 'seller_pickup_request_shipments';

    protected $fillable = [
        'seller_pickup_request_id',
        'seller_profile_id',
        'seller_order_id',
        'shipment_id',
        'active_slot',
    ];

    protected function casts(): array
    {
        return ['active_slot' => 'boolean'];
    }

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(SellerPickupRequest::class, 'seller_pickup_request_id');
    }

    public function sellerProfile(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class);
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
