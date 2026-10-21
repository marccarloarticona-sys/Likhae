<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('theme_color', 7)->nullable();
            $table->string('cta_label', 80)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->boolean('show_popup')->default(true);
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('theme_color', 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'image_alt',
                'theme_color',
                'cta_label',
                'cta_url',
                'show_popup',
            ]);
        });

        Schema::table('vouchers', function (Blueprint $table): void {
            $table->dropColumn(['image_path', 'image_alt', 'theme_color']);
        });
    }
};
