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

    public function ready(): bool
    {
        return $this->step6Ready() && $this->step7Ready() && $this->originalPriceSnapshotReady();
    }
}
