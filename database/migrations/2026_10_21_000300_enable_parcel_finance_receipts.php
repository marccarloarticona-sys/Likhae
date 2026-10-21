<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceSellerOrderUniqueWithIndex(
            'shipment_financial_allocations',
            'shipment_financial_allocations_seller_order_id_unique',
            'shipment_fin_alloc_seller_order_idx',
        );
        $this->replaceSellerOrderUniqueWithIndex(
            'seller_settlements',
            'seller_settlements_seller_order_id_unique',
            'seller_settlements_seller_order_idx',
        );

        if (Schema::hasTable('seller_settlements')) {
            Schema::table('seller_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('seller_settlements', 'payout_method')) {
                    $table->string('payout_method', 80)->nullable();
                }
                if (! Schema::hasColumn('seller_settlements', 'payout_external_reference')) {
                    $table->string('payout_external_reference', 150)->nullable();
                }
                if (! Schema::hasColumn('seller_settlements', 'payout_failure_reason')) {
                    $table->text('payout_failure_reason')->nullable();
                }
                if (! Schema::hasColumn('seller_settlements', 'payout_failed_at')) {
                    $table->timestamp('payout_failed_at')->nullable();
                }
                if (! Schema::hasColumn('seller_settlements', 'payout_failed_by_user_id')) {
                    $table->foreignId('payout_failed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('seller_settlements', 'seller_acknowledged_at')) {
                    $table->timestamp('seller_acknowledged_at')->nullable();
                }
                if (! Schema::hasColumn('seller_settlements', 'seller_acknowledged_by_user_id')) {
                    $table->foreignId('seller_acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    private function replaceSellerOrderUniqueWithIndex(string $tableName, string $uniqueName, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($tableName));
        $hasLookup = $indexes->contains(fn (array $index): bool => ! $index['unique'] && $index['columns'] === ['seller_order_id']);
        if (! $hasLookup) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->index('seller_order_id', $indexName);
            });
        }

        $unique = $indexes->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['seller_order_id']);
        if ($unique) {
            Schema::table($tableName, function (Blueprint $table) use ($unique): void {
                $table->dropUnique($unique['name']);
            });
        }
    }

    public function down(): void
    {
        foreach (['shipment_financial_allocations', 'seller_settlements'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            $duplicates = DB::table($tableName)
                ->select('seller_order_id')
                ->groupBy('seller_order_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicates) {
                throw new RuntimeException('Cannot restore the seller-order unique constraint while parcel-level settlement rows exist. No schema was removed.');
            }
        }

        $columnsByTable = [
            'seller_settlements' => [
                'payout_method', 'payout_external_reference', 'payout_failure_reason', 'payout_failed_at',
                'payout_failed_by_user_id', 'seller_acknowledged_at', 'seller_acknowledged_by_user_id',
            ],
        ];
        foreach ($columnsByTable as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($tableName, $column) && DB::table($tableName)->whereNotNull($column)->exists()) {
                    throw new RuntimeException('Cannot remove populated parcel finance receipt data. No schema was removed.');
                }
            }
        }

        if (Schema::hasTable('seller_settlements')) {
            Schema::table('seller_settlements', function (Blueprint $table): void {
                foreach (['payout_failed_by_user_id', 'seller_acknowledged_by_user_id'] as $column) {
                    if (Schema::hasColumn('seller_settlements', $column)) {
                        $table->dropForeign([$column]);
                    }
                }
                $columns = [
                    'payout_method', 'payout_external_reference', 'payout_failure_reason', 'payout_failed_at',
                    'payout_failed_by_user_id', 'seller_acknowledged_at', 'seller_acknowledged_by_user_id',
                ];
                $table->dropColumn(array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('seller_settlements', $column))));
            });
        }

        $this->restoreSellerOrderUnique('shipment_financial_allocations', 'shipment_fin_alloc_seller_order_idx', 'shipment_financial_allocations_seller_order_id_unique');
        $this->restoreSellerOrderUnique('seller_settlements', 'seller_settlements_seller_order_idx', 'seller_settlements_seller_order_id_unique');
    }

    private function restoreSellerOrderUnique(string $tableName, string $indexName, string $uniqueName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $indexes = collect(Schema::getIndexes($tableName));
        $lookup = $indexes->first(fn (array $index): bool => $index['name'] === $indexName);
        if ($lookup) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }
        if (! collect(Schema::getIndexes($tableName))->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['seller_order_id'])) {
            Schema::table($tableName, function (Blueprint $table) use ($uniqueName): void {
                $table->unique('seller_order_id', $uniqueName);
            });
        }
    }
};
