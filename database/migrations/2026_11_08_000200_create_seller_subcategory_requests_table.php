<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_subcategory_requests')) {
            return;
        }

        Schema::create('seller_subcategory_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_profile_id')->nullable()->constrained('seller_profiles')->nullOnDelete();
            $table->string('seller_business_name_snapshot', 200);
            $table->foreignId('parent_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('parent_category_name_snapshot', 150);
            $table->string('requested_name', 150);
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('resolved_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->longText('resolution')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'subcategory_requests_status_created_idx');
            $table->index(['seller_profile_id', 'created_at'], 'subcategory_requests_seller_created_idx');
            $table->index(['parent_category_id', 'status'], 'subcategory_requests_parent_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_subcategory_requests');
    }
};
