<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_policies', function (Blueprint $table): void {
            $table->string('policy_type', 40)->default('CUSTOM')->after('body');
            $table->string('audience', 20)->default('ALL')->after('policy_type');
            $table->unsignedBigInteger('logistics_center_id')->nullable()->after('audience');
            $table->string('scope_key', 120)->default('PLATFORM:ALL')->after('logistics_center_id');

            $table->dropUnique('platform_policies_title_version_unique');
            $table->unique(
                ['scope_key', 'title', 'version'],
                'platform_policies_scope_title_version_unique',
            );
            $table->index(
                ['scope_key', 'policy_type', 'effective_at'],
                'platform_policies_scope_current_index',
            );
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('api_token_policy_snapshot', 64)
                ->nullable()
                ->after('api_token_expires_at');
        });

        DB::table('platform_policies')
            ->whereRaw("LOWER(title) LIKE '%privacy%' AND LOWER(title) NOT LIKE '%term%'")
            ->update(['policy_type' => 'PRIVACY']);
        DB::table('platform_policies')
            ->whereRaw("LOWER(title) LIKE '%term%' AND LOWER(title) NOT LIKE '%privacy%'")
            ->update(['policy_type' => 'TERMS']);
    }

    public function down(): void
    {
        // Policy scope and acceptance metadata are retained on rollback so a
        // rollback cannot erase published policy history or API token state.
    }
};
