<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'flash_sale_quantity')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->unsignedInteger('flash_sale_quantity')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('order_items', 'flash_sale_quantity')) {
            return;
        }

        if (DB::table('order_items')->where('flash_sale_quantity', '>', 0)->exists()) {
            throw new RuntimeException('Flash-sale order history exists. Preserve it before rolling back this migration.');
        }

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('flash_sale_quantity');
        });
    }
};
