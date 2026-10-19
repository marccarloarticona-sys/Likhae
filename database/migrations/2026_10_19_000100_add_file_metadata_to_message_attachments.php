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
            $table->string('attachment_original_name', 180)->nullable()->after('attachment_path');
            $table->string('attachment_mime', 128)->nullable()->after('attachment_original_name');
        });
    }

    public function down(): void
    {
        if (DB::table('messages')
            ->whereNotNull('attachment_original_name')
            ->orWhereNotNull('attachment_mime')
            ->exists()) {
            throw new RuntimeException('Message attachment metadata exists. Preserve it before rolling back this migration.');
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['attachment_original_name', 'attachment_mime']);
        });
    }
};
