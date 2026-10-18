<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_followers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_profile_id')->constrained('seller_profiles')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['buyer_user_id', 'seller_profile_id']);
            $table->index('seller_profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_followers');
    }
};
