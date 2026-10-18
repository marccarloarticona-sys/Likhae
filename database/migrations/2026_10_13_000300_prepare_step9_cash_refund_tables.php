<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('return_custody_events')) {
            Schema::create('return_custody_events', function (Blueprint $table): void {
                $table->id();
                // Custody history must survive parent cleanup; never cascade-delete audit records.
                $table->foreignId('return_refund_request_id')->constrained('return_refund_requests')->restrictOnDelete();
                $table->foreignId('seller_return_refund_request_id')->constrained('seller_return_refund_requests')->restrictOnDelete();
                $table->string('event_type', 50);
                $table->string('from_party', 40)->nullable();
                $table->string('to_party', 40)->nullable();
                $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
                $table->foreignId('rider_assignment_id')->nullable()->constrained('rider_assignments')->nullOnDelete();
                $table->string('transfer_reference', 120)->nullable();
                $table->string('location_label', 255)->nullable();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('occurred_at');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['seller_return_refund_request_id', 'event_type'], 'return_custody_request_event_idx');
                $table->index(['shipment_id', 'occurred_at'], 'return_custody_shipment_time_idx');
            });
        }

        if (! Schema::hasTable('return_inspections')) {
            Schema::create('return_inspections', function (Blueprint $table): void {
                $table->id();
                // Inspection history is financial/operational evidence and must remain retained.
                $table->foreignId('seller_return_refund_request_id')->constrained('seller_return_refund_requests')->restrictOnDelete();
                $table->foreignId('return_refund_request_item_id')->constrained('return_refund_request_items')->restrictOnDelete();
                $table->foreignId('inspector_user_id')->constrained('users')->restrictOnDelete();
                $table->string('inspection_status', 30)->default('COMPLETED');
                $table->string('received_condition', 80)->nullable();
                $table->string('completeness_status', 80)->nullable();
                $table->text('damage_notes')->nullable();
                $table->text('missing_accessories')->nullable();
                $table->string('identifier_result', 120)->nullable();
                $table->json('evidence_metadata')->nullable();
                $table->string('inventory_disposition', 30);
                // Use DATETIME for required event times so MySQL does not
                // apply implicit defaults to non-null TIMESTAMP columns.
                $table->dateTime('received_at');
                $table->dateTime('inspected_at');
                $table->text('notes')->nullable();
                $table->timestamp('restocked_at')->nullable();
                $table->foreignId('restocked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['seller_return_refund_request_id', 'inspection_status'], 'return_inspection_request_status_idx');
            });
        }

        if (! Schema::hasTable('cash_refund_obligations')) {
            Schema::create('cash_refund_obligations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('seller_return_refund_request_id')->unique()->constrained('seller_return_refund_requests')->restrictOnDelete();
                $table->foreignId('return_refund_request_id')->constrained('return_refund_requests')->restrictOnDelete();
                $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('seller_order_id')->constrained('seller_orders')->restrictOnDelete();
                $table->decimal('approved_amount', 12, 2);
                $table->decimal('product_refund_amount', 12, 2);
                $table->decimal('shipping_refund_amount', 12, 2)->default(0);
                $table->decimal('protection_refund_amount', 12, 2)->default(0);
                $table->string('shipping_refund_policy', 40)->default('PENDING_OWNER_POLICY');
                $table->string('protection_refund_policy', 40)->default('PENDING_OWNER_POLICY');
                $table->string('state', 30)->default('APPROVED');
                $table->string('idempotency_key', 120)->unique();
                $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('cash_ready_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cash_ready_at')->nullable();
                $table->foreignId('paid_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('paid_at')->nullable();
                $table->text('payment_reference')->nullable();
                $table->char('payment_reference_hash', 64)->nullable()->unique();
                $table->foreignId('buyer_acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('buyer_acknowledged_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['state', 'cash_ready_at'], 'cash_refund_state_ready_idx');
            });
        }
    }

    public function down(): void
    {
        // Additive preparation is intentionally not used for live rollback.
    }
};
