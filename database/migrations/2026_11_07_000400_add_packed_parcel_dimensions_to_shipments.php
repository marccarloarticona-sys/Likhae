<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'parcel_length_cm')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->decimal('parcel_length_cm', 10, 2)->nullable()->after('parcel_weight_kg');
            });
        }

        if (! Schema::hasColumn('shipments', 'parcel_width_cm')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->decimal('parcel_width_cm', 10, 2)->nullable()->after('parcel_length_cm');
            });
        }

        if (! Schema::hasColumn('shipments', 'parcel_height_cm')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->decimal('parcel_height_cm', 10, 2)->nullable()->after('parcel_width_cm');
            });
        }
    }

    public function down(): void
    {
        // Keep recorded parcel dimensions when rolling application code back.
    }
};
