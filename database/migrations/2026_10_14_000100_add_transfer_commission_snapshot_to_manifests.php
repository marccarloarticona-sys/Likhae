<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_manifests', function (Blueprint $table): void {
            $table->string('transfer_commission_type', 20)->nullable()->after('status');
            $table->decimal('transfer_commission_value', 12, 4)->nullable()->after('transfer_commission_type');
            $table->decimal('transfer_commission_base', 12, 2)->nullable()->after('transfer_commission_value');
            $table->decimal('transfer_commission_amount', 12, 2)->nullable()->after('transfer_commission_base');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_manifests', function (Blueprint $table): void {
            $table->dropColumn([
                'transfer_commission_type',
                'transfer_commission_value',
                'transfer_commission_base',
                'transfer_commission_amount',
            ]);
        });
    }
};
