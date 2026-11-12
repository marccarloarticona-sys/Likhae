<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['addresses', 'order_addresses'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'coordinate_precision')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('coordinate_precision', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep saved exact/approximate classifications when rolling back code.
    }
};
