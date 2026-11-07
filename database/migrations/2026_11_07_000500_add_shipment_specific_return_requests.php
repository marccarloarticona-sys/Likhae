<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['return_refund_request_items', 'seller_return_refund_requests'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("The {$table} table must exist before parcel-specific returns can be enabled.");
            }
        }

        if (! Schema::hasColumn('return_refund_request_items', 'shipment_id')) {
            Schema::table('return_refund_request_items', function (Blueprint $table): void {
                $table->foreignId('shipment_id')->nullable()->after('order_item_id')
                    ->constrained('shipments', 'id', 'rr_item_shipment_fk')->restrictOnDelete();
                $table->index(['shipment_id', 'order_item_id'], 'rr_item_shipment_order_idx');
            });
        }

        if (! Schema::hasColumn('seller_return_refund_requests', 'shipment_id')) {
            Schema::table('seller_return_refund_requests', function (Blueprint $table): void {
                $table->foreignId('shipment_id')->nullable()->after('seller_order_id')
                    ->constrained('shipments', 'id', 'srr_shipment_fk')->restrictOnDelete();
                $table->index(['shipment_id', 'status'], 'srr_shipment_status_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (['return_refund_request_items', 'seller_return_refund_requests'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'shipment_id')
                && DB::table($table)->whereNotNull('shipment_id')->exists()) {
                throw new RuntimeException("Parcel-specific return records exist in {$table}; preserve their parcel links before rolling back.");
            }
        }

        if (Schema::hasTable('return_refund_request_items') && Schema::hasColumn('return_refund_request_items', 'shipment_id')) {
            Schema::table('return_refund_request_items', function (Blueprint $table): void {
                $foreignKeys = collect(Schema::getForeignKeys('return_refund_request_items'))->pluck('name')->all();
                if (in_array('rr_item_shipment_fk', $foreignKeys, true)) $table->dropForeign('rr_item_shipment_fk');
                $indexes = collect(Schema::getIndexes('return_refund_request_items'))->pluck('name')->all();
                if (in_array('rr_item_shipment_order_idx', $indexes, true)) $table->dropIndex('rr_item_shipment_order_idx');
                $table->dropColumn('shipment_id');
            });
        }

        if (Schema::hasTable('seller_return_refund_requests') && Schema::hasColumn('seller_return_refund_requests', 'shipment_id')) {
            Schema::table('seller_return_refund_requests', function (Blueprint $table): void {
                $foreignKeys = collect(Schema::getForeignKeys('seller_return_refund_requests'))->pluck('name')->all();
                if (in_array('srr_shipment_fk', $foreignKeys, true)) $table->dropForeign('srr_shipment_fk');
                $indexes = collect(Schema::getIndexes('seller_return_refund_requests'))->pluck('name')->all();
                if (in_array('srr_shipment_status_idx', $indexes, true)) $table->dropIndex('srr_shipment_status_idx');
                $table->dropColumn('shipment_id');
            });
        }
    }
};
