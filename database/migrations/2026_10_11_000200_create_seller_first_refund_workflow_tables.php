<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('return_refund_requests') && Schema::hasColumn('return_refund_requests', 'dispute_id')) {
            Schema::table('return_refund_requests', function (Blueprint $table): void {
                $table->foreignId('dispute_id')->nullable()->change();
            });
        }

        if (Schema::hasTable('return_refund_requests')) {
            Schema::table('return_refund_requests', function (Blueprint $table): void {
                if (! Schema::hasColumn('return_refund_requests', 'gcash_recipient_name')) {
                    $table->text('gcash_recipient_name')->nullable();
                }
                if (! Schema::hasColumn('return_refund_requests', 'gcash_mobile')) {
                    $table->text('gcash_mobile')->nullable();
                }
                if (! Schema::hasColumn('return_refund_requests', 'gcash_mobile_last4')) {
                    $table->string('gcash_mobile_last4', 4)->nullable();
                }
                if (! Schema::hasColumn('return_refund_requests', 'gcash_details_confirmed_at')) {
                    $table->timestamp('gcash_details_confirmed_at')->nullable();
                }
                if (! Schema::hasColumn('return_refund_requests', 'gcash_details_confirmed_by_user_id')) {
                    $table->unsignedBigInteger('gcash_details_confirmed_by_user_id')->nullable();
                }
            });

            if (Schema::hasColumn('return_refund_requests', 'gcash_details_confirmed_by_user_id')
                && ! collect(Schema::getForeignKeys('return_refund_requests'))->contains(fn (array $foreign): bool => $foreign['name'] === 'rr_req_gcash_confirmer_fk')) {
                Schema::table('return_refund_requests', function (Blueprint $table): void {
                    $table->foreign('gcash_details_confirmed_by_user_id', 'rr_req_gcash_confirmer_fk')
                        ->references('id')->on('users')->nullOnDelete();
                });
            }
        }

        if (! Schema::hasTable('seller_return_refund_requests')) {
            Schema::create('seller_return_refund_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('return_refund_request_id');
                $table->unsignedBigInteger('seller_order_id');
                $table->unsignedBigInteger('seller_profile_id');
                $table->string('status', 40)->default('SELLER_REVIEW');
                $table->decimal('requested_amount', 12, 2);
                $table->decimal('approved_amount', 12, 2)->nullable();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->text('escalation_reason')->nullable();
                $table->text('escalation_description')->nullable();
                $table->unsignedBigInteger('escalated_by_user_id')->nullable();
                $table->timestamp('escalated_at')->nullable();
                $table->unsignedBigInteger('dispute_id')->nullable()->unique();
                $table->timestamps();

                $table->foreign('return_refund_request_id', 'srr_parent_fk')->references('id')->on('return_refund_requests')->cascadeOnDelete();
                $table->foreign('seller_order_id', 'srr_order_fk')->references('id')->on('seller_orders')->restrictOnDelete();
                $table->foreign('seller_profile_id', 'srr_profile_fk')->references('id')->on('seller_profiles')->restrictOnDelete();
                $table->foreign('reviewed_by_user_id', 'srr_reviewer_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('escalated_by_user_id', 'srr_escalator_fk')->references('id')->on('users')->nullOnDelete();
                $table->foreign('dispute_id', 'srr_dispute_fk')->references('id')->on('disputes')->nullOnDelete();

                $table->unique(['return_refund_request_id', 'seller_order_id'], 'seller_rr_request_owner_unique');
                $table->index(['seller_profile_id', 'status']);
                $table->index(['seller_order_id', 'status']);
            });
        }

        if (Schema::hasTable('return_refund_request_items') && ! Schema::hasColumn('return_refund_request_items', 'seller_return_refund_request_id')) {
            Schema::table('return_refund_request_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('seller_return_refund_request_id')->nullable();
                $table->foreign('seller_return_refund_request_id', 'rr_item_seller_fk')
                    ->references('id')->on('seller_return_refund_requests')->nullOnDelete();
                $table->index(['seller_return_refund_request_id', 'order_item_id'], 'seller_rr_item_lookup_idx');
            });
        } elseif (Schema::hasTable('return_refund_request_items') && Schema::hasColumn('return_refund_request_items', 'seller_return_refund_request_id')
            && ! collect(Schema::getForeignKeys('return_refund_request_items'))->contains(fn (array $foreign): bool => $foreign['name'] === 'rr_item_seller_fk')) {
            Schema::table('return_refund_request_items', function (Blueprint $table): void {
                $table->foreign('seller_return_refund_request_id', 'rr_item_seller_fk')
                    ->references('id')->on('seller_return_refund_requests')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('return_refund_evidence')) {
            Schema::create('return_refund_evidence', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('return_refund_request_id');
                $table->unsignedBigInteger('seller_return_refund_request_id')->nullable();
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->string('disk', 50)->default('local');
                $table->string('file_path', 500);
                $table->string('original_name', 255)->nullable();
                $table->string('mime_type', 100)->nullable();
                $table->string('evidence_type', 40)->default('BUYER_EVIDENCE');
                $table->boolean('encrypted')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('return_refund_request_id', 'rr_evidence_request_fk')->references('id')->on('return_refund_requests')->cascadeOnDelete();
                $table->foreign('seller_return_refund_request_id', 'rr_evidence_seller_fk')->references('id')->on('seller_return_refund_requests')->nullOnDelete();
                $table->foreign('uploaded_by_user_id', 'rr_evidence_uploader_fk')->references('id')->on('users')->nullOnDelete();

                $table->index(['return_refund_request_id', 'evidence_type'], 'rr_evidence_request_type_idx');
            });
        }

        if (! Schema::hasTable('refund_payouts')) {
            Schema::create('refund_payouts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('seller_return_refund_request_id');
                $table->unsignedBigInteger('submitted_by_user_id');
                $table->decimal('amount_sent', 12, 2);
                $table->text('transaction_reference');
                $table->char('transaction_reference_hash', 64);
                $table->timestamp('sent_at');
                $table->string('proof_disk', 50)->default('local');
                $table->string('proof_path', 500);
                $table->string('proof_original_name', 255)->nullable();
                $table->string('proof_mime', 100)->nullable();
                $table->text('seller_note')->nullable();
                $table->string('payment_report_state', 40)->default('REPORTED_SENT');
                $table->timestamps();

                $table->foreign('seller_return_refund_request_id', 'refund_payout_seller_fk')->references('id')->on('seller_return_refund_requests')->restrictOnDelete();
                $table->foreign('submitted_by_user_id', 'refund_payout_submitter_fk')->references('id')->on('users')->restrictOnDelete();

                $table->unique(['seller_return_refund_request_id', 'transaction_reference_hash'], 'refund_payout_reference_unique');
                $table->index(['seller_return_refund_request_id', 'payment_report_state'], 'refund_payout_state_idx');
            });
        }

        if (! Schema::hasTable('return_refund_events')) {
            Schema::create('return_refund_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('return_refund_request_id');
                $table->unsignedBigInteger('seller_return_refund_request_id')->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('event_type', 80);
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->text('details')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('return_refund_request_id', 'rr_event_request_fk')->references('id')->on('return_refund_requests')->cascadeOnDelete();
                $table->foreign('seller_return_refund_request_id', 'rr_event_seller_fk')->references('id')->on('seller_return_refund_requests')->nullOnDelete();
                $table->foreign('actor_user_id', 'rr_event_actor_fk')->references('id')->on('users')->nullOnDelete();

                $table->index(['return_refund_request_id', 'created_at'], 'rr_events_request_time_idx');
                $table->index(['seller_return_refund_request_id', 'created_at'], 'rr_events_seller_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_refund_events');
        Schema::dropIfExists('refund_payouts');
        Schema::dropIfExists('return_refund_evidence');

        if (Schema::hasTable('return_refund_request_items') && Schema::hasColumn('return_refund_request_items', 'seller_return_refund_request_id')) {
            Schema::table('return_refund_request_items', function (Blueprint $table): void {
                $table->dropForeign(['seller_return_refund_request_id']);
                $table->dropIndex('seller_rr_item_lookup_idx');
                $table->dropColumn('seller_return_refund_request_id');
            });
        }

        Schema::dropIfExists('seller_return_refund_requests');

        if (Schema::hasTable('return_refund_requests')) {
            Schema::table('return_refund_requests', function (Blueprint $table): void {
                $table->dropForeign('rr_req_gcash_confirmer_fk');
                $table->dropColumn([
                    'gcash_recipient_name',
                    'gcash_mobile',
                    'gcash_mobile_last4',
                    'gcash_details_confirmed_at',
                    'gcash_details_confirmed_by_user_id',
                ]);
                $table->foreignId('dispute_id')->nullable(false)->change();
            });
        }
    }
};
