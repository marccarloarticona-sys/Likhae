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
        $confirmedPayouts = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $this->shipment_financial_allocation_id)
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->latest('id')
            ->get();
        $confirmedPayout = $confirmedPayouts->first(
            fn (FinancialLedgerEntry $entry): bool => (int) data_get($entry->metadata, 'logistics_center_id') === (int) $this->logistics_center_id,
        );
        $confirmedMetadata = (array) $confirmedPayout?->metadata;
        if ($confirmedPayout && isset($confirmedMetadata['route_role'], $confirmedMetadata['amount_expected'])) {
            return [
                'role' => $confirmedMetadata['route_role'],
                'net_share_cents' => FinancialRules::cents($confirmedMetadata['logistics_net_share'] ?? 0),
                'transfer_cents' => FinancialRules::cents($confirmedMetadata['amount_expected']),
                'rider_reserve_cents' => FinancialRules::cents($confirmedMetadata['rider_pass_through'] ?? 0),
            ];
        }

        $snapshot = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $this->shipment_financial_allocation_id)
            ->where('entry_type', 'LOGISTICS_ALLOCATION')
            ->where('metadata->logistics_center_id', $this->logistics_center_id)
            ->first();
        $confirmedPayoutsMatchCurrentPolicy = $this->shipment && $this->allocation && $confirmedPayouts->isNotEmpty()
            && $confirmedPayouts->every(function (FinancialLedgerEntry $entry): bool {
                $metadata = (array) $entry->metadata;
                $centerId = (int) data_get($metadata, 'logistics_center_id', 0);
                if ($centerId <= 0 || ! isset($metadata['route_role'], $metadata['amount_expected'])) {
                    return false;
                }

                try {
                    $expected = DeliveryEarningsBreakdown::forLogisticsCenter($this->shipment, $this->allocation, $centerId);
                } catch (\RuntimeException) {
                    return false;
                }

                return $metadata['route_role'] === $expected['role']
                    && FinancialRules::cents($metadata['logistics_net_share'] ?? -1) === $expected['net_share_cents']
                    && FinancialRules::cents($metadata['amount_expected']) === $expected['transfer_cents']
                    && FinancialRules::cents($metadata['rider_pass_through'] ?? -1) === $expected['rider_reserve_cents'];
            });
        if ($confirmedPayouts->isNotEmpty() && ! $confirmedPayoutsMatchCurrentPolicy && $snapshot) {
            $snapshotMetadata = (array) $snapshot->metadata;
            if (isset($snapshotMetadata['route_role'], $snapshotMetadata['transfer_amount'], $snapshotMetadata['rider_reserve_amount'])) {
                return [
                    'role' => $snapshotMetadata['route_role'],
                    'net_share_cents' => FinancialRules::cents($snapshot->amount),
                    'transfer_cents' => FinancialRules::cents($snapshotMetadata['transfer_amount']),
                    'rider_reserve_cents' => FinancialRules::cents($snapshotMetadata['rider_reserve_amount']),
                ];
            }
        }
        if (! $this->shipment || ! $this->allocation) {
            return [
                'role' => 'UNKNOWN',
                'net_share_cents' => FinancialRules::cents($this->total_amount),
                'transfer_cents' => FinancialRules::cents($this->total_amount),
                'rider_reserve_cents' => 0,
            ];
        }

        return $snapshot
            ? DeliveryEarningsBreakdown::forLogisticsCenter($this->shipment, $this->allocation, (int) $this->logistics_center_id)
            : DeliveryEarningsBreakdown::forLegacyLogisticsCenter($this->shipment, $this->allocation, (int) $this->logistics_center_id);
    }
}
