<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vouchers')) {
            return;
        }

        $missing = array_values(array_filter(self::COLUMNS, fn (string $column): bool => ! Schema::hasColumn('vouchers', $column)));
        if ($missing === []) {
            return;
        }

        Schema::table('vouchers', function (Blueprint $table) use ($missing): void {
            if (in_array('homepage_carousel_enabled', $missing, true)) {
                $table->boolean('homepage_carousel_enabled')->default(false);
            }
            if (in_array('homepage_carousel_desktop_image_path', $missing, true)) {
                $table->string('homepage_carousel_desktop_image_path')->nullable();
            }
            if (in_array('homepage_carousel_mobile_image_path', $missing, true)) {
                $table->string('homepage_carousel_mobile_image_path')->nullable();
            }
            if (in_array('homepage_carousel_headline', $missing, true)) {
                $table->string('homepage_carousel_headline', 150)->nullable();
            }
            if (in_array('homepage_carousel_description', $missing, true)) {
                $table->string('homepage_carousel_description', 280)->nullable();
            }
            if (in_array('homepage_carousel_cta_label', $missing, true)) {
                $table->string('homepage_carousel_cta_label', 60)->nullable();
            }
            if (in_array('homepage_carousel_cta_destination', $missing, true)) {
                $table->string('homepage_carousel_cta_destination', 20)->default('EVENT');
            }
            if (in_array('homepage_carousel_priority', $missing, true)) {
                $table->unsignedSmallInteger('homepage_carousel_priority')->default(0);
            }
        });
    }

    public function down(): void
    {
        // Keep campaign artwork settings if application code is rolled back.
    }
};
