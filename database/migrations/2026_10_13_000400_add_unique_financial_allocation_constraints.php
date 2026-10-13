<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHIPMENT_UNIQUE_INDEX = 'shipment_financial_allocations_shipment_id_unique';

    private const SELLER_ORDER_UNIQUE_INDEX = 'shipment_financial_allocations_seller_order_id_unique';

    public function up(): void
    {
        if (!Schema::hasTable('shipment_financial_allocations')) {
            return;
        }

        $shipmentDuplicates = DB::table('shipment_financial_allocations')
            ->select('shipment_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('shipment_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $sellerOrderDuplicates = DB::table('shipment_financial_allocations')
            ->select('seller_order_id')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('seller_order_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($shipmentDuplicates->isNotEmpty() || $sellerOrderDuplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add unique constraints to shipment_financial_allocations: duplicate shipment_id or seller_order_id values exist. Resolve the duplicate rows separately; no indexes were added.'
            );
        }

        $indexes = collect(Schema::getIndexes('shipment_financial_allocations'));
        $hasUniqueShipment = $indexes->contains(
            fn (array $index): bool => $index['unique'] === true && $index['columns'] === ['shipment_id']
        );
        $hasUniqueSellerOrder = $indexes->contains(
            fn (array $index): bool => $index['unique'] === true && $index['columns'] === ['seller_order_id']
        );

        if ($hasUniqueShipment && $hasUniqueSellerOrder) {
            return;
        }

        Schema::table('shipment_financial_allocations', function (Blueprint $table) use ($hasUniqueShipment, $hasUniqueSellerOrder): void {
            if (!$hasUniqueShipment) {
                $table->unique('shipment_id', self::SHIPMENT_UNIQUE_INDEX);
            }

            if (!$hasUniqueSellerOrder) {
                $table->unique('seller_order_id', self::SELLER_ORDER_UNIQUE_INDEX);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipment_financial_allocations')) {
            return;
        }

        $indexes = collect(Schema::getIndexes('shipment_financial_allocations'));
        $indexesToDrop = [];

        if ($indexes->contains(fn (array $index): bool => $index['name'] === self::SHIPMENT_UNIQUE_INDEX)) {
            $indexesToDrop[] = self::SHIPMENT_UNIQUE_INDEX;
        }

        if ($indexes->contains(fn (array $index): bool => $index['name'] === self::SELLER_ORDER_UNIQUE_INDEX)) {
            $indexesToDrop[] = self::SELLER_ORDER_UNIQUE_INDEX;
        }

        if ($indexesToDrop === []) {
            return;
        }

        Schema::table('shipment_financial_allocations', function (Blueprint $table) use ($indexesToDrop): void {
            foreach ($indexesToDrop as $indexName) {
                $table->dropUnique($indexName);
            }
        });
    }
};
