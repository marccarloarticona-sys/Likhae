<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('order_items', 'original_unit_price')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->decimal('original_unit_price', 12, 2)->nullable()->after('unit_price');
            });
        }

        $this->createTableIfMissing('cod_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('rider_profile_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->nullable()->constrained('logistics_centers')->restrictOnDelete();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('collected_amount', 12, 2)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->string('collection_reference', 100)->nullable()->unique();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('evidence_path', 500)->nullable();
            $table->timestamps();

            $table->index(['rider_profile_id', 'status']);
            $table->index(['logistics_center_id', 'status']);
            $table->index(['seller_order_id', 'status']);
        });

        $this->createTableIfMissing('rider_remittances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->string('remittance_reference', 100)->unique();
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('submitted_amount', 12, 2)->nullable();
            $table->decimal('verified_amount', 12, 2)->nullable();
            $table->decimal('discrepancy_amount', 12, 2)->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['rider_profile_id', 'status']);
            $table->index(['logistics_center_id', 'status']);
        });

        $this->createTableIfMissing('rider_remittance_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_remittance_id')->constrained('rider_remittances')->restrictOnDelete();
            $table->foreignId('cod_collection_id')->unique()->constrained('cod_collections')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['rider_remittance_id', 'cod_collection_id'], 'remittance_collection_unique');
        });

        $this->createTableIfMissing('shipment_financial_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('seller_order_id')->unique()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->char('currency', 3)->default('PHP');
            $table->decimal('original_product_amount', 12, 2);
            $table->decimal('product_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('shipping_fee', 12, 2);
            $table->decimal('platform_commission', 12, 2);
            $table->decimal('seller_payable', 12, 2)->nullable();
            $table->decimal('logistics_allocation', 12, 2);
            $table->decimal('rider_allocation', 12, 2);
            $table->string('discount_funding_status', 32)->default('NOT_APPLICABLE');
            $table->string('status', 32)->default('PENDING');
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'calculated_at']);
        });
        Schema::table('shipment_financial_allocations', function (Blueprint $table): void {
            $table->index(['discount_funding_status', 'status'], 'shipment_fin_alloc_discount_status_idx');
        });

        $this->createTableIfMissing('logistics_earning_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_financial_allocation_id')->unique()->constrained('shipment_financial_allocations')->restrictOnDelete();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('unresolved_share_amount', 12, 2);
            $table->string('status', 32)->default('PENDING');
            $table->string('settlement_reference', 100)->nullable()->unique();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('rider_earning_pools', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_financial_allocation_id')->unique()->constrained('shipment_financial_allocations')->restrictOnDelete();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->decimal('total_amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('unresolved_share_amount', 12, 2);
            $table->string('status', 32)->default('PENDING');
            $table->string('settlement_reference', 100)->nullable()->unique();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->createTableIfMissing('rider_earning_pool_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_earning_pool_id')->constrained('rider_earning_pools')->restrictOnDelete();
            $table->foreignId('rider_assignment_id')->nullable()->unique()->constrained('rider_assignments')->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->string('participant_role', 32);
            $table->decimal('share_amount', 12, 2)->nullable();
            $table->string('status', 32)->default('PENDING');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['rider_earning_pool_id', 'rider_profile_id', 'participant_role'], 'rider_pool_participant_unique');
        });

        $this->createTableIfMissing('seller_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_financial_allocation_id')->unique()->constrained('shipment_financial_allocations')->restrictOnDelete();
            $table->foreignId('seller_order_id')->unique()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->restrictOnDelete();
            $table->decimal('original_product_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('platform_commission', 12, 2);
            $table->decimal('seller_payable', 12, 2)->nullable();
            $table->decimal('payout_amount', 12, 2)->default(0);
            $table->decimal('outstanding_balance', 12, 2);
            $table->string('payout_status', 32)->default('PENDING');
            $table->string('settlement_reference', 100)->nullable()->unique();
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['seller_profile_id', 'payout_status']);
        });

        $this->createTableIfMissing('financial_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_financial_allocation_id')->nullable();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('seller_order_id')->nullable()->constrained('seller_orders')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->string('account_type', 32);
            $table->string('entry_type', 64);
            $table->string('direction', 16);
            $table->decimal('amount', 12, 2);
            $table->string('status', 32)->default('PENDING');
            $table->string('idempotency_key', 180)->unique();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('shipment_financial_allocation_id', 'ledger_allocation_fk')
                ->references('id')->on('shipment_financial_allocations')->restrictOnDelete();

            $table->index(['account_type', 'status']);
            $table->index(['entry_type', 'posted_at']);
        });

        $this->createTableIfMissing('finance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->string('auditable_type', 150);
            $table->unsignedBigInteger('auditable_id');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['event_type', 'created_at']);
        });
    }

    private function createTableIfMissing(string $tableName, \Closure $definition): void
    {
        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, $definition);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_events');
        Schema::dropIfExists('financial_ledger_entries');
        Schema::dropIfExists('seller_settlements');
        Schema::dropIfExists('rider_earning_pool_participants');
        Schema::dropIfExists('rider_earning_pools');
        Schema::dropIfExists('logistics_earning_pools');
        Schema::dropIfExists('shipment_financial_allocations');
        Schema::dropIfExists('rider_remittance_items');
        Schema::dropIfExists('rider_remittances');
        Schema::dropIfExists('cod_collections');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('original_unit_price');
        });
    }
};
