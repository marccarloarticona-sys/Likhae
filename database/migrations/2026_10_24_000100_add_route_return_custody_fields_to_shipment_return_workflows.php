<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipment_return_workflows')) {
            return;
        }

        Schema::table('shipment_return_workflows', function (Blueprint $table): void {
            if (! Schema::hasColumn('shipment_return_workflows', 'return_disposition')) {
                $table->string('return_disposition', 32)->default('SELLER_RETURN');
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'pending_return_rider_profile_id')) {
                $table->foreignId('pending_return_rider_profile_id')->nullable()->constrained('rider_profiles', 'id', 'srw_pending_return_rider_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_authorized_by_user_id')) {
                $table->foreignId('handoff_authorized_by_user_id')->nullable()->constrained('users', 'id', 'srw_handoff_authorizer_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_authorized_at')) {
                $table->timestamp('handoff_authorized_at')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_reason')) {
                $table->text('handoff_reason')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_from_confirmed_at')) {
                $table->timestamp('handoff_from_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_to_confirmed_at')) {
                $table->timestamp('handoff_to_confirmed_at')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'handoff_completed_at')) {
                $table->timestamp('handoff_completed_at')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'return_exception_reason')) {
                $table->text('return_exception_reason')->nullable();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'return_exception_recorded_by_user_id')) {
                $table->foreignId('return_exception_recorded_by_user_id')->nullable()->constrained('users', 'id', 'srw_exception_recorder_fk')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipment_return_workflows', 'return_exception_recorded_at')) {
                $table->timestamp('return_exception_recorded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipment_return_workflows')) {
            return;
        }

        if (Schema::hasColumn('shipment_return_workflows', 'return_disposition')
            && DB::table('shipment_return_workflows')->where(function ($query): void {
                $query->where('return_disposition', '<>', 'SELLER_RETURN')
                    ->orWhereNotNull('pending_return_rider_profile_id')
                    ->orWhereNotNull('handoff_authorized_at')
                    ->orWhereNotNull('handoff_reason')
                    ->orWhereNotNull('handoff_from_confirmed_at')
                    ->orWhereNotNull('handoff_to_confirmed_at')
                    ->orWhereNotNull('handoff_completed_at')
                    ->orWhereNotNull('return_exception_reason')
                    ->orWhereNotNull('return_exception_recorded_at');
            })->exists()) {
            throw new RuntimeException('Route return custody history exists; export or resolve it before rolling back this migration.');
        }

        Schema::table('shipment_return_workflows', function (Blueprint $table): void {
            foreach ([
                'pending_return_rider_profile_id' => 'srw_pending_return_rider_fk',
                'handoff_authorized_by_user_id' => 'srw_handoff_authorizer_fk',
                'return_exception_recorded_by_user_id' => 'srw_exception_recorder_fk',
            ] as $column => $constraint) {
                if (Schema::hasColumn('shipment_return_workflows', $column)) {
                    $table->dropForeign($constraint);
                    $table->dropColumn($column);
                }
            }

            $columns = [
                'return_disposition', 'handoff_authorized_at', 'handoff_reason',
                'handoff_from_confirmed_at', 'handoff_to_confirmed_at', 'handoff_completed_at', 'return_exception_reason',
                'return_exception_recorded_at',
            ];
            $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn('shipment_return_workflows', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
