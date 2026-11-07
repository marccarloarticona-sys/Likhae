<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_pickup_requests')
            || ! Schema::hasTable('seller_pickup_request_shipments')
            || ! Schema::hasTable('logistics_centers')) {
            throw new RuntimeException('Create Seller pickup requests and Logistics centers before adding Seller-selected fallback hubs.');
        }

        if (! Schema::hasColumn('seller_pickup_requests', 'seller_selected_fallback')) {
            Schema::table('seller_pickup_requests', function (Blueprint $table): void {
                $table->boolean('seller_selected_fallback')->default(false);
            });
        }

        if (! Schema::hasColumn('seller_pickup_request_shipments', 'preferred_destination_logistics_center_id')) {
            Schema::table('seller_pickup_request_shipments', function (Blueprint $table): void {
                $table->foreignId('preferred_destination_logistics_center_id')->nullable();
            });
        }

        if (! Schema::hasIndex('seller_pickup_request_shipments', 'seller_pickup_dest_center_idx')) {
            Schema::table('seller_pickup_request_shipments', function (Blueprint $table): void {
                $table->index('preferred_destination_logistics_center_id', 'seller_pickup_dest_center_idx');
            });
        }

        if (! collect(Schema::getForeignKeys('seller_pickup_request_shipments'))
            ->contains(fn (array $key): bool => ($key['name'] ?? null) === 'seller_pickup_dest_center_fk')) {
            Schema::table('seller_pickup_request_shipments', function (Blueprint $table): void {
                $table->foreign('preferred_destination_logistics_center_id', 'seller_pickup_dest_center_fk')
                    ->references('id')->on('logistics_centers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_pickup_requests')
            && Schema::hasColumn('seller_pickup_requests', 'seller_selected_fallback')
            && DB::table('seller_pickup_requests')->where('seller_selected_fallback', true)->exists()) {
            throw new RuntimeException('Seller-selected fallback route history is in use. Preserve pickup requests and roll forward instead of removing it.');
        }

        if (Schema::hasTable('seller_pickup_request_shipments')
            && Schema::hasColumn('seller_pickup_request_shipments', 'preferred_destination_logistics_center_id')
            && DB::table('seller_pickup_request_shipments')->whereNotNull('preferred_destination_logistics_center_id')->exists()) {
            throw new RuntimeException('Seller-selected destination hub history is in use. Preserve pickup requests and roll forward instead of removing it.');
        }

        if (Schema::hasTable('seller_pickup_request_shipments')
            && Schema::hasColumn('seller_pickup_request_shipments', 'preferred_destination_logistics_center_id')) {
            if (collect(Schema::getForeignKeys('seller_pickup_request_shipments'))
                ->contains(fn (array $key): bool => ($key['name'] ?? null) === 'seller_pickup_dest_center_fk')) {
                Schema::table('seller_pickup_request_shipments', fn (Blueprint $table) => $table->dropForeign('seller_pickup_dest_center_fk'));
            }
            if (Schema::hasIndex('seller_pickup_request_shipments', 'seller_pickup_dest_center_idx')) {
                Schema::table('seller_pickup_request_shipments', fn (Blueprint $table) => $table->dropIndex('seller_pickup_dest_center_idx'));
            }
            Schema::table('seller_pickup_request_shipments', fn (Blueprint $table) => $table->dropColumn('preferred_destination_logistics_center_id'));
        }

        if (Schema::hasTable('seller_pickup_requests') && Schema::hasColumn('seller_pickup_requests', 'seller_selected_fallback')) {
            Schema::table('seller_pickup_requests', fn (Blueprint $table) => $table->dropColumn('seller_selected_fallback'));
        }
    }
};
