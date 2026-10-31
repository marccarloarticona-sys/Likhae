<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interhub_driver_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->string('region_type', 20);
            $table->decimal('daily_rate', 12, 4);
            $table->date('effective_from');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['rider_profile_id', 'region_type', 'effective_from'], 'interhub_rate_profile_region_date_uq');
            $table->index(['logistics_center_id', 'effective_from'], 'interhub_rates_center_effective_idx');
        });

        Schema::create('interhub_attendance_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('active_driver_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('transfer_manifests')->restrictOnDelete();
            $table->foreignId('rate_id')->nullable()->constrained('interhub_driver_rates')->nullOnDelete();
            $table->string('region_type', 20);
            $table->decimal('daily_rate_snapshot', 12, 4);
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->decimal('gross_pay', 12, 2)->nullable();
            $table->boolean('overtime_review')->default(false);
            $table->foreignId('clock_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('clock_out_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('clock_in_source', 20);
            $table->string('clock_out_source', 20)->nullable();
            $table->timestamps();
            $table->index(['rider_profile_id', 'clock_out_at'], 'interhub_sessions_driver_open_idx');
            $table->index(['logistics_center_id', 'clock_out_at'], 'interhub_sessions_center_period_idx');
            $table->index(['manifest_id', 'clock_in_at'], 'interhub_sessions_manifest_idx');
            $table->unique('active_driver_id', 'interhub_one_open_shift_per_driver_uq');
        });

        Schema::create('interhub_attendance_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained('interhub_attendance_sessions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 32);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['attendance_session_id', 'created_at'], 'interhub_attendance_events_session_idx');
        });

        Schema::create('interhub_attendance_qr_tokens', function (Blueprint $table): void {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->string('issued_by_role', 20);
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->foreignId('manifest_id')->constrained('transfer_manifests')->restrictOnDelete();
            $table->string('action', 12);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['expires_at', 'consumed_at'], 'interhub_attendance_qr_expiry_idx');
        });

        Schema::create('interhub_payroll_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['logistics_center_id', 'period_start', 'period_end'], 'interhub_payroll_period_uq');
        });

        Schema::create('interhub_payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_batch_id')->constrained('interhub_payroll_batches')->restrictOnDelete();
            $table->foreignId('attendance_session_id')->unique()->constrained('interhub_attendance_sessions')->restrictOnDelete();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->decimal('daily_rate_snapshot', 12, 4);
            $table->unsignedInteger('worked_minutes');
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('PENDING');
            $table->string('payment_reference', 160)->nullable();
            $table->string('proof_path', 500)->nullable();
            $table->text('payment_notes')->nullable();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['payroll_batch_id', 'rider_profile_id'], 'interhub_payroll_driver_idx');
            $table->index(['status', 'paid_at'], 'interhub_payroll_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interhub_payroll_items');
        Schema::dropIfExists('interhub_payroll_batches');
        Schema::dropIfExists('interhub_attendance_qr_tokens');
        Schema::dropIfExists('interhub_attendance_events');
        Schema::dropIfExists('interhub_attendance_sessions');
        Schema::dropIfExists('interhub_driver_rates');
    }
};
