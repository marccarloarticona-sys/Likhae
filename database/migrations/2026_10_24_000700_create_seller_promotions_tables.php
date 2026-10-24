<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_promotions')) {
            Schema::create('seller_promotions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_profile_id')->constrained()->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('promotion_type', 24);
                $table->decimal('bundle_price', 12, 2)->nullable();
                $table->foreignId('buy_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
                $table->unsignedSmallInteger('buy_quantity')->nullable();
                $table->foreignId('reward_variant_id')->nullable()->constrained('product_variants')->restrictOnDelete();
                $table->unsignedSmallInteger('reward_quantity')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['seller_profile_id', 'promotion_type', 'is_active'], 'seller_promotions_active_idx');
                $table->index(['starts_at', 'ends_at'], 'seller_promotions_window_idx');
            });
        }

        if (! Schema::hasTable('seller_promotion_components')) {
            Schema::create('seller_promotion_components', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_promotion_id')->constrained('seller_promotions')->cascadeOnDelete();
                $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->timestamps();

                $table->unique(['seller_promotion_id', 'product_variant_id'], 'seller_promotion_variant_unique');
                $table->index('product_variant_id', 'seller_promotion_variant_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('seller_promotions') && DB::table('seller_promotions')->exists()) {
            throw new RuntimeException('Cannot remove seller promotions while campaign records exist.');
        }

        Schema::dropIfExists('seller_promotion_components');
        Schema::dropIfExists('seller_promotions');
    }
};
