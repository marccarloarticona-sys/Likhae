<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['users', 'seller_profiles', 'logistics_centers'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'payout_destinations')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->longText('payout_destinations')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName)
                && Schema::hasColumn($tableName, 'payout_destinations')
                && DB::table($tableName)->whereNotNull('payout_destinations')->exists()) {
                throw new RuntimeException('Saved payout destinations exist in '.$tableName.'; export them before rolling back this migration.');
            }
        }

        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'payout_destinations')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('payout_destinations'));
            }
        }
    }
};
