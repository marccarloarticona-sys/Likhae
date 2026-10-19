<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHIPMENT_STATUSES = [
        'PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_ASSIGNED', 'PICKED_UP',
        'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED',
        'COMPLETED', 'DELIVERY_FAILED', 'RETURNED', 'CANCELLED', 'LOST', 'DAMAGED',
    ];

    private const EVENT_STATUSES = [
        'PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_ASSIGNED', 'PICKED_UP',
        'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED',
        'COMPLETED', 'DELIVERY_FAILED', 'RETURNED', 'LOST', 'DAMAGED',
    ];

    public function up(): void
    {
        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'current_status')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->enum('current_status', self::SHIPMENT_STATUSES)->default('PLACED')->change();
            });
        }

        if (Schema::hasTable('shipment_events') && Schema::hasColumn('shipment_events', 'status')) {
            Schema::table('shipment_events', function (Blueprint $table): void {
                $table->enum('status', self::EVENT_STATUSES)->change();
            });
        }

        if (Schema::hasTable('dispute_evidence')) {
            $addDisk = ! Schema::hasColumn('dispute_evidence', 'disk');
            $addEncrypted = ! Schema::hasColumn('dispute_evidence', 'encrypted');

            if ($addDisk || $addEncrypted) {
                Schema::table('dispute_evidence', function (Blueprint $table) use ($addDisk, $addEncrypted): void {
                    if ($addDisk) {
                        $table->string('disk', 50)->default('public');
                    }
                    if ($addEncrypted) {
                        $table->boolean('encrypted')->default(false);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->whereIn('current_status', ['LOST', 'DAMAGED'])->exists()) {
            throw new RuntimeException('Lost or damaged shipments exist; their statuses cannot be removed.');
        }
        if (Schema::hasTable('shipment_events') && DB::table('shipment_events')->whereIn('status', ['LOST', 'DAMAGED'])->exists()) {
            throw new RuntimeException('Lost or damaged shipment events exist; their statuses cannot be removed.');
        }

        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'current_status')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->enum('current_status', array_values(array_diff(self::SHIPMENT_STATUSES, ['LOST', 'DAMAGED'])))->default('PLACED')->change();
            });
        }
        if (Schema::hasTable('shipment_events') && Schema::hasColumn('shipment_events', 'status')) {
            Schema::table('shipment_events', function (Blueprint $table): void {
                $table->enum('status', array_values(array_diff(self::EVENT_STATUSES, ['LOST', 'DAMAGED'])))->change();
            });
        }
        if (Schema::hasTable('dispute_evidence')) {
            $dropDisk = Schema::hasColumn('dispute_evidence', 'disk');
            $dropEncrypted = Schema::hasColumn('dispute_evidence', 'encrypted');

            if ($dropDisk || $dropEncrypted) {
                Schema::table('dispute_evidence', function (Blueprint $table) use ($dropDisk, $dropEncrypted): void {
                    $columns = [];
                    if ($dropDisk) {
                        $columns[] = 'disk';
                    }
                    if ($dropEncrypted) {
                        $columns[] = 'encrypted';
                    }
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
