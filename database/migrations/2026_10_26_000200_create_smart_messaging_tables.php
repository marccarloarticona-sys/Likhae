<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messaging_faqs')) {
            Schema::create('messaging_faqs', function (Blueprint $table): void {
                $table->id();
                $table->string('owner_type', 16);
                $table->foreignId('seller_profile_id')->nullable()->constrained('seller_profiles')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('category', 60);
                $table->string('question', 300);
                $table->text('answer');
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->unsignedInteger('selection_count')->default(0);
                $table->timestamp('last_selected_at')->nullable();
                $table->timestamps();

                $table->index(['owner_type', 'seller_profile_id', 'is_active', 'sort_order'], 'messaging_faq_owner_active_order_idx');
                $table->index(['product_id', 'product_variant_id', 'is_active'], 'messaging_faq_context_idx');
            });
        }

        if (! Schema::hasTable('conversation_support_sessions')) {
            Schema::create('conversation_support_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
                $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status', 36)->default('AUTOMATED_HELP');
                $table->foreignId('selected_faq_id')->nullable()->constrained('messaging_faqs')->nullOnDelete();
                $table->string('selected_question_key', 100)->nullable();
                $table->string('selected_question_snapshot', 300)->nullable();
                $table->text('answer_snapshot')->nullable();
                $table->string('answer_source_snapshot', 100)->nullable();
                $table->string('last_feedback', 20)->nullable();
                $table->json('context_snapshot')->nullable();
                $table->timestamp('selected_at')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['status', 'requested_at'], 'conversation_support_status_requested_idx');
                $table->index(['recipient_user_id', 'status'], 'conversation_support_recipient_status_idx');
            });
        }

        if (Schema::hasTable('conversation_support_sessions')) {
            Schema::table('conversation_support_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('conversation_support_sessions', 'answer_snapshot')) {
                    $table->text('answer_snapshot')->nullable();
                }
                if (! Schema::hasColumn('conversation_support_sessions', 'answer_source_snapshot')) {
                    $table->string('answer_source_snapshot', 100)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_support_sessions');
        Schema::dropIfExists('messaging_faqs');
    }
};
