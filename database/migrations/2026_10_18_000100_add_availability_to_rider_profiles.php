<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->boolean('is_online')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->dropColumn('is_online');
        });
    }
};
