<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair deployments that ran an earlier branding-only version of the
     * seller profile migration before the store settings fields were added.
     */
    public function up(): void
    {
        $columns = [
            'tagline' => fn (Blueprint $table) => $table->string('tagline', 180)->nullable(),
            'description' => fn (Blueprint $table) => $table->text('description')->nullable(),
            'location' => fn (Blueprint $table) => $table->string('location')->nullable(),
            'business_days' => fn (Blueprint $table) => $table->string('business_days', 80)->nullable(),
            'business_hours' => fn (Blueprint $table) => $table->string('business_hours', 80)->nullable(),
            'processing_days' => fn (Blueprint $table) => $table->unsignedTinyInteger('processing_days')->default(1),
            'order_cutoff' => fn (Blueprint $table) => $table->string('order_cutoff', 80)->nullable(),
            'vacation_mode' => fn (Blueprint $table) => $table->boolean('vacation_mode')->default(false),
            'auto_accept_orders' => fn (Blueprint $table) => $table->boolean('auto_accept_orders')->default(false),
            'store_visibility' => fn (Blueprint $table) => $table->boolean('store_visibility')->default(true),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::hasColumn('seller_profiles', $column)) {
                continue;
            }

            Schema::table('seller_profiles', $definition);
        }
    }

    public function down(): void
    {
        // These fields may belong to the earlier migration, so a repair
        // rollback must never remove data created outside this migration.
    }
};
