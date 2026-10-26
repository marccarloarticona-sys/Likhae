<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversation_support_requests')) {
            Schema::create('conversation_support_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('seller_profile_id')->nullable()->constrained('seller_profiles')->nullOnDelete();
                $table->foreignId('selected_faq_id')->nullable()->constrained('messaging_faqs')->nullOnDelete();
                $table->string('category', 60)->default('Other concerns');
                $table->string('selected_question', 300)->nullable();
                $table->text('answer_snapshot')->nullable();
                $table->string('answer_source', 100)->nullable();
                $table->string('last_feedback', 20)->nullable();
                $table->text('unresolved_concern')->nullable();
                $table->string('order_reference', 100)->nullable();
                $table->json('context_snapshot')->nullable();
                $table->string('status', 24)->default('NEW');
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('accepted_at')->nullable();
                $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['recipient_user_id', 'status', 'requested_at'], 'support_requests_recipient_status_idx');
                $table->index(['seller_profile_id', 'status', 'requested_at'], 'support_requests_seller_status_idx');
                $table->index(['conversation_id', 'status'], 'support_requests_conversation_status_idx');
                $table->index(['order_reference', 'status'], 'support_requests_reference_status_idx');
            });
        }

        if (! Schema::hasTable('conversation_support_interactions')) {
            Schema::create('conversation_support_interactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
                $table->foreignId('conversation_support_session_id')->constrained('conversation_support_sessions')->cascadeOnDelete();
                $table->foreignId('support_request_id')->nullable()->constrained('conversation_support_requests')->nullOnDelete();
                $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('selected_faq_id')->nullable()->constrained('messaging_faqs')->nullOnDelete();
                $table->string('question_key', 100)->nullable();
                $table->string('category', 60)->default('Other concerns');
                $table->string('question_snapshot', 300);
                $table->text('answer_snapshot');
                $table->string('answer_source', 100)->nullable();
                $table->string('feedback', 20)->nullable();
                $table->json('context_snapshot')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'created_at'], 'support_interactions_conversation_time_idx');
                $table->index(['support_request_id', 'created_at'], 'support_interactions_request_time_idx');
                $table->index(['selected_faq_id', 'feedback'], 'support_interactions_faq_feedback_idx');
            });
        }

        if (Schema::hasTable('conversation_support_sessions')) {
            Schema::table('conversation_support_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('conversation_support_sessions', 'selected_category_snapshot')) {
                    $table->string('selected_category_snapshot', 60)->nullable();
                }
                if (! Schema::hasColumn('conversation_support_sessions', 'current_request_id')) {
                    $table->foreignId('current_request_id')->nullable()->constrained('conversation_support_requests')->nullOnDelete();
                }
            });
        }

        $this->backfillExistingRequests();
    }

    private function backfillExistingRequests(): void
    {
        if (! Schema::hasTable('conversation_support_sessions') || ! Schema::hasTable('conversation_support_requests')) {
            return;
        }

        DB::table('conversation_support_sessions')
            ->whereNotNull('requested_at')
            ->whereIn('status', ['HUMAN_ASSISTANCE_REQUESTED', 'HUMAN_CONVERSATION', 'RESOLVED'])
            ->orderBy('id')
            ->chunkById(100, function ($sessions): void {
                foreach ($sessions as $session) {
                    if (! empty($session->current_request_id)) {
                        continue;
                    }

                    $context = json_decode((string) ($session->context_snapshot ?? '{}'), true) ?: [];
                    $sellerProfileId = DB::table('seller_profiles')->where('user_id', $session->recipient_user_id)->value('id');
                    $status = match ($session->status) {
                        'HUMAN_CONVERSATION' => 'IN_PROGRESS',
                        'RESOLVED' => 'RESOLVED',
                        default => 'NEW',
                    };
                    $now = $session->updated_at ?: now();
                    $requestId = DB::table('conversation_support_requests')->insertGetId([
                        'conversation_id' => $session->conversation_id,
                        'requester_user_id' => $session->requester_user_id,
                        'recipient_user_id' => $session->recipient_user_id,
                        'seller_profile_id' => $sellerProfileId,
                        'selected_faq_id' => $session->selected_faq_id,
                        'category' => $session->selected_category_snapshot ?: 'Other concerns',
                        'selected_question' => $session->selected_question_snapshot,
                        'answer_snapshot' => $session->answer_snapshot,
                        'answer_source' => $session->answer_source_snapshot,
                        'last_feedback' => $session->last_feedback,
                        'order_reference' => $context['order_number'] ?? null,
                        'context_snapshot' => json_encode($context),
                        'status' => $status,
                        'requested_at' => $session->requested_at,
                        'accepted_at' => $status === 'IN_PROGRESS' ? $now : null,
                        'resolved_at' => $status === 'RESOLVED' ? ($session->resolved_at ?: $now) : null,
                        'resolved_by_user_id' => $session->resolved_by_user_id,
                        'created_at' => $session->created_at ?: $now,
                        'updated_at' => $now,
                    ]);

                    DB::table('conversation_support_sessions')->where('id', $session->id)->update(['current_request_id' => $requestId]);

                    if (filled($session->selected_question_snapshot) && filled($session->answer_snapshot)) {
                        DB::table('conversation_support_interactions')->insert([
                            'conversation_id' => $session->conversation_id,
                            'conversation_support_session_id' => $session->id,
                            'support_request_id' => $requestId,
                            'requester_user_id' => $session->requester_user_id,
                            'selected_faq_id' => $session->selected_faq_id,
                            'question_key' => $session->selected_question_key,
                            'category' => $session->selected_category_snapshot ?: 'Other concerns',
                            'question_snapshot' => $session->selected_question_snapshot,
                            'answer_snapshot' => $session->answer_snapshot,
                            'answer_source' => $session->answer_source_snapshot,
                            'feedback' => $session->last_feedback,
                            'context_snapshot' => json_encode($context),
                            'created_at' => $session->selected_at ?: $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Keep support history on rollback. Removing these tables would erase
        // request and FAQ interaction records collected after this migration.
    }
};
