<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('vouchers', 'requires_review')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->boolean('requires_review')->default(false)->after('is_active');
            });
        }

        if (! Schema::hasColumn('vouchers', 'review_reason')) {
            Schema::table('vouchers', function (Blueprint $table): void {
                $table->string('review_reason', 255)->nullable()->after('requires_review');
            });
        }

        DB::table('vouchers')
            ->where(function ($query): void {
                $query->where(function ($percent): void {
                    $percent->where('discount_type', 'PERCENT')
                        ->where(function ($value): void {
                            $value->where('discount_value', '<=', 0)
                                ->orWhere('discount_value', '>=', 100);
                        });
                })->orWhere(function ($fixed): void {
                    $fixed->where('discount_type', 'FIXED')
                        ->where('discount_value', '<=', 0);
                })->orWhere(function ($maximum): void {
                    $maximum->whereNotNull('maximum_discount_amount')
                        ->where('maximum_discount_amount', '<=', 0);
                })->orWhere('minimum_order_amount', '<', 0);
            })
            ->update([
                'requires_review' => true,
                'review_reason' => 'Legacy discount terms are invalid; review and replace before reactivation.',
                'is_active' => false,
            ]);
    }

    public function down(): void
    {
        // Review flags are retained on rollback so a later rollback cannot
        // silently reactivate legacy vouchers that were found to be invalid.
    }
};
