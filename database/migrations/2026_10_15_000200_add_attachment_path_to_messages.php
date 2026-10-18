<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        if (DB::table('messages')->whereNotNull('attachment_path')->exists()) {
            throw new RuntimeException('Message attachments exist. Preserve them before rolling back this migration.');
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('attachment_path');
        });
    }
};
