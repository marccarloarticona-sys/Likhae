<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipment_return_workflows') || ! Schema::hasTable('seller_return_refund_requests')) {
            throw new RuntimeException('Install shipment return and seller return request migrations before adding buyer return waybills.');
        }

        $shipmentUnique = collect(Schema::getIndexes('shipment_return_workflows'))
            ->first(fn (array $index): bool => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['shipment_id']);
        if ($shipmentUnique) {
            Schema::table('shipment_return_workflows', function (Blueprint $table) use ($shipmentUnique): void {
                $table->dropUnique($shipmentUnique['name']);
            });
        }

        Schema::table('shipment_return_workflows', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')) {
                $table->foreignId('seller_return_refund_request_id')
                    ->nullable()
                    ->constrained('seller_return_refund_requests', 'id', 'srw_seller_return_request_fk')
                    ->restrictOnDelete();
                $table->unique('seller_return_refund_request_id', 'srw_seller_return_request_unique');
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'return_tracking_number')) {
                $table->string('return_tracking_number', 80)->nullable()->unique('srw_return_tracking_unique');
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'buyer_dropoff_logistics_center_id')) {
                $table->foreignId('buyer_dropoff_logistics_center_id')
                    ->nullable()
                    ->constrained('logistics_centers', 'id', 'srw_buyer_dropoff_center_fk')
                    ->restrictOnDelete();
            }
        });

        $indexes = collect(Schema::getIndexes('shipment_return_workflows'));
        if (! $indexes->contains(fn (array $index): bool => ($index['name'] ?? null) === 'srw_seller_return_request_unique')) {
            Schema::table('shipment_return_workflows', function (Blueprint $table): void {
                $table->unique('seller_return_refund_request_id', 'srw_seller_return_request_unique');
            });
        }
        if (! $indexes->contains(fn (array $index): bool => ($index['name'] ?? null) === 'srw_return_tracking_unique')) {
            Schema::table('shipment_return_workflows', function (Blueprint $table): void {
                $table->unique('return_tracking_number', 'srw_return_tracking_unique');
            });
        }

        $foreignKeys = collect(Schema::getForeignKeys('shipment_return_workflows'));
        foreach ([
            'seller_return_refund_request_id' => ['seller_return_refund_requests', 'srw_seller_return_request_fk'],
            'buyer_dropoff_logistics_center_id' => ['logistics_centers', 'srw_buyer_dropoff_center_fk'],
        ] as $column => [$referencedTable, $name]) {
            if (! $foreignKeys->contains(fn (array $foreignKey): bool => ($foreignKey['name'] ?? null) === $name)) {
                Schema::table('shipment_return_workflows', function (Blueprint $table) use ($column, $referencedTable, $name): void {
                    $table->foreign($column, $name)->references('id')->on($referencedTable)->restrictOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipment_return_workflows')) {
            return;
        }

        if (Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')
            && DB::table('shipment_return_workflows')->whereNotNull('seller_return_refund_request_id')->exists()) {
            throw new RuntimeException('Buyer return waybill records exist; resolve or preserve them before rolling back this migration.');
        }
        if (Schema::hasColumn('shipment_return_workflows', 'return_tracking_number')
            && DB::table('shipment_return_workflows')->whereNotNull('return_tracking_number')->exists()) {
            throw new RuntimeException('Printed return waybills exist; preserve them before rolling back this migration.');
        }
        if (DB::table('shipment_return_workflows')->select('shipment_id')->groupBy('shipment_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Multiple return workflow records reference one shipment; the original unique index cannot be restored safely.');
        }

        Schema::table('shipment_return_workflows', function (Blueprint $table): void {
            if (Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')) {
                $table->dropForeign('srw_seller_return_request_fk');
                $table->dropUnique('srw_seller_return_request_unique');
                $table->dropColumn('seller_return_refund_request_id');
            }
            if (Schema::hasColumn('shipment_return_workflows', 'return_tracking_number')) {
                $table->dropUnique('srw_return_tracking_unique');
                $table->dropColumn('return_tracking_number');
            }
            if (Schema::hasColumn('shipment_return_workflows', 'buyer_dropoff_logistics_center_id')) {
                if (DB::table('shipment_return_workflows')->whereNotNull('buyer_dropoff_logistics_center_id')->exists()) {
                    throw new RuntimeException('Buyer drop-off center snapshots exist; preserve them before rolling back this migration.');
                }
                $table->dropForeign('srw_buyer_dropoff_center_fk');
                $table->dropColumn('buyer_dropoff_logistics_center_id');
            }
            $table->unique('shipment_id', 'shipment_return_workflows_shipment_id_unique');
        });
    }
};
