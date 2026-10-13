<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // Existing logistics_center_id remains the historical/current
            // custody pointer. These fields are separate route endpoints.
            $table->foreignId('origin_logistics_center_id')
                ->nullable()
                ->after('logistics_center_id')
                ->constrained('logistics_centers')
                ->nullOnDelete();
            $table->foreignId('destination_logistics_center_id')
                ->nullable()
                ->after('origin_logistics_center_id')
                ->constrained('logistics_centers')
                ->nullOnDelete();

            $table->string('origin_province_code', 50)->nullable()->after('destination_logistics_center_id');
            $table->string('origin_province_name', 150)->nullable()->after('origin_province_code');
            $table->string('origin_municipality_code', 50)->nullable()->after('origin_province_name');
            $table->string('origin_municipality_name', 150)->nullable()->after('origin_municipality_code');
            $table->string('origin_barangay_code', 50)->nullable()->after('origin_municipality_name');
            $table->string('origin_barangay_name', 150)->nullable()->after('origin_barangay_code');

            // Step 4 must fail safely when physical parcel data is unknown.
            // These nullable snapshots avoid inventing capacity assumptions.
            $table->decimal('parcel_weight_kg', 10, 3)->nullable()->after('origin_barangay_name');
            $table->decimal('parcel_volume_liters', 12, 3)->nullable()->after('parcel_weight_kg');

            $table->index(['origin_logistics_center_id', 'current_status'], 'shipments_origin_center_status_idx');
            $table->index(['destination_logistics_center_id', 'current_status'], 'shipments_destination_center_status_idx');
            $table->index(['origin_province_code', 'origin_municipality_code'], 'shipments_origin_geo_codes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_origin_geo_codes_idx');
            $table->dropIndex('shipments_destination_center_status_idx');
            $table->dropIndex('shipments_origin_center_status_idx');
            $table->dropConstrainedForeignId('destination_logistics_center_id');
            $table->dropConstrainedForeignId('origin_logistics_center_id');
            $table->dropColumn([
                'origin_province_code',
                'origin_province_name',
                'origin_municipality_code',
                'origin_municipality_name',
                'origin_barangay_code',
                'origin_barangay_name',
                'parcel_weight_kg',
                'parcel_volume_liters',
            ]);
        });
    }
};
