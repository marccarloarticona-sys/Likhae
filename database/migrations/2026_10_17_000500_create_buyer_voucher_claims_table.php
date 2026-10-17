<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_voucher_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            // Platform campaigns also use vouchers; the claim workflow must require seller_profile_id.
            $table->foreignId('voucher_id')->constrained('vouchers')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['buyer_user_id', 'voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_voucher_claims');
    }
};
