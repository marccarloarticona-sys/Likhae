<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'products_seller_submission_token_uq';

    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (! Schema::hasColumn('products', 'submission_token')) {
            Schema::table('products', fn (Blueprint $table) => $table->uuid('submission_token')->nullable());
        }
        if (! Schema::hasColumn('products', 'submission_fingerprint')) {
            Schema::table('products', fn (Blueprint $table) => $table->char('submission_fingerprint', 64)->nullable());
        }

        if (Schema::hasColumn('products', 'seller_profile_id')
            && Schema::hasColumn('products', 'submission_token')
            && ! Schema::hasIndex('products', self::UNIQUE_INDEX)) {
            Schema::table('products', fn (Blueprint $table) => $table->unique(
                ['seller_profile_id', 'submission_token'],
                self::UNIQUE_INDEX,
            ));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::hasIndex('products', self::UNIQUE_INDEX)) {
            Schema::table('products', fn (Blueprint $table) => $table->dropUnique(self::UNIQUE_INDEX));
        }

        $columns = array_values(array_filter(
            ['submission_token', 'submission_fingerprint'],
            fn (string $column): bool => Schema::hasColumn('products', $column),
        ));
        if ($columns !== []) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
