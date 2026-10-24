<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'applies_to')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->string('applies_to', 20)->default('STORE')->after('per_user_limit');
            });
        }

        if (! Schema::hasTable('voucher_products')) {
            Schema::create('voucher_products', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('voucher_id')->constrained('vouchers')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['voucher_id', 'product_id']);
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_products');

        if (Schema::hasColumn('vouchers', 'applies_to')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropColumn('applies_to');
            });
        }
    }
};
