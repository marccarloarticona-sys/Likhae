<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'visibility')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->string('visibility', 20)->default('PUBLIC')->after('applies_to');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('vouchers', 'visibility')) {
            if (DB::table('vouchers')->where('visibility', 'CODE_ONLY')->exists()) {
                throw new RuntimeException('Cannot remove voucher visibility while code-only vouchers exist.');
            }

            Schema::table('vouchers', function (Blueprint $table): void {
                $table->dropColumn('visibility');
            });
        }
    }
};
