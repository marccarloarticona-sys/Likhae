<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'discounts_enabled')
            || ! Schema::hasColumn('products', 'discount_starts_at')
            || ! Schema::hasColumn('products', 'discount_ends_at')) {
            Schema::table('products', function (Blueprint $table): void {
                if (! Schema::hasColumn('products', 'discounts_enabled')) {
                    $table->boolean('discounts_enabled')->default(true);
                }
                if (! Schema::hasColumn('products', 'discount_starts_at')) {
                    $table->timestamp('discount_starts_at')->nullable();
                }
                if (! Schema::hasColumn('products', 'discount_ends_at')) {
                    $table->timestamp('discount_ends_at')->nullable();
                }
            });
        }

        if (Schema::hasColumn('products', 'discount_starts_at')
            && Schema::hasColumn('products', 'discount_ends_at')
            && Schema::hasColumn('products', 'flash_sale_starts_at')
            && Schema::hasColumn('products', 'flash_sale_ends_at')
            && Schema::hasTable('product_variants')
            && Schema::hasColumn('product_variants', 'discount_type')
            && Schema::hasColumn('product_variants', 'discount_value')
            && Schema::hasColumn('product_variants', 'flash_sale_price')) {
            DB::table('products')->select(['id', 'flash_sale_starts_at', 'flash_sale_ends_at'])
                ->where(function ($query): void {
                    $query->whereNotNull('flash_sale_starts_at')->orWhereNotNull('flash_sale_ends_at');
                })
                ->orderBy('id')->chunkById(200, function ($products): void {
                    foreach ($products as $product) {
                        $hasLegacyDiscount = DB::table('product_variants')
                            ->where('product_id', $product->id)
                            ->where('discount_value', '>', 0)
                            ->where(function ($query): void {
                                $query->whereNull('discount_type')
                                    ->orWhereRaw("LOWER(TRIM(discount_type)) <> 'none'");
                            })->exists();
                        if (! $hasLegacyDiscount) {
                            continue;
                        }

                        DB::table('products')->where('id', $product->id)
                            ->whereNull('discount_starts_at')->whereNull('discount_ends_at')->update([
                            'discount_starts_at' => $product->flash_sale_starts_at,
                            'discount_ends_at' => $product->flash_sale_ends_at,
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Preserve seller discount availability if this additive flag is rolled back.
    }
};
