<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_profile_update_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('vehicle_type', 100);
            $table->string('plate_number', 50);
            $table->string('drivers_license_number', 100);
            $table->string('status', 20)->default('PENDING');
            $table->text('review_notes')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['rider_profile_id', 'status'], 'rider_profile_update_requests_status_idx');
        });

        Schema::create('rider_profile_update_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_update_request_id')
                ->constrained('rider_profile_update_requests', 'id', 'rider_profile_update_documents_request_fk')
                ->restrictOnDelete();
            $table->string('document_type', 100);
            $table->string('file_path', 500);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('verification_status', 20)->default('PENDING');
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['rider_profile_update_request_id', 'document_type'], 'rider_profile_update_documents_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_profile_update_documents');
        Schema::dropIfExists('rider_profile_update_requests');
    }
};
