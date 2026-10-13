<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('approved_parcel_capacity')->nullable()->after('vehicle_type');
            $table->decimal('approved_weight_capacity_kg', 10, 3)->nullable()->after('approved_parcel_capacity');
            $table->decimal('approved_volume_capacity_liters', 12, 3)->nullable()->after('approved_weight_capacity_kg');
            $table->index(['logistics_center_id', 'vehicle_type', 'status'], 'rider_profiles_center_vehicle_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->dropIndex('rider_profiles_center_vehicle_status_idx');
            $table->dropColumn([
                'approved_parcel_capacity',
                'approved_weight_capacity_kg',
                'approved_volume_capacity_liters',
            ]);
        });
    }
};
