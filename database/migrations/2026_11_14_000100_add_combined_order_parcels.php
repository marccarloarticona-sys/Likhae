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

        Schema::table('shipments', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipments', 'combined_order_items')) {
                $table->boolean('combined_order_items')->default(false)->after('parcel_quantity');
            }
            if (! Schema::hasColumn('shipments', 'combined_source_shipment_ids')) {
                $table->json('combined_source_shipment_ids')->nullable()->after('combined_order_items');
            }
            if (! Schema::hasColumn('shipments', 'combined_order_item_ids')) {
                $table->json('combined_order_item_ids')->nullable()->after('combined_source_shipment_ids');
            }
            if (! Schema::hasColumn('shipments', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->index();
            }
            if (! Schema::hasColumn('shipments', 'superseded_by_shipment_id')) {
                $table->unsignedBigInteger('superseded_by_shipment_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        if (Schema::hasColumn('shipments', 'combined_order_items')
            && DB::table('shipments')->where('combined_order_items', true)->exists()) {
            throw new RuntimeException('Cannot remove combined parcel support while combined parcels exist. Separate them first.');
        }
        if (Schema::hasColumn('shipments', 'superseded_at')
            && DB::table('shipments')->whereNotNull('superseded_at')->exists()) {
            throw new RuntimeException('Cannot remove combined parcel support while source parcel history is retained.');
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $columns = array_filter([
                Schema::hasColumn('shipments', 'combined_order_items') ? 'combined_order_items' : null,
                Schema::hasColumn('shipments', 'combined_source_shipment_ids') ? 'combined_source_shipment_ids' : null,
                Schema::hasColumn('shipments', 'combined_order_item_ids') ? 'combined_order_item_ids' : null,
                Schema::hasColumn('shipments', 'superseded_at') ? 'superseded_at' : null,
                Schema::hasColumn('shipments', 'superseded_by_shipment_id') ? 'superseded_by_shipment_id' : null,
            ]);
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
