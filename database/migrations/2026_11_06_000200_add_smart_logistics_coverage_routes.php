<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_area_locations')
            && ! Schema::hasColumn('service_area_locations', 'coverage_type')) {
            Schema::table('service_area_locations', function (Blueprint $table): void {
                // Existing rows remain primary exact PSGC coverage.
                $table->string('coverage_type', 16)->default('PRIMARY');
            });
        }

        if (Schema::hasTable('service_area_locations')
            && Schema::hasColumn('service_area_locations', 'coverage_type')
            && ! in_array('service_area_location_coverage_geo_idx', Schema::getIndexListing('service_area_locations'), true)) {
            Schema::table('service_area_locations', function (Blueprint $table): void {
                $table->index(['coverage_type', 'province_code', 'municipality_code', 'barangay_code'], 'service_area_location_coverage_geo_idx');
            });
        }

        if (! Schema::hasTable('logistics_coverage_routes')) {
            Schema::create('logistics_coverage_routes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('origin_logistics_center_id')->constrained('logistics_centers', 'id', 'smart_cov_origin_hub_fk')->restrictOnDelete();
                $table->foreignId('destination_service_area_location_id')->constrained('service_area_locations', 'id', 'smart_cov_destination_area_fk')->restrictOnDelete();
                $table->string('coverage_type', 16);
                $table->string('shipment_type', 24)->default('MARKETPLACE');
                $table->boolean('is_active')->default(false);
                $table->timestamp('valid_from');
                $table->timestamp('valid_until');
                $table->unsignedInteger('max_parcels_per_day');
                $table->unsignedSmallInteger('estimated_sla_hours');
                $table->unsignedSmallInteger('minimum_pickup_lead_hours');
                $table->json('dispatch_weekdays');
                $table->time('dispatch_cutoff_time');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('approval_notes')->nullable();
                $table->timestamps();

            });
        }

        if (Schema::hasTable('logistics_coverage_routes')) {
            $foreignKeys = Schema::getForeignKeys('logistics_coverage_routes');
            $foreignKeyColumns = array_map(static fn (array $key): array => $key['columns'], $foreignKeys);

            Schema::table('logistics_coverage_routes', function (Blueprint $table) use ($foreignKeyColumns): void {
                if (! in_array(['origin_logistics_center_id'], $foreignKeyColumns, true)) {
                    $table->foreign('origin_logistics_center_id', 'smart_cov_origin_hub_fk')
                        ->references('id')->on('logistics_centers')->restrictOnDelete();
                }

                if (! in_array(['destination_service_area_location_id'], $foreignKeyColumns, true)) {
                    $table->foreign('destination_service_area_location_id', 'smart_cov_destination_area_fk')
                        ->references('id')->on('service_area_locations')->restrictOnDelete();
                }

                if (! in_array(['created_by_user_id'], $foreignKeyColumns, true)) {
                    $table->foreign('created_by_user_id', 'smart_cov_created_by_user_fk')
                        ->references('id')->on('users')->nullOnDelete();
                }

                if (! in_array(['approved_by_user_id'], $foreignKeyColumns, true)) {
                    $table->foreign('approved_by_user_id', 'smart_cov_approved_by_user_fk')
                        ->references('id')->on('users')->nullOnDelete();
                }

                if (! in_array(['updated_by_user_id'], $foreignKeyColumns, true)) {
                    $table->foreign('updated_by_user_id', 'smart_cov_updated_by_user_fk')
                        ->references('id')->on('users')->nullOnDelete();
                }
            });

            $indexes = Schema::getIndexListing('logistics_coverage_routes');
            Schema::table('logistics_coverage_routes', function (Blueprint $table) use ($indexes): void {
                if (! in_array('logistics_coverage_route_origin_location_idx', $indexes, true)) {
                    $table->index(
                        ['origin_logistics_center_id', 'destination_service_area_location_id'],
                        'logistics_coverage_route_origin_location_idx',
                    );
                }
                if (! in_array('logistics_coverage_route_window_idx', $indexes, true)) {
                    $table->index(
                        ['origin_logistics_center_id', 'destination_service_area_location_id', 'valid_from', 'valid_until'],
                        'logistics_coverage_route_window_idx',
                    );
                }
                if (! in_array('logistics_coverage_route_validity_idx', $indexes, true)) {
                    $table->index(['is_active', 'valid_from', 'valid_until'], 'logistics_coverage_route_validity_idx');
                }
                if (! in_array('logistics_coverage_route_lookup_idx', $indexes, true)) {
                    $table->index(
                        ['origin_logistics_center_id', 'is_active', 'coverage_type', 'valid_from', 'valid_until'],
                        'logistics_coverage_route_lookup_idx',
                    );
                }
                if (! in_array('logistics_coverage_route_destination_idx', $indexes, true)) {
                    $table->index(['destination_service_area_location_id', 'is_active'], 'logistics_coverage_route_destination_idx');
                }
            });
        }

        if (Schema::hasTable('shipments') && ! Schema::hasColumn('shipments', 'smart_coverage_route_id')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->foreignId('smart_coverage_route_id')
                    ->nullable()
                    ->constrained('logistics_coverage_routes')
                    ->restrictOnDelete();
                $table->date('smart_coverage_service_date')->nullable();
                $table->index(['smart_coverage_route_id', 'smart_coverage_service_date'], 'shipments_smart_coverage_capacity_idx');
            });
        }

        if (Schema::hasTable('seller_orders') && ! Schema::hasColumn('seller_orders', 'delivery_routing_snapshot')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->json('delivery_routing_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Rollback is intentionally forward-only: historical route approvals,
        // shipment assignments, and order snapshots must remain auditable.
        throw new RuntimeException('Smart logistics routing is forward-only. Disable the feature flag to roll back behavior; preserve route and order history.');
    }
};
