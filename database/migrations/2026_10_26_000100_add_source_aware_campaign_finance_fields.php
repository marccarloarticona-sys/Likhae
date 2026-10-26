<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'campaign_budget_total')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->decimal('campaign_budget_total', 12, 2)->nullable()->after('benefit_scope');
            });
        }

        foreach ([
            'seller_voucher_discount',
            'platform_voucher_discount',
            'platform_shipping_voucher_discount',
        ] as $column) {
            if (! Schema::hasColumn('seller_orders', $column)) {
                Schema::table('seller_orders', function (Blueprint $table) use ($column): void {
                    $table->decimal($column, 12, 2)->default(0);
                });
            }
        }
        if (! Schema::hasColumn('seller_orders', 'funding_snapshot_version')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->unsignedSmallInteger('funding_snapshot_version')->nullable();
            });
        }
        if (! Schema::hasColumn('seller_orders', 'commission_rate_snapshot')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->decimal('commission_rate_snapshot', 5, 4)->nullable();
            });
        }

        foreach ([
            'seller_voucher_discount',
            'platform_voucher_discount',
            'platform_shipping_voucher_discount',
            'reward_points_discount',
        ] as $column) {
            if (! Schema::hasColumn('order_items', $column)) {
                Schema::table('order_items', function (Blueprint $table) use ($column): void {
                    $table->decimal($column, 12, 2)->default(0);
                });
            }
        }
        if (! Schema::hasColumn('order_items', 'funding_snapshot_version')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->unsignedSmallInteger('funding_snapshot_version')->nullable();
            });
        }

        if (! Schema::hasColumn('financial_ledger_entries', 'voucher_id')) {
            Schema::table('financial_ledger_entries', function (Blueprint $table): void {
                $table->foreignId('voucher_id')->nullable()->after('order_id')->constrained('vouchers')->restrictOnDelete();
            });
        }
        if (! Schema::hasColumn('financial_ledger_entries', 'order_item_id')) {
            Schema::table('financial_ledger_entries', function (Blueprint $table): void {
                $table->foreignId('order_item_id')->nullable()->after('voucher_id')->constrained('order_items')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Retain financial snapshots and ledger references on rollback. Removing
        // these columns would destroy the audit trail for campaign commitments.
    }
};
