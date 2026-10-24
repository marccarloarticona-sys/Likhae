<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seller_orders', 'promotion_discount_total')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->decimal('promotion_discount_total', 12, 2)->default(0)->after('voucher_discount');
            });
        }

        if (! Schema::hasColumn('order_items', 'promotion_discount_amount')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->decimal('promotion_discount_amount', 12, 2)->default(0)->after('discount_amount');
            });
        }

        if (! Schema::hasColumn('order_items', 'promotion_snapshot')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->json('promotion_snapshot')->nullable()->after('promotion_discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'promotion_discount_amount')
            && (DB::table('order_items')->where('promotion_discount_amount', '>', 0)->exists()
                || (Schema::hasColumn('order_items', 'promotion_snapshot') && DB::table('order_items')->whereNotNull('promotion_snapshot')->exists()))) {
            throw new RuntimeException('Cannot remove promotion snapshots while promoted order items exist.');
        }

        if (Schema::hasColumn('seller_orders', 'promotion_discount_total')
            && DB::table('seller_orders')->where('promotion_discount_total', '>', 0)->exists()) {
            throw new RuntimeException('Cannot remove promotion totals while promoted Seller orders exist.');
        }

        if (Schema::hasColumn('order_items', 'promotion_snapshot')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropColumn('promotion_snapshot');
            });
        }
        if (Schema::hasColumn('order_items', 'promotion_discount_amount')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropColumn('promotion_discount_amount');
            });
        }

        if (Schema::hasColumn('seller_orders', 'promotion_discount_total')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->dropColumn('promotion_discount_total');
            });
        }
    }
};
