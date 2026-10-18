<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // Keep MySQL's seller_order_id foreign key backed by an index while
            // replacing the unique index with a regular index.
            $table->index('seller_order_id', 'shipments_seller_order_id_index');
            $table->dropUnique('shipments_seller_order_id_unique');
            $table->foreignId('order_item_id')
                ->nullable()
                ->unique('shipments_order_item_id_unique')
                ->after('seller_order_id')
                ->constrained('order_items')
                ->restrictOnDelete();
        });

        // Existing one-line parcels can be linked without guessing. Ambiguous
        // multi-line legacy parcels remain null until Logistics reconciles them.
        DB::table('seller_orders')
            ->select('id')
            ->orderBy('id')
            ->chunkById(200, function ($sellerOrders): void {
                foreach ($sellerOrders as $sellerOrder) {
                    $items = DB::table('order_items')
                        ->where('seller_order_id', $sellerOrder->id)
                        ->limit(2)
                        ->get(['id']);

                    if ($items->count() !== 1) {
                        continue;
                    }

                    DB::table('shipments')
                        ->where('seller_order_id', $sellerOrder->id)
                        ->whereNull('order_item_id')
                        ->update(['order_item_id' => $items->first()->id]);
                }
            });
    }

    public function down(): void
    {
        $hasMultipleParcels = DB::table('shipments')
            ->select('seller_order_id')
            ->groupBy('seller_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasMultipleParcels) {
            throw new RuntimeException('Cannot roll back item-level parcels while a seller order has multiple shipments.');
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign(['order_item_id']);
            $table->dropUnique('shipments_order_item_id_unique');
            $table->dropColumn('order_item_id');
            $table->dropIndex('shipments_seller_order_id_index');
            $table->unique('seller_order_id', 'shipments_seller_order_id_unique');
        });
    }
};
