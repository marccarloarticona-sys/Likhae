<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'flash_sale_is_paused')) {
                $table->boolean('flash_sale_is_paused')->default(false)->after('flash_sale_ends_at');
            }
            if (! Schema::hasColumn('products', 'flash_sale_limit_per_buyer')) {
                $table->unsignedInteger('flash_sale_limit_per_buyer')->nullable()->after('flash_sale_is_paused');
            }
            if (! Schema::hasColumn('products', 'flash_sale_campaign_key')) {
                $table->string('flash_sale_campaign_key', 36)->nullable()->after('flash_sale_limit_per_buyer');
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'flash_sale_campaign_key')) {
                $table->string('flash_sale_campaign_key', 36)->nullable()->after('flash_sale_quantity');
            }
        });

        // Assign stable keys to configured campaigns and connect matching
        // existing order snapshots without removing or changing their prices.
        $productIds = DB::table('product_variants')
            ->whereNotNull('flash_sale_price')
            ->where('flash_sale_price', '>', 0)
            ->whereNotNull('flash_sale_allocation')
            ->distinct()
            ->pluck('product_id');

        foreach ($productIds as $productId) {
            $product = DB::table('products')->where('id', $productId)->first();
            if (! $product) {
                continue;
            }

            $campaignKey = $product->flash_sale_campaign_key ?: (string) Str::uuid();
            DB::table('products')->where('id', $productId)->update([
                'flash_sale_campaign_key' => $campaignKey,
            ]);

            $itemIds = DB::table('order_items')
                ->join('seller_orders', 'seller_orders.id', '=', 'order_items.seller_order_id')
                ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
                ->where('order_items.product_id', $productId)
                ->where('order_items.flash_sale_quantity', '>', 0)
                ->whereNull('order_items.flash_sale_campaign_key')
                ->where('seller_orders.status', '<>', 'CANCELLED')
                ->where('orders.status', '<>', 'CANCELLED')
                ->when($product->flash_sale_starts_at, fn ($query) => $query->where('orders.placed_at', '>=', $product->flash_sale_starts_at))
                ->when($product->flash_sale_ends_at, fn ($query) => $query->where('orders.placed_at', '<', $product->flash_sale_ends_at))
                ->select('order_items.id')
                ->pluck('order_items.id');

            foreach ($itemIds->chunk(500) as $chunk) {
                DB::table('order_items')->whereIn('id', $chunk)->update([
                    'flash_sale_campaign_key' => $campaignKey,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Preserve new campaign keys and order snapshots if application code is
        // rolled back. Removing these additive columns would erase new history.
    }
};
