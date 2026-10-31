<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interhub_attendance_sessions', function (Blueprint $table): void {
            $table->dropForeign(['manifest_id']);
            $table->foreignId('manifest_id')->nullable()->change();
            $table->foreign('manifest_id')->references('id')->on('transfer_manifests')->restrictOnDelete();
        });

        Schema::table('interhub_attendance_qr_tokens', function (Blueprint $table): void {
            $table->dropForeign(['rider_profile_id']);
            $table->foreignId('rider_profile_id')->nullable()->change();
            $table->foreign('rider_profile_id')->references('id')->on('rider_profiles')->restrictOnDelete();
            $table->dropForeign(['manifest_id']);
            $table->foreignId('manifest_id')->nullable()->change();
            $table->foreign('manifest_id')->references('id')->on('transfer_manifests')->restrictOnDelete();
        });

        Schema::create('interhub_attendance_qr_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_token_id')->constrained('interhub_attendance_qr_tokens')->cascadeOnDelete();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('attendance_session_id')->nullable()->constrained('interhub_attendance_sessions')->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->unique(['qr_token_id', 'rider_profile_id'], 'interhub_qr_token_driver_once_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interhub_attendance_qr_uses');

        // Keep manifest and driver links nullable so rollback cannot destroy
        // timekeeping entries created without a transfer assignment.
    }
};
