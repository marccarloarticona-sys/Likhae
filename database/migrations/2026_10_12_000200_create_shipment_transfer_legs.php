<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_transfer_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->unsignedInteger('sequence_no');
            $table->foreignId('origin_logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->foreignId('destination_logistics_center_id')->constrained('logistics_centers')->restrictOnDelete();
            $table->string('origin_province_code', 50);
            $table->string('origin_municipality_code', 50);
            $table->string('destination_province_code', 50);
            $table->string('destination_municipality_code', 50);
            $table->string('status', 32)->default('PLANNED');
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'sequence_no'], 'shipment_transfer_legs_shipment_sequence_uq');
            $table->index(['shipment_id', 'status'], 'shipment_transfer_legs_shipment_status_idx');
            $table->index(['origin_logistics_center_id', 'status'], 'shipment_transfer_legs_origin_status_idx');
            $table->index(['destination_logistics_center_id', 'status'], 'shipment_transfer_legs_destination_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_transfer_legs');
    }
};
