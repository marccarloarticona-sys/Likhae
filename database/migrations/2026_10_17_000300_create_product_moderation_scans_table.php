<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_moderation_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('seller_profile_id')->nullable()->constrained('seller_profiles')->nullOnDelete();
            $table->foreignId('seller_compliance_case_id')->nullable()->constrained('seller_compliance_cases')->nullOnDelete();
            $table->foreignId('requested_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->char('revision_hash', 64);
            $table->string('policy_version', 40);
            $table->json('model_versions')->nullable();
            $table->boolean('is_automatic')->default(true);
            $table->string('scan_status', 32)->default('QUEUED');
            $table->string('overall_risk', 16)->nullable();
            $table->string('text_risk', 16)->nullable();
            $table->string('image_risk', 16)->nullable();
            $table->json('detected_categories')->nullable();
            $table->json('text_findings')->nullable();
            $table->json('image_findings')->nullable();
            $table->json('object_detections')->nullable();
            $table->json('model_results')->nullable();
            $table->text('evidence_summary')->nullable();
            $table->string('recommended_action', 40)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('scanned_at')->nullable();

            $table->string('listing_status_before_hold', 24)->nullable();
            $table->timestamp('held_at')->nullable();
            $table->string('decision_status', 48)->default('PENDING');
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            // One appeal belongs to one scan. A later scan can have a separate appeal.
            $table->string('appeal_status', 32)->default('NOT_APPEALED');
            $table->string('appeal_reason', 120)->nullable();
            $table->longText('appeal_explanation')->nullable();
            $table->string('appeal_evidence_path')->nullable();
            $table->string('appeal_evidence_original_name')->nullable();
            $table->string('appeal_evidence_mime', 120)->nullable();
            $table->unsignedBigInteger('appeal_evidence_size')->nullable();
            $table->timestamp('appeal_submitted_at')->nullable();
            $table->foreignId('appeal_decided_by_admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('appeal_decision_reason')->nullable();
            $table->timestamp('appeal_decided_at')->nullable();

            $table->timestamps();

            $table->index(['product_id', 'created_at'], 'product_mod_scans_product_time_idx');
            $table->index(['product_id', 'revision_hash'], 'product_mod_scans_product_revision_idx');
            $table->index(['scan_status', 'created_at'], 'product_mod_scans_status_time_idx');
            $table->index(['overall_risk', 'decision_status', 'created_at'], 'product_mod_scans_risk_decision_idx');
            $table->index(['seller_profile_id', 'appeal_status', 'appeal_submitted_at'], 'product_mod_scans_seller_appeal_idx');
            $table->index('seller_compliance_case_id', 'product_mod_scans_case_idx');
            $table->index(['appeal_status', 'appeal_submitted_at'], 'product_mod_scans_appeal_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_moderation_scans');
    }
};
