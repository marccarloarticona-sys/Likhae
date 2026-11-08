<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_variants')
            && ! Schema::hasColumn('product_variants', 'reserve_stock')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('reserve_stock')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_variants')
            || ! Schema::hasColumn('product_variants', 'reserve_stock')) {
            return;
        }

        if (DB::table('product_variants')->where('reserve_stock', '>', 0)->exists()) {
            throw new RuntimeException('Seller reserve stock values are in use. Preserve inventory settings and roll forward instead of removing the reserve_stock column.');
        }

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('reserve_stock');
        });
    }
};
