<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_pickup_center_offers')) {
            return;
        }

        Schema::create('seller_pickup_center_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_pickup_request_id')->constrained('seller_pickup_requests', 'id', 'spco_request_fk')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers', 'id', 'spco_origin_center_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 24)->default('OFFERED');
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->unsignedInteger('estimated_distance_meters')->nullable();
            $table->unsignedInteger('estimated_travel_seconds')->nullable();
            $table->string('estimate_method', 32)->nullable();
            $table->foreignId('destination_logistics_center_id')->nullable()->constrained('logistics_centers', 'id', 'spco_destination_center_fk')->restrictOnDelete();
            $table->foreignId('destination_service_area_location_id')->nullable()->constrained('service_area_locations', 'id', 'spco_destination_area_fk')->restrictOnDelete();
            $table->foreignId('coverage_route_id')->nullable()->constrained('logistics_coverage_routes', 'id', 'spco_coverage_route_fk')->restrictOnDelete();
            $table->date('service_date')->nullable();
            $table->unsignedSmallInteger('reserved_parcel_count')->default(0);
            $table->json('route_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['seller_pickup_request_id', 'attempt_number'], 'spco_request_attempt_uq');
            $table->index(['logistics_center_id', 'status', 'expires_at'], 'spco_center_status_exp_idx');
            $table->index(['seller_pickup_request_id', 'status'], 'spco_request_status_idx');
            $table->index(['destination_logistics_center_id', 'status', 'service_date'], 'spco_dest_status_date_idx');
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('Seller pickup offer history is forward-only. Disable automatic matching to roll back behavior; preserve offer records.');
    }
};
