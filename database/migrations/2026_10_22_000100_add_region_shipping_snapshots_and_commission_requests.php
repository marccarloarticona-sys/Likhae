<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['addresses', 'order_addresses'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $missing = array_values(array_filter(
                ['region_code', 'region_name'],
                fn (string $column): bool => ! Schema::hasColumn($tableName, $column),
            ));
            if ($missing !== []) {
                Schema::table($tableName, function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->string($column, $column === 'region_code' ? 50 : 150)->nullable();
                    }
                });
            }
        }

        if (Schema::hasTable('seller_orders') && ! Schema::hasColumn('seller_orders', 'shipping_rate_snapshot')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->json('shipping_rate_snapshot')->nullable();
            });
        }

        if (Schema::hasTable('shipping_rate_change_requests')) {
            $missing = array_values(array_filter(
                ['current_rider_pool_percentage', 'requested_rider_pool_percentage'],
                fn (string $column): bool => ! Schema::hasColumn('shipping_rate_change_requests', $column),
            ));
            if ($missing !== []) {
                Schema::table('shipping_rate_change_requests', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->decimal($column, 7, 4)->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        $columnsByTable = [
            'addresses' => ['region_code', 'region_name'],
            'order_addresses' => ['region_code', 'region_name'],
            'seller_orders' => ['shipping_rate_snapshot'],
            'shipping_rate_change_requests' => ['current_rider_pool_percentage', 'requested_rider_pool_percentage'],
        ];

        foreach ($columnsByTable as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column)
                    && DB::table($tableName)->whereNotNull($column)->exists()) {
                    throw new \RuntimeException('Cannot remove populated shipping-region or commission-request snapshots. No schema was removed.');
                }
            }
        }

        foreach ($columnsByTable as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $existing = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($tableName, $column)));
            if ($existing !== []) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($existing));
            }
        }
    }
};
