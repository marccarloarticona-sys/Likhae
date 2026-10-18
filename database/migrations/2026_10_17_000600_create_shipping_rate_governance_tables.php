<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_rate_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requesting_logistics_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('current_shipping_fee', 12, 2);
            $table->decimal('requested_shipping_fee', 12, 2);
            $table->string('change_type', 16);
            $table->decimal('change_amount', 12, 2);
            $table->decimal('change_percentage', 9, 4);
            $table->string('reason_code', 48);
            $table->text('reason_details')->nullable();
            $table->timestamp('requested_effective_at')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('reviewed_by_admin_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_decision_reason')->nullable();
            $table->timestamp('approved_effective_at')->nullable();
            $table->timestamps();

            // A stable user-row lock serializes duplicate-pending checks. This
            // index keeps the transactional check selective without relying on
            // MySQL partial indexes or a synthetic nullable uniqueness slot.
            $table->index(
                ['requesting_logistics_user_id', 'status', 'created_at'],
                'shipping_rate_requests_owner_status_idx',
            );
            $table->index(['status', 'created_at'], 'shipping_rate_requests_status_created_idx');
        });

        Schema::create('shipping_rate_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 24)->default('GLOBAL');
            $table->decimal('shipping_fee', 12, 2);
            $table->decimal('rider_pool_percentage', 7, 4);
            $table->decimal('logistics_percentage', 7, 4);
            $table->unsignedSmallInteger('pickup_rider_weight')->default(8);
            $table->unsignedSmallInteger('hub_to_hub_rider_weight')->default(7);
            $table->unsignedSmallInteger('last_mile_rider_weight')->default(15);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->string('status', 24)->default('ACTIVE');
            // NULL for every non-active/non-GLOBAL row; the unique index then
            // enforces one ACTIVE GLOBAL row at the database level as well.
            $table->unsignedTinyInteger('active_global_slot')->nullable()
                ->virtualAs("CASE WHEN scope = 'GLOBAL' AND status = 'ACTIVE' THEN 1 ELSE NULL END");
            $table->foreignId('approved_by_admin_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_request_id')->nullable()->unique()
                ->constrained('shipping_rate_change_requests')->restrictOnDelete();
            $table->timestamps();

            // Rate writes are serialized on a stable Admin user row, then the
            // service closes the previous GLOBAL version inside that same
            // transaction. Scheduling is intentionally not enabled in v1.
            $table->index(['scope', 'status', 'effective_from'], 'shipping_rate_versions_effective_idx');
            $table->index(['scope', 'effective_until'], 'shipping_rate_versions_until_idx');
            $table->unique('active_global_slot', 'shipping_rate_one_active_global_unique');
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->foreignId('shipping_rate_version_id')->nullable()
                ->after('shipping_fee');
            $table->index('shipping_rate_version_id', 'seller_orders_shipping_rate_version_idx');
            $table->foreign('shipping_rate_version_id', 'seller_orders_shipping_rate_version_fk')
                ->references('id')->on('shipping_rate_versions')->restrictOnDelete();
        });
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
};
