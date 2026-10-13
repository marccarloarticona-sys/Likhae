<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fragile_protection_rules')) {
            Schema::create('fragile_protection_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
                $table->foreignId('subcategory_id')->nullable()->constrained('categories')->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('risk_level', 32)->nullable();
                $table->string('calculation_type', 20);
                $table->string('scope', 20)->nullable();
                $table->decimal('percentage_rate', 8, 4)->nullable();
                $table->decimal('flat_amount', 12, 2)->nullable();
                $table->decimal('minimum_fee', 12, 2)->nullable();
                $table->decimal('maximum_fee', 12, 2)->nullable();
                $table->decimal('minimum_product_price', 12, 2)->nullable();
                $table->decimal('maximum_product_price', 12, 2)->nullable();
                $table->boolean('active')->default(false);
                $table->timestamp('effective_from')->nullable();
                $table->timestamp('effective_until')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['product_id', 'active'], 'fragile_rules_product_active_idx');
                $table->index(['subcategory_id', 'active'], 'fragile_rules_subcategory_active_idx');
                $table->index(['category_id', 'active'], 'fragile_rules_category_active_idx');
            });
        }

        if (! Schema::hasTable('protection_selection_audits')) {
            Schema::create('protection_selection_audits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
                $table->foreignId('protection_rule_id')->nullable()->constrained('fragile_protection_rules')->nullOnDelete();
                $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
                $table->boolean('selected')->default(false);
                $table->decimal('amount', 12, 2)->default(0);
                $table->boolean('handling_flag')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamp('selected_at');
                $table->timestamps();

                $table->index(['order_id', 'selected_at'], 'protection_audit_order_time_idx');
            });
        }

        $this->addColumn('order_items', 'protection_selected', fn (Blueprint $table) => $table->boolean('protection_selected')->default(false));
        $this->addColumn('order_items', 'protection_fee', fn (Blueprint $table) => $table->decimal('protection_fee', 12, 2)->default(0));
        $this->addColumn('order_items', 'protection_rule_id', fn (Blueprint $table) => $table->unsignedBigInteger('protection_rule_id')->nullable());
        $this->addColumn('order_items', 'protection_scope', fn (Blueprint $table) => $table->string('protection_scope', 20)->nullable());
        $this->addColumn('order_items', 'protection_rule_snapshot', fn (Blueprint $table) => $table->json('protection_rule_snapshot')->nullable());
        $this->addColumn('order_items', 'protection_handling_flag', fn (Blueprint $table) => $table->boolean('protection_handling_flag')->default(false));
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'protection_rule_id')
            && ! collect(Schema::getForeignKeys('order_items'))->contains(fn (array $foreign): bool => $foreign['name'] === 'order_items_protection_rule_fk')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->foreign('protection_rule_id', 'order_items_protection_rule_fk')->references('id')->on('fragile_protection_rules')->nullOnDelete());
        }

        $this->addColumn('orders', 'protection_total', fn (Blueprint $table) => $table->decimal('protection_total', 12, 2)->default(0));
        $this->addColumn('seller_orders', 'protection_total', fn (Blueprint $table) => $table->decimal('protection_total', 12, 2)->default(0));
        $this->addColumn('shipment_financial_allocations', 'protection_amount', fn (Blueprint $table) => $table->decimal('protection_amount', 12, 2)->default(0));
        $this->addColumn('shipment_financial_allocations', 'protection_account_status', fn (Blueprint $table) => $table->string('protection_account_status', 32)->default('NOT_APPLICABLE'));
    }

    private function addColumn(string $tableName, string $column, \Closure $definition): void
    {
        if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, $column)) {
            Schema::table($tableName, $definition);
        }
    }

    public function down(): void
    {
        // Additive preparation is intentionally not used for live rollback.
    }
};
