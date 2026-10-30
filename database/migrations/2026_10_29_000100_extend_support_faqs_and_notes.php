<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messaging_faqs')) {
            Schema::table('messaging_faqs', function (Blueprint $table): void {
                if (! Schema::hasColumn('messaging_faqs', 'target_role')) {
                    $table->string('target_role', 24)->default('ALL');
                }
                if (! Schema::hasColumn('messaging_faqs', 'entry_type')) {
                    $table->string('entry_type', 24)->default('FAQ');
                }
                if (! Schema::hasColumn('messaging_faqs', 'keywords')) {
                    $table->json('keywords')->nullable();
                }
                if (! Schema::hasColumn('messaging_faqs', 'alternate_questions')) {
                    $table->json('alternate_questions')->nullable();
                }
                if (! Schema::hasColumn('messaging_faqs', 'is_automatic')) {
                    $table->boolean('is_automatic')->default(true);
                }
            });
        }

        if (Schema::hasTable('conversation_support_requests') && ! Schema::hasColumn('conversation_support_requests', 'internal_notes')) {
            Schema::table('conversation_support_requests', function (Blueprint $table): void {
                $table->json('internal_notes')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally keep these additive columns on rollback so support data is preserved.
    }
};
