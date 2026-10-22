<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\Schema;

final class FinanceSchemaReadiness
{
    public function step6Ready(): bool
    {
        return Schema::hasTable('cod_collections')
            && Schema::hasTable('rider_remittances')
            && Schema::hasTable('rider_remittance_items')
            && Schema::hasTable('finance_audit_events');
    }

    public function step7Ready(): bool
    {
        return Schema::hasTable('shipment_financial_allocations')
            && Schema::hasTable('seller_settlements')
            && Schema::hasTable('logistics_earning_pools')
            && Schema::hasTable('rider_earning_pools')
            && Schema::hasTable('rider_earning_pool_participants')
            && Schema::hasTable('financial_ledger_entries')
            && Schema::hasTable('finance_audit_events');
    }

    public function originalPriceSnapshotReady(): bool
    {
        return Schema::hasColumn('order_items', 'original_unit_price');
    }

    public function parcelSettlementReady(): bool
    {
        if (! $this->step6Ready() || ! $this->step7Ready() || ! $this->originalPriceSnapshotReady()) {
            return false;
        }

        foreach ([
            'payout_method', 'payout_external_reference', 'payout_failure_reason', 'payout_failed_at',
            'payout_failed_by_user_id', 'seller_acknowledged_at', 'seller_acknowledged_by_user_id',
        ] as $column) {
            if (! Schema::hasColumn('seller_settlements', $column)) {
                return false;
            }
        }

        foreach (['shipment_financial_allocations', 'seller_settlements'] as $table) {
            $hasSellerOrderUnique = collect(Schema::getIndexes($table))->contains(
                fn (array $index): bool => $index['unique'] && $index['columns'] === ['seller_order_id'],
            );
            if ($hasSellerOrderUnique) {
                return false;
            }
        }

        return true;
    }

    public function sellerPayoutProofReady(): bool
    {
        return Schema::hasTable('seller_settlements')
            && Schema::hasColumn('seller_settlements', 'payout_proof_path')
            && Schema::hasColumn('seller_settlements', 'payout_proof_name');
    }

    public function ready(): bool
    {
        return $this->step6Ready() && $this->step7Ready() && $this->originalPriceSnapshotReady();
    }
}
