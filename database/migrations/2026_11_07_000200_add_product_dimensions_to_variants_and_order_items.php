<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $variantColumns = ['length_cm', 'width_cm', 'height_cm'];
        if (Schema::hasTable('product_variants')) {
            $missing = array_values(array_filter($variantColumns, fn (string $column): bool => ! Schema::hasColumn('product_variants', $column)));
            if ($missing !== []) {
                Schema::table('product_variants', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->decimal($column, 10, 2)->nullable();
                    }
                });
            }
        }

        $snapshotColumns = ['product_length_cm', 'product_width_cm', 'product_height_cm'];
        if (Schema::hasTable('order_items')) {
            $missing = array_values(array_filter($snapshotColumns, fn (string $column): bool => ! Schema::hasColumn('order_items', $column)));
            if ($missing !== []) {
                Schema::table('order_items', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column) {
                        $table->decimal($column, 10, 2)->nullable();
                    }
                });
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Product dimension snapshots are forward-only so order and waybill history is preserved.');
    }
};
