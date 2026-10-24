<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('product_variants', 'flash_sale_price')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->decimal('flash_sale_price', 10, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('product_variants', 'flash_sale_allocation')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('flash_sale_allocation')->nullable();
            });
        }

        if (! Schema::hasColumn('product_variants', 'flash_sale_sold')) {
            Schema::table('product_variants', function (Blueprint $table): void {
                $table->unsignedInteger('flash_sale_sold')->default(0);
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['flash_sale_price', 'flash_sale_allocation', 'flash_sale_sold'],
            fn (string $column): bool => Schema::hasColumn('product_variants', $column),
        ));

        if ($columns !== []) {
            Schema::table('product_variants', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
