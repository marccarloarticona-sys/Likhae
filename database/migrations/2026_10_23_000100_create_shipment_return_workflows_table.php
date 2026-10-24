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
            $foreignKeys = collect(Schema::getForeignKeys('shipment_return_workflows'));
            $existingColumns = $foreignKeys->flatMap(fn (array $foreignKey): array => $foreignKey['columns'])->all();
            $missing = [
                'shipment_id' => ['shipments', 'srw_shipment_fk', 'restrict'],
                'logistics_center_id' => ['logistics_centers', 'srw_center_fk', 'restrict'],
                'original_delivery_assignment_id' => ['rider_assignments', 'srw_original_assignment_fk', 'restrict'],
                'return_rider_profile_id' => ['rider_profiles', 'srw_return_rider_fk', 'null'],
                'initiated_by_user_id' => ['users', 'srw_initiated_by_fk', 'null'],
                'center_handoff_by_user_id' => ['users', 'srw_center_handoff_by_fk', 'null'],
                'center_received_by_user_id' => ['users', 'srw_center_received_by_fk', 'null'],
                'return_rider_assigned_by_user_id' => ['users', 'srw_return_assigned_by_fk', 'null'],
                'return_rider_picked_up_by_user_id' => ['users', 'srw_return_picked_by_fk', 'null'],
                'seller_handoff_by_user_id' => ['users', 'srw_seller_handoff_by_fk', 'null'],
                'seller_received_by_user_id' => ['users', 'srw_seller_received_by_fk', 'null'],
            ];
            foreach ($missing as $column => [$referencedTable]) {
                if (! Schema::hasColumn('shipment_return_workflows', $column)
                    || ! Schema::hasTable($referencedTable)) {
                    throw new RuntimeException('The existing shipment return workflow table is incomplete; repair its columns before rerunning this migration.');
                }
            }
            $missing = array_filter($missing, fn (array $definition, string $column): bool => ! in_array($column, $existingColumns, true), ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                Schema::table('shipment_return_workflows', function (Blueprint $table) use ($missing): void {
                    foreach ($missing as $column => [$referencedTable, $constraint, $deleteAction]) {
                        $foreign = $table->foreign($column, $constraint)
                            ->references('id')
                            ->on($referencedTable);
                        if ($deleteAction === 'null') {
                            $foreign->nullOnDelete();
                        } else {
                            $foreign->restrictOnDelete();
                        }
                    }
                });
            }

            return;
        }

        Schema::create('shipment_return_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments', 'id', 'srw_shipment_fk')->restrictOnDelete();
            $table->foreignId('logistics_center_id')->constrained('logistics_centers', 'id', 'srw_center_fk')->restrictOnDelete();
            $table->foreignId('original_delivery_assignment_id')->constrained('rider_assignments', 'id', 'srw_original_assignment_fk')->restrictOnDelete();
            $table->foreignId('return_rider_profile_id')->nullable()->constrained('rider_profiles', 'id', 'srw_return_rider_fk')->nullOnDelete();
            $table->string('status', 48)->default('AWAITING_CENTER_HANDOFF');
            $table->text('reason');

            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users', 'id', 'srw_initiated_by_fk')->nullOnDelete();
            $table->timestamp('initiated_at');

            $table->foreignId('center_handoff_by_user_id')->nullable()->constrained('users', 'id', 'srw_center_handoff_by_fk')->nullOnDelete();
            $table->timestamp('center_handoff_at')->nullable();
            $table->foreignId('center_received_by_user_id')->nullable()->constrained('users', 'id', 'srw_center_received_by_fk')->nullOnDelete();
            $table->timestamp('center_received_at')->nullable();

            $table->foreignId('return_rider_assigned_by_user_id')->nullable()->constrained('users', 'id', 'srw_return_assigned_by_fk')->nullOnDelete();
            $table->timestamp('return_rider_assigned_at')->nullable();
            $table->timestamp('return_rider_accepted_at')->nullable();
            $table->foreignId('return_rider_picked_up_by_user_id')->nullable()->constrained('users', 'id', 'srw_return_picked_by_fk')->nullOnDelete();
            $table->timestamp('return_rider_picked_up_at')->nullable();
            $table->foreignId('seller_handoff_by_user_id')->nullable()->constrained('users', 'id', 'srw_seller_handoff_by_fk')->nullOnDelete();
            $table->timestamp('seller_handoff_at')->nullable();

            $table->foreignId('seller_received_by_user_id')->nullable()->constrained('users', 'id', 'srw_seller_received_by_fk')->nullOnDelete();
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
