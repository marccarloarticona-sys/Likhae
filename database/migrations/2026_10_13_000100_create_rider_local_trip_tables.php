<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_local_trips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_profile_id')->constrained('rider_profiles')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->string('trip_type', 16);
            $table->string('province_code', 50);
            $table->string('municipality_code', 50);
            $table->string('barangay_code', 50);
            $table->unsignedInteger('approved_parcel_capacity');
            $table->string('status', 32)->default('LOADING');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('exception_notes')->nullable();
            $table->timestamps();

            $table->index(['rider_profile_id', 'status'], 'rider_local_trips_rider_status_idx');
            $table->index(['logistics_center_id', 'status'], 'rider_local_trips_center_status_idx');
            $table->index(['province_code', 'municipality_code', 'barangay_code', 'status'], 'rider_local_trips_psgc_status_idx');
        });

        Schema::create('rider_local_trip_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rider_local_trip_id')->constrained('rider_local_trips')->restrictOnDelete();
            $table->foreignId('rider_assignment_id')->constrained('rider_assignments')->restrictOnDelete();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('loaded_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 32)->default('QUEUED');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['rider_local_trip_id', 'rider_assignment_id'], 'rider_local_trip_items_trip_assignment_uq');
            $table->index(['rider_local_trip_id', 'status'], 'rider_local_trip_items_trip_status_idx');
            $table->index(['rider_assignment_id', 'status'], 'rider_local_trip_items_assignment_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_local_trip_items');
        Schema::dropIfExists('rider_local_trips');
    }
};
