<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'seller_discount_budget_total')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->decimal('seller_discount_budget_total', 12, 2)->nullable()->after('campaign_budget_total');
            });
        }

        if (! Schema::hasTable('voucher_variants')) {
            Schema::create('voucher_variants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['voucher_id', 'product_variant_id'], 'voucher_variant_unique');
                $table->index('product_variant_id', 'voucher_variant_variant_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('voucher_variants') && DB::table('voucher_variants')->exists()) {
            throw new RuntimeException('Cannot remove voucher variant targeting while voucher campaigns still use it.');
        }

        Schema::dropIfExists('voucher_variants');

        if (Schema::hasColumn('vouchers', 'seller_discount_budget_total')
            && DB::table('vouchers')->whereNotNull('seller_discount_budget_total')->exists()) {
            throw new RuntimeException('Cannot remove configured Seller voucher budgets.');
        }

        if (Schema::hasColumn('vouchers', 'seller_discount_budget_total')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropColumn('seller_discount_budget_total');
            });
        }
    }
};
