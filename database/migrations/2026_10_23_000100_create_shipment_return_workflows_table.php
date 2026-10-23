<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_return_workflows')) {
            return;
        }

        Schema::create('shipment_return_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->foreignId('original_delivery_assignment_id')->constrained('rider_assignments')->restrictOnDelete();
            $table->foreignId('return_rider_profile_id')->nullable()->constrained('rider_profiles')->nullOnDelete();
            $table->string('status', 48)->default('AWAITING_CENTER_HANDOFF');
            $table->text('reason');

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at');

            $table->foreignId('center_handoff_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('center_handoff_at')->nullable();
            $table->foreignId('center_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('center_received_at')->nullable();

            $table->foreignId('return_rider_assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('return_rider_assigned_at')->nullable();
            $table->timestamp('return_rider_accepted_at')->nullable();
            $table->foreignId('return_rider_picked_up_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('return_rider_picked_up_at')->nullable();
            $table->foreignId('seller_handoff_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seller_handoff_at')->nullable();

            $table->foreignId('seller_received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('seller_received_at')->nullable();
            $table->text('seller_confirmation_notes')->nullable();
            $table->timestamps();

            $table->index(['logistics_center_id', 'status', 'updated_at'], 'shipment_returns_center_status_updated_idx');
            $table->index(['return_rider_profile_id', 'status'], 'shipment_returns_rider_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipment_return_workflows')) {
            return;
        }

        if (DB::table('shipment_return_workflows')->exists()) {
            throw new RuntimeException('Shipment return workflow history exists; export or resolve it before rolling back this migration.');
        }

        Schema::drop('shipment_return_workflows');
    }
};
