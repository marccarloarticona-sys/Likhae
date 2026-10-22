<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['seller_profiles', 'logistics_centers', 'users'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'payout_account_type')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('payout_account_type', 24)->nullable()->after('payout_method');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'payout_account_type')) {
                continue;
            }

            if (DB::table($tableName)->whereNotNull('payout_account_type')->exists()) {
                throw new RuntimeException('Saved payout account type data exists in '.$tableName.'; no schema was removed.');
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('payout_account_type');
            });
        }
    }
};
