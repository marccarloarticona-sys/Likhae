<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcel_scans', function (Blueprint $table): void {
            $table->foreignId('transfer_leg_id')
                ->nullable()
                ->after('rider_assignment_id')
                ->constrained('shipment_transfer_legs')
                ->restrictOnDelete();
            $table->index(['transfer_leg_id', 'scanned_at'], 'parcel_scans_transfer_leg_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('parcel_scans', function (Blueprint $table): void {
            $table->dropIndex('parcel_scans_transfer_leg_time_idx');
            $table->dropConstrainedForeignId('transfer_leg_id');
        });
    }
};
