<?php

namespace App\Models\Logistics;

use App\Models\Admin\Dispute;
use App\Models\Communication\Conversation;
use App\Models\Finance\CodCollection;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Rider\RiderAssignment;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\SellerPickupRequestShipment;
use App\Models\Buyer\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shipment extends Model
{
    public const STATUSES = [
        'PLACED',
        'CONFIRMED',
        'PREPARING',
        'READY_FOR_PICKUP',
        'PICKED_UP',
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'DELIVERY_FAILED',
        'RETURNED',
    ];

    protected $fillable = [
        'seller_order_id',
        'order_item_id',
        'tracking_number',
        'logistics_center_id',
        'origin_logistics_center_id',
        'destination_logistics_center_id',
        'service_area_id',
        'origin_province_code',
        'origin_province_name',
        'origin_municipality_code',
        'origin_municipality_name',
        'origin_barangay_code',
        'origin_barangay_name',
        'parcel_weight_kg',
        'parcel_volume_liters',
        'destination_province_code',
        'destination_province_name',
        'destination_municipality_code',
        'destination_municipality_name',
        'destination_barangay_code',
        'destination_barangay_name',
        'current_status',
    ];

    protected function casts(): array
    {
        return [
            'parcel_weight_kg' => 'decimal:3',
            'parcel_volume_liters' => 'decimal:3',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function logisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class);
    }

    public function originLogisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class, 'origin_logistics_center_id');
    }

    public function destinationLogisticsCenter(): BelongsTo
    {
        return $this->belongsTo(LogisticsCenter::class, 'destination_logistics_center_id');
    }

    /** Transitional alias. */
    public function provider(): BelongsTo
    {
        return $this->logisticsCenter();
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function pickupRequests(): HasMany
    {
        return $this->hasMany(PickupRequest::class);
    }

    public function sellerPickupMemberships(): HasMany
    {
        return $this->hasMany(SellerPickupRequestShipment::class, 'shipment_id');
    }

    public function riderAssignments(): HasMany
    {
        return $this->hasMany(RiderAssignment::class);
    }

    public function latestRiderAssignment(): HasOne
    {
        return $this->hasOne(RiderAssignment::class)->latestOfMany();
    }

    public function waybills(): HasMany
    {
        return $this->hasMany(Waybill::class);
    }

    public function scans(): HasMany
    {
        return $this->hasMany(ParcelScan::class);
    }

    public function transferLegs(): HasMany
    {
        return $this->hasMany(ShipmentTransferLeg::class)->orderBy('sequence_no');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ShipmentEvent::class)->latestOfMany();
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    public function codCollection(): HasOne
    {
        return $this->hasOne(CodCollection::class);
    }

    public function financialAllocation(): HasOne
    {
        return $this->hasOne(ShipmentFinancialAllocation::class);
    }

    public function logisticsEarningPool(): HasOne
    {
        return $this->hasOne(LogisticsEarningPool::class);
    }

    public function riderEarningPool(): HasOne
    {
        return $this->hasOne(RiderEarningPool::class);
    }

    public function financialLedgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedgerEntry::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('current_status', strtoupper($status));
    }
}
