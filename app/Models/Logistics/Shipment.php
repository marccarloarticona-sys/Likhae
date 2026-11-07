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
use App\Models\Buyer\ReturnRefundRequestItem;
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
        'PICKUP_ASSIGNED',
        'PICKED_UP',
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'DELIVERY_FAILED',
        'RETURNED',
        'CANCELLED',
        'LOST',
        'DAMAGED',
    ];

    public const CENTER_RECEIVED_STATUSES = [
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'DELIVERY_FAILED',
    ];

    protected $fillable = [
        'seller_order_id',
        'order_item_id',
        'parcel_quantity',
        'tracking_number',
        'logistics_center_id',
        'origin_logistics_center_id',
        'destination_logistics_center_id',
        'smart_coverage_route_id',
        'smart_coverage_service_date',
        'service_area_id',
        'origin_province_code',
        'origin_province_name',
        'origin_municipality_code',
        'origin_municipality_name',
        'origin_barangay_code',
        'origin_barangay_name',
        'parcel_weight_kg',
        'parcel_volume_liters',
        'parcel_length_cm',
        'parcel_width_cm',
        'parcel_height_cm',
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
            'parcel_quantity' => 'integer',
            'parcel_weight_kg' => 'decimal:3',
            'parcel_volume_liters' => 'decimal:3',
            'parcel_length_cm' => 'decimal:2',
            'parcel_width_cm' => 'decimal:2',
            'parcel_height_cm' => 'decimal:2',
            'smart_coverage_service_date' => 'date',
        ];
    }

    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class);
    }

    public function hasReachedSortingCenter(): bool
    {
        if (in_array($this->current_status, self::CENTER_RECEIVED_STATUSES, true)) {
            return true;
        }

        // Terminal statuses can replace the live status after intake (for
        // example, a parcel can later be reported lost during delivery). Keep
        // the recorded custody event authoritative so a later status change
        // does not make the parcel appear to be awaiting its first intake.
        if (! in_array($this->current_status, ['CANCELLED', 'LOST', 'DAMAGED', 'RETURNED'], true)) {
            return false;
        }

        return $this->events()
            ->whereIn('status', self::CENTER_RECEIVED_STATUSES)
            ->exists();
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function getParcelQuantityAttribute(): int
    {
        $stored = $this->attributes['parcel_quantity'] ?? null;
        if (is_numeric($stored) && (int) $stored > 0) {
            return (int) $stored;
        }

        return max(0, (int) ($this->orderItem?->quantity ?? 0));
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

    public function smartCoverageRoute(): BelongsTo
    {
        return $this->belongsTo(LogisticsCoverageRoute::class, 'smart_coverage_route_id');
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

    public function returnWorkflow(): HasOne
    {
        $relation = $this->hasOne(ShipmentReturnWorkflow::class);
        if (\Illuminate\Support\Facades\Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')) {
            $relation->whereNull('seller_return_refund_request_id');
        }

        return $relation;
    }

    public function returnRefundRequestItems(): HasMany
    {
        return $this->hasMany(ReturnRefundRequestItem::class, 'shipment_id');
    }

    public function buyerReturnWorkflows(): HasMany
    {
        return $this->hasMany(ShipmentReturnWorkflow::class)->whereNotNull('seller_return_refund_request_id');
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
