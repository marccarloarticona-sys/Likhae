<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'flash_sale_starts_at')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->timestamp('flash_sale_starts_at')->nullable();
            });
        }
        if (! Schema::hasColumn('products', 'flash_sale_ends_at')) {
            Schema::table('products', fn (Blueprint $table) => $table->timestamp('flash_sale_ends_at')->nullable());
        }

        if (! Schema::hasColumn('vouchers', 'benefit_scope')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->string('benefit_scope', 20)->default('PRODUCT');
            });
        }

        if (! Schema::hasColumn('orders', 'platform_voucher_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('platform_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('orders', 'platform_voucher_discount')) {
            Schema::table('orders', fn (Blueprint $table) => $table->decimal('platform_voucher_discount', 12, 2)->default(0));
        }
        if (! Schema::hasColumn('orders', 'shipping_voucher_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('shipping_voucher_id')->nullable()->constrained('vouchers')->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('orders', 'shipping_voucher_discount')) {
            Schema::table('orders', fn (Blueprint $table) => $table->decimal('shipping_voucher_discount', 12, 2)->default(0));
        }
        if (! Schema::hasColumn('seller_orders', 'shipping_voucher_discount')) {
            Schema::table('seller_orders', fn (Blueprint $table) => $table->decimal('shipping_voucher_discount', 12, 2)->default(0));
        }

        if (! Schema::hasTable('flash_sale_reminders')) {
            Schema::create('flash_sale_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('product_name', 200);
                $table->string('product_slug', 220);
                $table->timestamp('sale_starts_at');
                $table->timestamp('scheduled_at');
                $table->string('status', 20)->default('PENDING');
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique(['buyer_user_id', 'product_id'], 'flash_sale_reminders_buyer_product_unique');
                $table->index(['status', 'scheduled_at'], 'flash_sale_reminders_due_idx');
                $table->index(['product_id', 'status'], 'flash_sale_reminders_product_status_idx');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('This additive Buyer feature migration is retained to preserve voucher and reminder records. Use a reviewed data-preserving rollback if needed.');
    }
};
