<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rider_profiles', 'availability_latitude')) {
            Schema::table('rider_profiles', function (Blueprint $table): void {
                $table->decimal('availability_latitude', 10, 7)->nullable()->after('is_online');
            });
        }

        if (! Schema::hasColumn('rider_profiles', 'availability_longitude')) {
            Schema::table('rider_profiles', function (Blueprint $table): void {
                $table->decimal('availability_longitude', 10, 7)->nullable()->after('availability_latitude');
            });
        }

        if (! Schema::hasColumn('rider_profiles', 'availability_location_updated_at')) {
            Schema::table('rider_profiles', function (Blueprint $table): void {
                $table->timestamp('availability_location_updated_at')->nullable()->after('availability_longitude');
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['availability_latitude', 'availability_longitude', 'availability_location_updated_at'],
            fn (string $column): bool => Schema::hasColumn('rider_profiles', $column),
        ));

        if ($columns !== []) {
            Schema::table('rider_profiles', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
