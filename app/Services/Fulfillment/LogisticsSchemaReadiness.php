<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class LogisticsSchemaReadiness
{
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
                'approved_volume_capacity_liters',
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
