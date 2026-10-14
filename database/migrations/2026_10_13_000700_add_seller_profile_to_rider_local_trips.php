<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_local_trips', function (Blueprint $table): void {
            $table->foreignId('seller_profile_id')
                ->nullable()
                ->after('logistics_center_id')
                ->constrained('seller_profiles')
                ->restrictOnDelete();
            $table->index(['seller_profile_id', 'trip_type', 'status'], 'rider_local_trip_seller_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rider_local_trips', function (Blueprint $table): void {
            $table->dropIndex('rider_local_trip_seller_status_idx');
            $table->dropConstrainedForeignId('seller_profile_id');
        });
    }
};
