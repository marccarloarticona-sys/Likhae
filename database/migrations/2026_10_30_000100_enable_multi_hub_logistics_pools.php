<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOCATION_UNIQUE = 'logistics_earning_pools_shipment_financial_allocation_id_unique';
    private const SHIPMENT_UNIQUE = 'logistics_earning_pools_shipment_id_unique';
    private const ROUTE_UNIQUE = 'logistics_pool_allocation_center_unique';

    public function up(): void
    {
        if (! Schema::hasTable('logistics_earning_pools')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('logistics_earning_pools'));
        if (! $indexes->contains(fn (array $index): bool => $index['name'] === self::ROUTE_UNIQUE)) {
            Schema::table('logistics_earning_pools', fn (Blueprint $table) => $table->unique(['shipment_financial_allocation_id', 'logistics_center_id'], self::ROUTE_UNIQUE));
        }
        if (! collect(Schema::getIndexes('logistics_earning_pools'))->contains(fn (array $index): bool => $index['name'] === 'logistics_pool_shipment_center_idx')) {
            Schema::table('logistics_earning_pools', fn (Blueprint $table) => $table->index(['shipment_id', 'logistics_center_id'], 'logistics_pool_shipment_center_idx'));
        }

        // MySQL requires each foreign key to retain a supporting index while
        // its original single-column unique index is removed. The new route
        // key supports the allocation foreign key; the shipment-center index
        // supports the shipment foreign key.
        $indexes = collect(Schema::getIndexes('logistics_earning_pools'));
        foreach ([
            [self::ALLOCATION_UNIQUE, ['shipment_financial_allocation_id']],
            [self::SHIPMENT_UNIQUE, ['shipment_id']],
        ] as [$name, $columns]) {
            if ($indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === $columns)) {
                Schema::table('logistics_earning_pools', fn (Blueprint $table) => $table->dropUnique($name));
                $indexes = collect(Schema::getIndexes('logistics_earning_pools'));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_earning_pools')) {
            return;
        }

        $hasMultipleRoutes = DB::table('logistics_earning_pools')
            ->select('shipment_financial_allocation_id')
            ->groupBy('shipment_financial_allocation_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $hasMultipleShipmentPools = DB::table('logistics_earning_pools')
            ->select('shipment_id')
            ->groupBy('shipment_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleRoutes || $hasMultipleShipmentPools) {
            throw new \RuntimeException('Cannot restore one-pool-per-shipment constraints while Inter-Hub earning pools exist. No financial rows were changed.');
        }

        $indexes = collect(Schema::getIndexes('logistics_earning_pools'));
        Schema::table('logistics_earning_pools', function (Blueprint $table) use ($indexes): void {
            if (! $indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shipment_financial_allocation_id'])) {
                $table->unique('shipment_financial_allocation_id');
            }
            if (! $indexes->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['shipment_id'])) {
                $table->unique('shipment_id');
            }
        });

        // Restore the old unique indexes before dropping the replacement
        // foreign-key support indexes.
        $indexes = collect(Schema::getIndexes('logistics_earning_pools'));
        if ($indexes->contains(fn (array $index): bool => $index['name'] === self::ROUTE_UNIQUE)) {
            Schema::table('logistics_earning_pools', fn (Blueprint $table) => $table->dropUnique(self::ROUTE_UNIQUE));
        }
        if ($indexes->contains(fn (array $index): bool => $index['name'] === 'logistics_pool_shipment_center_idx')) {
            Schema::table('logistics_earning_pools', fn (Blueprint $table) => $table->dropIndex('logistics_pool_shipment_center_idx'));
        }
    }
};
