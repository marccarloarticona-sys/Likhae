<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_pickup_requests') || ! Schema::hasTable('logistics_centers')) {
            throw new RuntimeException('Create seller pickup requests and logistics centers before adding the Seller-selected center field.');
        }

        if (! Schema::hasColumn('seller_pickup_requests', 'preferred_logistics_center_id')) {
            Schema::table('seller_pickup_requests', function (Blueprint $table): void {
                $table->foreignId('preferred_logistics_center_id')->nullable();
            });
        }

        if (! Schema::hasIndex('seller_pickup_requests', 'seller_pickup_preferred_center_status_idx')) {
            Schema::table('seller_pickup_requests', function (Blueprint $table): void {
                $table->index(['preferred_logistics_center_id', 'status'], 'seller_pickup_preferred_center_status_idx');
            });
        }

        $foreignKeys = collect(Schema::getForeignKeys('seller_pickup_requests'));
        if (! $foreignKeys->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'seller_pickup_preferred_center_fk')) {
            Schema::table('seller_pickup_requests', function (Blueprint $table): void {
                $table->foreign('preferred_logistics_center_id', 'seller_pickup_preferred_center_fk')
                    ->references('id')->on('logistics_centers')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('seller_pickup_requests')
            || ! Schema::hasColumn('seller_pickup_requests', 'preferred_logistics_center_id')) {
            return;
        }

        if (DB::table('seller_pickup_requests')->whereNotNull('preferred_logistics_center_id')->exists()) {
            throw new RuntimeException('Seller Logistics choices are in use. Preserve pickup requests and roll forward instead of removing the selected center column.');
        }

        if (collect(Schema::getForeignKeys('seller_pickup_requests'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === 'seller_pickup_preferred_center_fk')) {
            Schema::table('seller_pickup_requests', fn (Blueprint $table) => $table->dropForeign('seller_pickup_preferred_center_fk'));
        }
        if (Schema::hasIndex('seller_pickup_requests', 'seller_pickup_preferred_center_status_idx')) {
            Schema::table('seller_pickup_requests', fn (Blueprint $table) => $table->dropIndex('seller_pickup_preferred_center_status_idx'));
        }
        Schema::table('seller_pickup_requests', fn (Blueprint $table) => $table->dropColumn('preferred_logistics_center_id'));
    }
};
