<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_pickup_center_offers')) {
            return;
        }

        if (! Schema::hasColumn('seller_pickup_center_offers', 'leg_type')) {
            Schema::table('seller_pickup_center_offers', function (Blueprint $table): void {
                $table->string('leg_type', 16)->default('PICKUP')->after('logistics_center_id');
                $table->index(['logistics_center_id', 'leg_type', 'status', 'expires_at'], 'spco_center_leg_status_exp_idx');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Seller pickup offer routing history is forward-only. Preserve all offer records.');
    }
};
