<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('return_refund_requests')
            || Schema::hasColumn('return_refund_requests', 'refund_destination_snapshot')) {
            return;
        }

        Schema::table('return_refund_requests', function (Blueprint $table): void {
            $table->longText('refund_destination_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        // Retain encrypted destination snapshots attached to refund records.
    }
};
