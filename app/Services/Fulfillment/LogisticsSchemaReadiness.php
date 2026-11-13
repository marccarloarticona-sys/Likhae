<?php

namespace App\Services\Fulfillment;

use Illuminate\Support\Facades\Schema;
use Throwable;

final class LogisticsSchemaReadiness
{
    /** @var array<string, bool> */
    private array $tableExistence = [];

    /** @var array<string, list<string>> */
    private array $columnListings = [];

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

    public function smartCoverageReady(): bool
    {
        return $this->coverageTypeReady()
            && $this->hasColumns('logistics_coverage_routes', [
                'origin_logistics_center_id', 'destination_service_area_location_id', 'coverage_type', 'shipment_type',
                'is_active', 'valid_from', 'valid_until', 'max_parcels_per_day', 'estimated_sla_hours',
                'minimum_pickup_lead_hours', 'dispatch_weekdays', 'dispatch_cutoff_time', 'approved_by_user_id', 'approved_at',
            ])
            && $this->hasColumns('shipments', ['smart_coverage_route_id', 'smart_coverage_service_date'])
            && $this->hasColumn('seller_orders', 'delivery_routing_snapshot');
    }

    public function coverageTypeReady(): bool
    {
        return $this->hasColumn('service_area_locations', 'coverage_type');
    }

    public function smartFallbackEnabled(): bool
    {
        return (bool) config('likhae.smart_nearby_logistics_fallback', false)
            && $this->smartCoverageReady();
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

    public function riderReturnHistoryReady(): bool
    {
        return $this->hasColumns('shipment_return_workflows', [
            'shipment_id',
            'logistics_center_id',
            'original_delivery_assignment_id',
            'return_rider_profile_id',
            'status',
            'reason',
            'initiated_at',
            'center_handoff_at',
            'center_received_at',
            'return_rider_assigned_at',
            'return_rider_accepted_at',
            'return_rider_picked_up_at',
            'seller_handoff_at',
            'seller_received_at',
            'updated_at',
        ]);
    }

    public function sellerPickupCenterOffersReady(): bool
    {
        return $this->hasColumns('seller_pickup_center_offers', [
            'seller_pickup_request_id', 'logistics_center_id', 'attempt_number', 'status', 'offered_at',
            'expires_at', 'responded_at', 'destination_logistics_center_id',
            'destination_service_area_location_id', 'coverage_route_id', 'service_date',
            'reserved_parcel_count', 'route_snapshot',
        ]);
    }

    public function sellerPickupLegOffersReady(): bool
    {
        return $this->sellerPickupCenterOffersReady()
            && $this->hasColumn('seller_pickup_center_offers', 'leg_type');
    }

    public function sellerPickupCenterSelectionReady(): bool
    {
        return $this->sellerPickupRequestsReady()
            && $this->hasColumn('seller_pickup_requests', 'preferred_logistics_center_id');
    }

    public function sellerSelectedFallbackHubsReady(): bool
    {
        return $this->sellerPickupCenterSelectionReady()
            && $this->hasColumn('seller_pickup_requests', 'seller_selected_fallback')
            && $this->hasColumn('seller_pickup_request_shipments', 'preferred_destination_logistics_center_id');
    }

    public function automaticSellerPickupMatchingReady(): bool
    {
        return (bool) config('likhae.automatic_seller_pickup_matching', false)
            && $this->sellerPickupRequestsReady()
            && $this->sellerPickupCenterSelectionReady()
            && $this->sellerPickupCenterOffersReady()
            && $this->sellerPickupLegOffersReady()
            && $this->automaticRoutingReady()
            && $this->manifestReady()
            && $this->coverageTypeReady()
            && $this->hasColumns('rider_profiles', [
                'is_online', 'availability_latitude', 'availability_longitude',
                'availability_location_updated_at', 'approved_parcel_capacity',
            ]);
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

    public function buyerReturnWaybillsReady(): bool
    {
        return $this->hasColumns('shipment_return_workflows', [
            'seller_return_refund_request_id',
            'return_tracking_number',
            'buyer_dropoff_logistics_center_id',
        ])
            && $this->hasColumns('shipments', [
                'origin_logistics_center_id', 'destination_logistics_center_id',
            ])
            && $this->hasColumns('rider_assignments', ['assignment_type', 'status', 'completed_at'])
            && $this->hasTables([
                'seller_return_refund_requests',
                'return_refund_requests',
                'return_refund_events',
                'return_custody_events',
                'return_inspections',
            ]);
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistence)) {
            return $this->tableExistence[$table];
        }

        try {
            return $this->tableExistence[$table] = Schema::hasTable($table);
        } catch (Throwable) {
            return $this->tableExistence[$table] = false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (! array_key_exists($table, $this->columnListings)) {
            try {
                $this->columnListings[$table] = array_map(
                    static fn (string $name): string => strtolower($name),
                    Schema::getColumnListing($table),
                );
            } catch (Throwable) {
                $this->columnListings[$table] = [];
            }
        }

        return in_array(strtolower($column), $this->columnListings[$table], true);
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

    /** @param list<string> $tables */
    private function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
