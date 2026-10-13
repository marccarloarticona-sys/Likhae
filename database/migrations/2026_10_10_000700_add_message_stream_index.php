<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // Incremental message polling filters by conversation and id.
            $table->index(['conversation_id', 'id'], 'messages_conversation_id_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_conversation_id_id_index');
        });
    }
};
