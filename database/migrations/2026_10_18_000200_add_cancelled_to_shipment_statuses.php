<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHIPMENT_STATUSES = [
        'PLACED',
        'CONFIRMED',
        'PREPARING',
        'READY_FOR_PICKUP',
        'PICKED_UP',
        'AT_SORTING_CENTER',
        'SORTED',
        'ASSIGNED_TO_RIDER',
        'OUT_FOR_DELIVERY',
        'DELIVERED',
        'COMPLETED',
        'DELIVERY_FAILED',
        'RETURNED',
        'CANCELLED',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('shipments') || ! Schema::hasColumn('shipments', 'current_status')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->enum('current_status', self::SHIPMENT_STATUSES)
                ->default('PLACED')
                ->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('shipments') && DB::table('shipments')->where('current_status', 'CANCELLED')->exists()) {
            throw new RuntimeException('Cancelled shipments exist; this migration cannot remove their status.');
        }

        if (! Schema::hasTable('shipments') || ! Schema::hasColumn('shipments', 'current_status')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->enum('current_status', array_values(array_diff(self::SHIPMENT_STATUSES, ['CANCELLED'])))
                ->default('PLACED')
                ->change();
        });
    }
};
