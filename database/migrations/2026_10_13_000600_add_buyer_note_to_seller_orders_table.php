<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_orders') && ! Schema::hasColumn('seller_orders', 'buyer_note')) {
            Schema::table('seller_orders', function (Blueprint $table): void {
                $table->text('buyer_note')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Buyer notes are retained as order history if this migration is rolled back.
    }
};
