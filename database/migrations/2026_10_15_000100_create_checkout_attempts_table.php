<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkout_attempts', function (Blueprint $table): void {
            $table->id();
            // The raw bearer key is never stored. Uniqueness is global so a
            // different buyer cannot reuse a key and resolve another order.
            $table->char('key_hash', 64)->unique();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('request_hash', 64);
            $table->foreignId('order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->timestamps();

            $table->index('buyer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_attempts');
    }
};
