<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_promotions')) {
            return;
        }

        Schema::table('seller_promotions', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_promotions', 'reward_product_id')) {
                $table->foreignId('reward_product_id')->nullable()->after('reward_variant_id')->constrained('products')->restrictOnDelete();
            }
            if (! Schema::hasColumn('seller_promotions', 'reward_discount_percent')) {
                $table->decimal('reward_discount_percent', 5, 2)->default(100)->after('reward_quantity');
            }
            if (! Schema::hasColumn('seller_promotions', 'max_redemptions')) {
                $table->unsignedInteger('max_redemptions')->nullable()->after('reward_discount_percent');
            }
            if (! Schema::hasColumn('seller_promotions', 'max_per_buyer')) {
                $table->unsignedSmallInteger('max_per_buyer')->nullable()->after('max_redemptions');
            }
            if (! Schema::hasColumn('seller_promotions', 'redemptions_used')) {
                $table->unsignedInteger('redemptions_used')->default(0)->after('max_per_buyer');
            }
        });
        if (Schema::hasTable('seller_promotion_components')
            && ! Schema::hasColumn('seller_promotion_components', 'choice_group')) {
            Schema::table('seller_promotion_components', function (Blueprint $table): void {
                $table->string('choice_group', 60)->nullable()->index();
            });
        }
        // Existing Buy X Get Y records retain their original free-reward meaning
        // through the new column default. Order and campaign rows are not rebuilt.
    }

    public function down(): void
    {
        // Campaign controls and reward targeting may now be referenced by orders.
        // Preserve them if a rollback is requested.
    }
};
