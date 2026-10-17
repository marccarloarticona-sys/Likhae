<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These composite unique keys let each parcel membership prove that
        // its SellerOrder and Shipment belong to the request's Seller.
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unique(['id', 'seller_profile_id'], 'seller_orders_id_seller_profile_uq');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->unique(['id', 'seller_order_id'], 'shipments_id_seller_order_uq');
        });

        Schema::table('rider_local_trips', function (Blueprint $table): void {
            $table->unique(['id', 'seller_profile_id'], 'rider_trips_id_seller_profile_uq');
        });

        Schema::create('seller_pickup_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->restrictOnDelete();
            $table->foreignId('pickup_address_id')->constrained('addresses')->restrictOnDelete();
            $table->unsignedBigInteger('rider_local_trip_id')->nullable();
            $table->string('status', 32)->default('REQUESTED');
            $table->timestamps();

            $table->unique(['id', 'seller_profile_id'], 'seller_pickup_requests_id_seller_uq');
            $table->index(['seller_profile_id', 'status'], 'seller_pickup_requests_seller_status_idx');
            $table->index(['rider_local_trip_id', 'seller_profile_id'], 'seller_pickup_requests_trip_seller_idx');

            $table->foreign(['rider_local_trip_id', 'seller_profile_id'], 'seller_pickup_requests_trip_seller_fk')
                ->references(['id', 'seller_profile_id'])
                ->on('rider_local_trips')
                ->restrictOnDelete();
        });

        Schema::create('seller_pickup_request_shipments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('seller_pickup_request_id');
            $table->unsignedBigInteger('seller_profile_id');
            $table->unsignedBigInteger('seller_order_id');
            $table->unsignedBigInteger('shipment_id');
            // Active rows use 1; completed, cancelled, or otherwise released
            // memberships set this to NULL. MySQL and SQLite allow multiple
            // NULLs in a unique index, while permitting only one active row
            // for each shipment.
            $table->boolean('active_slot')->nullable()->default(true);
            $table->timestamps();

            $table->unique(['seller_pickup_request_id', 'shipment_id'], 'seller_pickup_request_shipment_uq');
            $table->unique(['shipment_id', 'active_slot'], 'seller_pickup_active_shipment_uq');

            $table->index(['seller_pickup_request_id', 'seller_profile_id'], 'seller_pickup_membership_request_seller_idx');
            $table->index(['seller_order_id', 'seller_profile_id'], 'seller_pickup_membership_order_seller_idx');
            $table->index(['shipment_id', 'seller_order_id'], 'seller_pickup_membership_shipment_order_idx');

            $table->foreign(['seller_pickup_request_id', 'seller_profile_id'], 'seller_pickup_membership_request_seller_fk')
                ->references(['id', 'seller_profile_id'])
                ->on('seller_pickup_requests')
                ->restrictOnDelete();

            $table->foreign(['seller_order_id', 'seller_profile_id'], 'seller_pickup_membership_order_seller_fk')
                ->references(['id', 'seller_profile_id'])
                ->on('seller_orders')
                ->restrictOnDelete();

            $table->foreign(['shipment_id', 'seller_order_id'], 'seller_pickup_membership_shipment_order_fk')
                ->references(['id', 'seller_order_id'])
                ->on('shipments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_pickup_request_shipments');
        Schema::dropIfExists('seller_pickup_requests');

        Schema::table('rider_local_trips', function (Blueprint $table): void {
            $table->dropUnique('rider_trips_id_seller_profile_uq');
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropUnique('shipments_id_seller_order_uq');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropUnique('seller_orders_id_seller_profile_uq');
        });
    }
};
