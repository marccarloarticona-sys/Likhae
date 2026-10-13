<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('manifest_number', 100)->unique();
            $table->foreignId('origin_logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->foreignId('destination_logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->string('status', 32)->default('DRAFT');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_driver_rider_profile_id')->nullable()->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('driver_accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('driver_accepted_at')->nullable();
            $table->foreignId('sealed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sealed_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('exception_notes')->nullable();
            $table->timestamps();

            $table->index(['origin_logistics_center_id', 'status'], 'transfer_manifests_origin_status_idx');
            $table->index(['destination_logistics_center_id', 'status'], 'transfer_manifests_destination_status_idx');
            $table->index(['assigned_driver_rider_profile_id', 'status'], 'transfer_manifests_driver_status_idx');
        });

        Schema::create('transfer_manifest_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manifest_id')->constrained('transfer_manifests')->restrictOnDelete();
            $table->foreignId('transfer_leg_id')->constrained('shipment_transfer_legs')->restrictOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('added_at')->nullable();
            $table->string('status', 32)->default('EXPECTED');
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->string('exception_type', 50)->nullable();
            $table->text('exception_notes')->nullable();
            $table->timestamps();

            $table->unique(['manifest_id', 'transfer_leg_id'], 'transfer_manifest_items_manifest_leg_uq');
            $table->index(['transfer_leg_id', 'status'], 'transfer_manifest_items_leg_status_idx');
            $table->index(['manifest_id', 'status'], 'transfer_manifest_items_manifest_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_manifest_items');
        Schema::dropIfExists('transfer_manifests');
    }
};
