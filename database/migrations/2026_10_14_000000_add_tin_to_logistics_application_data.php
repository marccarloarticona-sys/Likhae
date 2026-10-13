<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_application_data', function (Blueprint $table): void {
            $table->string('tin', 15)->nullable()->after('dti_registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('logistics_application_data', function (Blueprint $table): void {
            $table->dropColumn('tin');
        });
    }
};
