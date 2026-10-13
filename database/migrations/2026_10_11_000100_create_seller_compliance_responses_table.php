<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_compliance_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_compliance_case_id')
                ->constrained('seller_compliance_cases')
                ->cascadeOnDelete();
            $table->foreignId('seller_profile_id')
                ->constrained('seller_profiles')
                ->restrictOnDelete();
            $table->longText('explanation');
            $table->longText('corrective_action');
            $table->string('evidence_path')->nullable();
            $table->string('evidence_original_name')->nullable();
            $table->string('evidence_mime')->nullable();
            $table->unsignedBigInteger('evidence_size')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->index(['seller_compliance_case_id', 'submitted_at'], 'seller_compliance_responses_case_time_idx');
            $table->index(['seller_profile_id', 'submitted_at'], 'seller_compliance_responses_seller_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_compliance_responses');
    }
};
