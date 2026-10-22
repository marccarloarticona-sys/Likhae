<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_settlements')) {
            return;
        }

        Schema::table('seller_settlements', function (Blueprint $table): void {
            if (! Schema::hasColumn('seller_settlements', 'payout_proof_path')) {
                $table->text('payout_proof_path')->nullable();
            }
            if (! Schema::hasColumn('seller_settlements', 'payout_proof_name')) {
                $table->string('payout_proof_name', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('seller_settlements')) {
            return;
        }

        foreach (['payout_proof_path', 'payout_proof_name'] as $column) {
            if (Schema::hasColumn('seller_settlements', $column)
                && DB::table('seller_settlements')->whereNotNull($column)->exists()) {
                throw new RuntimeException('Cannot remove populated payout proof records. No schema was removed.');
            }
        }

        Schema::table('seller_settlements', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['payout_proof_path', 'payout_proof_name'],
                fn (string $column): bool => Schema::hasColumn('seller_settlements', $column),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
