<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipping_rate_versions')) {
            return;
        }

        foreach ([
            'scope', 'shipping_fee', 'rider_pool_percentage', 'logistics_percentage',
            'pickup_rider_weight', 'hub_to_hub_rider_weight', 'last_mile_rider_weight',
            'effective_from', 'effective_until', 'status', 'approved_by_admin_user_id',
            'source_request_id',
        ] as $column) {
            if (! Schema::hasColumn('shipping_rate_versions', $column)) {
                return;
            }
        }

        DB::transaction(function (): void {
            $effectiveAt = now();
            $current = DB::table('shipping_rate_versions')
                ->where('scope', 'GLOBAL')
                ->where('status', 'ACTIVE')
                ->where('effective_from', '<=', $effectiveAt)
                ->where(fn ($query) => $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', $effectiveAt))
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $current
                || number_format((float) $current->rider_pool_percentage, 4, '.', '') !== '90.0000'
                || number_format((float) $current->logistics_percentage, 4, '.', '') !== '10.0000') {
                return;
            }

            DB::table('shipping_rate_versions')->where('id', $current->id)->update([
                'effective_until' => $effectiveAt,
                'status' => 'SUPERSEDED',
                'updated_at' => $effectiveAt,
            ]);

            $newVersionId = DB::table('shipping_rate_versions')->insertGetId([
                'scope' => 'GLOBAL',
                'shipping_fee' => $current->shipping_fee,
                'rider_pool_percentage' => '60.0000',
                'logistics_percentage' => '40.0000',
                'pickup_rider_weight' => $current->pickup_rider_weight,
                'hub_to_hub_rider_weight' => $current->hub_to_hub_rider_weight,
                'last_mile_rider_weight' => $current->last_mile_rider_weight,
                'effective_from' => $effectiveAt,
                'effective_until' => null,
                'status' => 'ACTIVE',
                'approved_by_admin_user_id' => $current->approved_by_admin_user_id,
                'source_request_id' => null,
                'created_at' => $effectiveAt,
                'updated_at' => $effectiveAt,
            ]);

            if (Schema::hasTable('finance_audit_events')) {
                DB::table('finance_audit_events')->insert([
                    'event_type' => 'SHIPPING_SPLIT_MIGRATED_60_40',
                    'auditable_type' => 'App\\Models\\Finance\\ShippingRateVersion',
                    'auditable_id' => $newVersionId,
                    'actor_user_id' => $current->approved_by_admin_user_id,
                    'before_state' => json_encode([
                        'shipping_fee' => $current->shipping_fee,
                        'rider_pool_percentage' => $current->rider_pool_percentage,
                        'logistics_percentage' => $current->logistics_percentage,
                        'predecessor_version_id' => $current->id,
                    ], JSON_THROW_ON_ERROR),
                    'after_state' => json_encode([
                        'shipping_fee' => $current->shipping_fee,
                        'rider_pool_percentage' => '60.0000',
                        'logistics_percentage' => '40.0000',
                        'shipping_rate_version_id' => $newVersionId,
                    ], JSON_THROW_ON_ERROR),
                    'notes' => 'The legacy 90% Rider / 10% Logistics shipping split was superseded by the requested 60% Rider / 40% Logistics policy. Existing orders keep their predecessor rate version.',
                    'created_at' => $effectiveAt,
                    'updated_at' => $effectiveAt,
                ]);
            }
        }, 3);
    }

    public function down(): void
    {
        // Keep versioned shipping policy and financial audit history intact.
    }
};
