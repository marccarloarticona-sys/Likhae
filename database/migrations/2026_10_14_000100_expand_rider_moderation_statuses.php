<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->enum('status', ['PENDING', 'ACTIVE', 'WARNED', 'SUSPENDED', 'BANNED', 'DEACTIVATED'])
                ->default('PENDING')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('rider_profiles')->where('status', 'WARNED')->update(['status' => 'ACTIVE']);
        DB::table('rider_profiles')->where('status', 'BANNED')->update(['status' => 'DEACTIVATED']);

        Schema::table('rider_profiles', function (Blueprint $table): void {
            $table->enum('status', ['PENDING', 'ACTIVE', 'SUSPENDED', 'DEACTIVATED'])
                ->default('PENDING')
                ->change();
        });
    }
};
