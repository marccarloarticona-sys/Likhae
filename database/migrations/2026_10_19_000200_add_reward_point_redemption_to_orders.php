<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('reward_points_redeemed')->default(0);
            $table->decimal('reward_points_discount', 12, 2)->default(0);
        });

        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->unsignedInteger('reward_points_redeemed')->default(0);
            $table->decimal('reward_points_discount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('seller_orders', function (Blueprint $table): void {
            $table->dropColumn(['reward_points_redeemed', 'reward_points_discount']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['reward_points_redeemed', 'reward_points_discount']);
        });
    }
};
