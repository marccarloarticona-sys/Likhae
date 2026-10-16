<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('target_audience', 20)->default('ALL')->after('body');
            $table->index(['target_audience', 'is_active', 'published_at'], 'announcements_audience_publish_index');
        });
    }

    public function down(): void
    {
        if (DB::table('announcements')->where('target_audience', '<>', 'ALL')->exists()) {
            throw new RuntimeException('Targeted announcements exist. Reassign them to ALL before rolling back this migration.');
        }

        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropIndex('announcements_audience_publish_index');
            $table->dropColumn('target_audience');
        });
    }
};
