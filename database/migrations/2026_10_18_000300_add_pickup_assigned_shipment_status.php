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
        'COMPLETED', 'DELIVERY_FAILED', 'RETURNED', 'CANCELLED',
    ];

    private const EVENT_STATUSES = [
        'PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP', 'PICKUP_ASSIGNED', 'PICKED_UP',
        'AT_SORTING_CENTER', 'SORTED', 'ASSIGNED_TO_RIDER', 'OUT_FOR_DELIVERY', 'DELIVERED',
        'COMPLETED', 'DELIVERY_FAILED', 'RETURNED',
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
    }

    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->where('current_status', 'PICKUP_ASSIGNED')->exists()) {
            throw new RuntimeException('Pickup-assigned shipments exist; this migration cannot remove their status.');
        }
        if (Schema::hasTable('shipment_events') && DB::table('shipment_events')->where('status', 'PICKUP_ASSIGNED')->exists()) {
            throw new RuntimeException('Pickup-assigned timeline events exist; this migration cannot remove their status.');
        }

        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'current_status')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->enum('current_status', array_values(array_diff(self::SHIPMENT_STATUSES, ['PICKUP_ASSIGNED'])))
                    ->default('PLACED')->change();
            });
        }
        if (Schema::hasTable('shipment_events') && Schema::hasColumn('shipment_events', 'status')) {
            Schema::table('shipment_events', function (Blueprint $table): void {
                $table->enum('status', array_values(array_diff(self::EVENT_STATUSES, ['PICKUP_ASSIGNED'])))->change();
            });
        }
    }
};
