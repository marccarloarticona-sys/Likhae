<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table): void {
            $table->string('platform_code_key', 80)->nullable()->unique();
            $table->unsignedBigInteger('seller_profile_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('vouchers')->whereNull('seller_profile_id')->exists()) {
            throw new RuntimeException('Platform vouchers exist. Remove or reassign them before rolling back this migration.');
        }

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropUnique('vouchers_platform_code_key_unique');
            $table->dropColumn('platform_code_key');
            $table->unsignedBigInteger('seller_profile_id')->nullable(false)->change();
        });
    }
};
