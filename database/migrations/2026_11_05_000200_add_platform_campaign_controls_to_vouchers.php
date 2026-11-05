<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'distribution_mode' => fn (Blueprint $table) => $table->string('distribution_mode', 16)->default('CODE'),
            'target_mode' => fn (Blueprint $table) => $table->string('target_mode', 16)->default('ALL'),
            'target_category_ids' => fn (Blueprint $table) => $table->json('target_category_ids')->nullable(),
            'target_product_ids' => fn (Blueprint $table) => $table->json('target_product_ids')->nullable(),
            'target_seller_ids' => fn (Blueprint $table) => $table->json('target_seller_ids')->nullable(),
            'buyer_audience' => fn (Blueprint $table) => $table->string('buyer_audience', 16)->default('ALL'),
            'stack_with_seller' => fn (Blueprint $table) => $table->boolean('stack_with_seller')->default(true),
            'stack_with_shipping' => fn (Blueprint $table) => $table->boolean('stack_with_shipping')->default(true),
            'campaign_status' => fn (Blueprint $table) => $table->string('campaign_status', 16)->default('ACTIVE'),
        ];

        foreach ($columns as $column => $add) {
            if (! Schema::hasColumn('vouchers', $column)) {
                Schema::table('vouchers', $add);
            }
        }
    }

    public function down(): void
    {
        // Preserve campaign definitions and eligibility history on rollback.
    }
};
