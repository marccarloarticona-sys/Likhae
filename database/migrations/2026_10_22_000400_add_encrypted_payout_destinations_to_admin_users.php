<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = ['payout_method', 'payout_bank_name', 'payout_account_name', 'payout_account_number'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'payout_method')) {
                $table->string('payout_method', 40)->nullable();
            }
            if (! Schema::hasColumn('users', 'payout_bank_name')) {
                $table->string('payout_bank_name', 120)->nullable();
            }
            if (! Schema::hasColumn('users', 'payout_account_name')) {
                $table->text('payout_account_name')->nullable();
            }
            if (! Schema::hasColumn('users', 'payout_account_number')) {
                $table->text('payout_account_number')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = collect(self::COLUMNS)
            ->filter(fn (string $column): bool => Schema::hasColumn('users', $column))
            ->values()
            ->all();

        foreach ($columns as $column) {
            if (DB::table('users')->whereNotNull($column)->exists()) {
                throw new RuntimeException('Admin payout data exists in users; export or clear it before rolling back this migration.');
            }
        }

        if ($columns !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
