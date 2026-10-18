<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipping_rate_change_requests')) {
            Schema::create('shipping_rate_change_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('requesting_logistics_user_id');
                $table->decimal('current_shipping_fee', 12, 2);
                $table->decimal('requested_shipping_fee', 12, 2);
                $table->string('change_type', 16);
                $table->decimal('change_amount', 12, 2);
                $table->decimal('change_percentage', 9, 4);
                $table->string('reason_code', 48);
                $table->text('reason_details')->nullable();
                $table->timestamp('requested_effective_at')->nullable();
                $table->string('status', 24)->default('PENDING');
                $table->foreignId('reviewed_by_admin_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('admin_decision_reason')->nullable();
                $table->timestamp('approved_effective_at')->nullable();
                $table->string('idempotency_key', 120);
                $table->timestamps();
            });
        }

        $this->ensureForeignKey('shipping_rate_change_requests', 'requesting_logistics_user_id', 'srr_requesting_user_fk', 'users');
        $this->ensureForeignKey('shipping_rate_change_requests', 'reviewed_by_admin_user_id', 'srr_reviewed_admin_fk', 'users');
        $this->ensureIndex('shipping_rate_change_requests', ['idempotency_key'], 'shipping_rate_requests_idempotency_unique', true);
        $this->ensureIndex('shipping_rate_change_requests', ['requesting_logistics_user_id', 'status', 'created_at'], 'shipping_rate_requests_owner_status_idx');
        $this->ensureIndex('shipping_rate_change_requests', ['status', 'created_at'], 'shipping_rate_requests_status_created_idx');

        if (! Schema::hasTable('shipping_rate_versions')) {
            Schema::create('shipping_rate_versions', function (Blueprint $table): void {
                $table->id();
                $table->string('scope', 24)->default('GLOBAL');
                $table->decimal('shipping_fee', 12, 2);
                $table->decimal('rider_pool_percentage', 7, 4);
                $table->decimal('logistics_percentage', 7, 4);
                $table->unsignedSmallInteger('pickup_rider_weight')->default(8);
                $table->unsignedSmallInteger('hub_to_hub_rider_weight')->default(7);
                $table->unsignedSmallInteger('last_mile_rider_weight')->default(15);
                $table->dateTime('effective_from');
                $table->timestamp('effective_until')->nullable();
                $table->string('status', 24)->default('ACTIVE');
                $table->unsignedTinyInteger('active_global_slot')->nullable()
                    ->virtualAs("CASE WHEN scope = 'GLOBAL' AND status = 'ACTIVE' THEN 1 ELSE NULL END");
                $table->foreignId('approved_by_admin_user_id');
                $table->foreignId('source_request_id')->nullable();
                $table->timestamps();
            });
        }

        $this->ensureForeignKey('shipping_rate_versions', 'approved_by_admin_user_id', 'srv_approved_admin_fk', 'users');
        $this->ensureForeignKey('shipping_rate_versions', 'source_request_id', 'srv_source_request_fk', 'shipping_rate_change_requests');
        $this->ensureIndex('shipping_rate_versions', ['source_request_id'], 'shipping_rate_versions_source_request_unique', true);
        $this->ensureIndex('shipping_rate_versions', ['scope', 'status', 'effective_from'], 'shipping_rate_versions_effective_idx');
        $this->ensureIndex('shipping_rate_versions', ['scope', 'effective_until'], 'shipping_rate_versions_until_idx');
        $this->ensureIndex('shipping_rate_versions', ['active_global_slot'], 'shipping_rate_one_active_global_unique', true);

        if (! Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->foreignId('shipping_rate_version_id')->nullable()->after('shipping_fee');
            });
        }

        $this->ensureIndex('seller_orders', ['shipping_rate_version_id'], 'seller_orders_shipping_rate_version_idx');
        $this->ensureForeignKey('seller_orders', 'shipping_rate_version_id', 'seller_orders_shipping_rate_version_fk', 'shipping_rate_versions');
    }

    public function down(): void
    {
        foreach (['shipping_rate_change_requests', 'shipping_rate_versions'] as $tableName) {
            if (Schema::hasTable($tableName) && DB::table($tableName)->exists()) {
                throw new RuntimeException('Shipping rate history exists; this migration cannot be rolled back without deleting audit history.');
            }
        }

        if (Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->dropForeign('seller_orders_shipping_rate_version_fk');
                $table->dropIndex('seller_orders_shipping_rate_version_idx');
                $table->dropColumn('shipping_rate_version_id');
            });
        }

        Schema::dropIfExists('shipping_rate_versions');
        Schema::dropIfExists('shipping_rate_change_requests');
    }

    private function ensureForeignKey(string $tableName, string $column, string $name, string $referencedTable): void
    {
        $exists = collect(Schema::getForeignKeys($tableName))->contains(
            fn (array $foreignKey): bool => $foreignKey['columns'] === [$column]
                && $foreignKey['foreign_table'] === $referencedTable,
        );

        if ($exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $name, $referencedTable): void {
            $table->foreign($column, $name)->references('id')->on($referencedTable)->restrictOnDelete();
        });
    }

    private function ensureIndex(string $tableName, array $columns, string $name, bool $unique = false): void
    {
        $exists = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index): bool => $index['columns'] === $columns && (bool) $index['unique'] === $unique,
        );

        if ($exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $name, $unique): void {
            $unique ? $table->unique($columns, $name) : $table->index($columns, $name);
        });
    }
};
