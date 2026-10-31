<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class LogisticsSchemaReadiness
{
    public function riderAvailabilityReady(): bool
    {
        return $this->hasColumns('rider_profiles', [
            'is_online',
            'availability_latitude',
            'availability_longitude',
            'availability_location_updated_at',
        ]);
    }

    public function automaticRoutingReady(): bool
    {
        return $this->hasColumns('shipments', [
            'origin_logistics_center_id',
            'destination_logistics_center_id',
            'origin_province_code',
            'origin_municipality_code',
            'origin_barangay_code',
        ])
            && $this->hasTable('shipment_transfer_legs')
            && $this->hasColumn('parcel_scans', 'transfer_leg_id');
    }

    public function transferReady(): bool
    {
        return $this->automaticRoutingReady()
            && $this->hasTable('transfer_manifests')
            && $this->hasTable('transfer_manifest_items');
    }

    public function manifestReady(): bool
    {
        return $this->transferReady()
            && $this->hasColumns('rider_profiles', [
                'approved_parcel_capacity',
                'approved_weight_capacity_kg',
            ]);
    }

    public function localTripsReady(): bool
    {
        return $this->manifestReady()
            && $this->hasColumns('rider_local_trips', [
                'rider_profile_id', 'logistics_center_id', 'seller_profile_id', 'trip_type', 'province_code',
                'municipality_code', 'barangay_code', 'approved_parcel_capacity', 'status',
            ])
            && $this->hasColumns('rider_local_trip_items', [
                'rider_local_trip_id', 'rider_assignment_id', 'status',
            ]);
    }

    public function sellerPickupBatchesReady(): bool
    {
        return $this->hasColumns('rider_profiles', ['approved_parcel_capacity'])
            && $this->hasColumns('rider_local_trips', [
                'rider_profile_id', 'logistics_center_id', 'seller_profile_id', 'trip_type', 'province_code',
                'municipality_code', 'barangay_code', 'approved_parcel_capacity', 'status',
            ])
            && $this->hasColumns('rider_local_trip_items', [
                'rider_local_trip_id', 'rider_assignment_id', 'status',
            ]);
    }

    public function sellerPickupRequestsReady(): bool
    {
        return $this->sellerPickupBatchesReady()
            && $this->hasColumns('seller_pickup_requests', [
                'seller_profile_id', 'pickup_address_id', 'rider_local_trip_id', 'status',
            ])
            && $this->hasColumns('seller_pickup_request_shipments', [
                'seller_pickup_request_id', 'seller_profile_id', 'seller_order_id', 'shipment_id', 'active_slot',
            ]);
    }

    public function shipmentReturnsReady(): bool
    {
        return $this->hasTable('shipment_return_workflows');
    }

    public function shipmentReturnEnhancementsReady(): bool
    {
        return $this->hasColumns('shipment_return_workflows', [
            'return_disposition',
            'pending_return_rider_profile_id',
            'handoff_authorized_by_user_id',
            'handoff_authorized_at',
            'handoff_reason',
            'handoff_from_confirmed_at',
            'handoff_to_confirmed_at',
            'handoff_completed_at',
            'return_exception_reason',
            'return_exception_recorded_by_user_id',
            'return_exception_recorded_at',
        ]);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (Throwable) {
            return false;
        }
    }

    /** @param list<string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! $this->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
}
