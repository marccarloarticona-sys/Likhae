<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        if (! Schema::hasColumn('shipments', 'parcel_quantity')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->unsignedInteger('parcel_quantity')->nullable()->after('order_item_id');
            });
        }

        if (! Schema::hasColumn('shipments', 'order_item_id')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('shipments'));
        $uniqueIndex = $indexes->first(fn (array $index): bool => ($index['name'] ?? null) === 'shipments_order_item_id_unique');
        $plainIndex = $indexes->first(fn (array $index): bool => ($index['name'] ?? null) === 'shipments_order_item_id_index');
        if (! $plainIndex) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->index('order_item_id', 'shipments_order_item_id_index');
            });
        }
        if ($uniqueIndex) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->dropUnique('shipments_order_item_id_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        if (Schema::hasColumn('shipments', 'order_item_id')) {
            $hasSplitOrderItem = DB::table('shipments')
                ->whereNotNull('order_item_id')
                ->select('order_item_id')
                ->groupBy('order_item_id')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if ($hasSplitOrderItem) {
                throw new RuntimeException('Cannot roll back split parcel support while an order item has multiple shipments.');
            }

            $indexes = collect(Schema::getIndexes('shipments'));
            $plainIndex = $indexes->first(fn (array $index): bool => ($index['name'] ?? null) === 'shipments_order_item_id_index');
            $uniqueIndex = $indexes->first(fn (array $index): bool => ($index['name'] ?? null) === 'shipments_order_item_id_unique');
            if (! $uniqueIndex) {
                Schema::table('shipments', function (Blueprint $table): void {
                    $table->unique('order_item_id', 'shipments_order_item_id_unique');
                });
            }
            if ($plainIndex) {
                Schema::table('shipments', function (Blueprint $table): void {
                    $table->dropIndex('shipments_order_item_id_index');
                });
            }
        }

        if (Schema::hasColumn('shipments', 'parcel_quantity')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->dropColumn('parcel_quantity');
            });
        }
    }
};
