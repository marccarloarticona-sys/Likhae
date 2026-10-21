<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROFILE_TABLES = ['seller_profiles', 'logistics_centers', 'rider_profiles'];

    public function up(): void
    {
        foreach (self::PROFILE_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'payout_method')) {
                    $table->string('payout_method', 40)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'payout_bank_name')) {
                    $table->string('payout_bank_name', 120)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'payout_account_name')) {
                    $table->text('payout_account_name')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'payout_account_number')) {
                    $table->text('payout_account_number')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::PROFILE_TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $columns = collect(['payout_method', 'payout_bank_name', 'payout_account_name', 'payout_account_number'])
                ->filter(fn (string $column): bool => Schema::hasColumn($tableName, $column))
                ->values()
                ->all();
            if ($columns === []) {
                continue;
            }

            foreach ($columns as $column) {
                if (DB::table($tableName)->whereNotNull($column)->exists()) {
                    throw new RuntimeException('Payout destination data exists in '.$tableName.'; export or clear it before rolling back this migration.');
                }
            }

            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
