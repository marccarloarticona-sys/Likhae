<?php

namespace App\Models\Finance;

use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Services\Finance\DeliveryEarningsBreakdown;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogisticsEarningPool extends Model
{
    protected $fillable = [
        'shipment_financial_allocation_id', 'shipment_id', 'logistics_center_id', 'total_amount',
        'approved_amount', 'paid_amount', 'unresolved_share_amount', 'status', 'settlement_reference',
        'approved_by_user_id', 'approved_at', 'paid_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['total_amount' => 'decimal:2', 'approved_amount' => 'decimal:2', 'paid_amount' => 'decimal:2', 'unresolved_share_amount' => 'decimal:2', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function allocation(): BelongsTo { return $this->belongsTo(ShipmentFinancialAllocation::class, 'shipment_financial_allocation_id'); }
    public function shipment(): BelongsTo { return $this->belongsTo(Shipment::class); }
    public function logisticsCenter(): BelongsTo { return $this->belongsTo(LogisticsCenter::class); }

    /** @return array{role:string,net_share_cents:int,transfer_cents:int,rider_reserve_cents:int} */
    public function payoutBreakdown(): array
    {
        $this->loadMissing('shipment', 'allocation');
        $snapshot = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $this->shipment_financial_allocation_id)
            ->where('entry_type', 'LOGISTICS_ALLOCATION')
            ->where('metadata->logistics_center_id', $this->logistics_center_id)
            ->first();
        $metadata = (array) $snapshot?->metadata;
        if ($snapshot && isset($metadata['route_role'], $metadata['transfer_amount'], $metadata['rider_reserve_amount'])) {
            return [
                'role' => $metadata['route_role'],
                'net_share_cents' => FinancialRules::cents($snapshot->amount),
                'transfer_cents' => FinancialRules::cents($metadata['transfer_amount']),
                'rider_reserve_cents' => FinancialRules::cents($metadata['rider_reserve_amount']),
            ];
        }
        if (! $this->shipment || ! $this->allocation) {
            return [
                'role' => 'UNKNOWN',
                'net_share_cents' => FinancialRules::cents($this->total_amount),
                'transfer_cents' => FinancialRules::cents($this->total_amount),
                'rider_reserve_cents' => 0,
            ];
        }

        return DeliveryEarningsBreakdown::forLogisticsCenter(
            $this->shipment,
            $this->allocation,
            (int) $this->logistics_center_id,
        );
    }
}
