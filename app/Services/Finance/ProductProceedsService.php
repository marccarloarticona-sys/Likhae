<?php

namespace App\Services\Finance;

use App\Models\Finance\CodCollection;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\RiderRemittance;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ProductProceedsService
{
    public function submitToAdmin(
        User $actor,
        FinancialLedgerEntry $entry,
        string|int|float $amount,
        ?string $notes = null,
        ?string $handoffMethod = null,
        ?string $externalReference = null,
    ): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);

        return DB::transaction(function () use ($actor, $entry, $amount, $notes, $handoffMethod, $externalReference): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->with(['shipment.codCollection.remittanceItem.remittance', 'allocation', 'shipment.sellerOrder.order'])
                ->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'PRODUCT_PROCEEDS_REMITTANCE' || ! $locked->shipment || ! $locked->allocation) {
                throw new RuntimeException('This is not a parcel product proceeds handoff.');
            }
            $this->assertShipmentCenter($actor, $locked->shipment);
            $collection = $locked->shipment->codCollection;
            $riderRemittance = $collection
                ? RiderRemittance::query()->whereIn('id', DB::table('rider_remittance_items')->where('cod_collection_id', $collection->id)->select('rider_remittance_id'))->first()
                : null;
            if (! $collection || $collection->status !== CodCollection::REMITTED || $riderRemittance?->status !== RiderRemittance::RECONCILED) {
                throw new RuntimeException('The rider handoff must be reconciled and the buyer COD discrepancy resolved before product proceeds can move to Admin.');
            }

            $metadata = (array) $locked->metadata;
            $submittedCents = FinancialRules::cents($amount);
            $expectedCents = FinancialRules::cents($locked->amount);
            if ($locked->status === 'SUBMITTED') {
                if (FinancialRules::cents($metadata['amount_submitted'] ?? 0) === $submittedCents) {
                    return $locked;
                }
                throw new RuntimeException('This parcel handoff was already submitted with a different amount.');
            }
            if ($locked->status !== 'PENDING') {
                throw new RuntimeException('This parcel proceeds handoff is no longer awaiting submission.');
            }

            $reference = 'PR-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
            $metadata = array_merge($metadata, [
                'receipt_reference' => $reference,
                'amount_expected' => FinancialRules::amount($expectedCents),
                'amount_submitted' => FinancialRules::amount($submittedCents),
                'handoff_method' => filled($handoffMethod) ? trim($handoffMethod) : 'Manual cash handoff',
                'handoff_external_reference' => filled($externalReference) ? trim($externalReference) : null,
                'amount_received' => null,
                'discrepancy_amount' => FinancialRules::amount(abs($submittedCents - $expectedCents)),
                'sender' => ['user_id' => $actor->id, 'role' => 'LOGISTICS', 'logistics_center_id' => $actor->logisticsCenter->id],
                'recipient' => ['role' => 'ADMIN'],
                'submitted_at' => now()->toIso8601String(),
                'confirmation_status' => 'SUBMITTED',
                'acknowledged_by_user_id' => null,
                'acknowledged_at' => null,
                'discrepancy_resolution' => null,
                'notes' => $notes,
            ]);
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $locked->update(['status' => 'SUBMITTED', 'actor_user_id' => $actor->id, 'metadata' => $metadata]);
            $this->audit('LOGISTICS_TO_ADMIN_HANDOFF_SUBMITTED', $locked, $actor, $before, $metadata, $notes);

            return $locked->refresh();
        });
    }

    public function acknowledgeByAdmin(User $actor, FinancialLedgerEntry $entry, string|int|float $receivedAmount, ?string $notes = null): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);

        return DB::transaction(function () use ($actor, $entry, $receivedAmount, $notes): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->with(['shipment.sellerOrder.order', 'allocation'])
                ->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type === 'PRODUCT_PROCEEDS_REMITTANCE' && in_array($locked->status, ['RECEIVED', 'DISCREPANCY'], true)) {
                $receipt = (array) $locked->metadata;
                if (FinancialRules::cents($receipt['amount_received'] ?? -1) === FinancialRules::cents($receivedAmount)
                    && (int) ($receipt['acknowledged_by_user_id'] ?? 0) === (int) $actor->id) {
                    return $locked;
                }
                throw new RuntimeException('This parcel receipt was already acknowledged with a different amount or by another Admin.');
            }
            if ($locked->entry_type !== 'PRODUCT_PROCEEDS_REMITTANCE' || $locked->status !== 'SUBMITTED') {
                throw new RuntimeException('Only a submitted parcel proceeds handoff can be acknowledged.');
            }
            $metadata = (array) $locked->metadata;
            $receivedCents = FinancialRules::cents($receivedAmount);
            $expectedCents = FinancialRules::cents($locked->amount);
            $status = $receivedCents === $expectedCents ? 'RECEIVED' : 'DISCREPANCY';
            $before = ['status' => $locked->status, 'metadata' => $metadata];
            $metadata = array_merge($metadata, [
                'amount_received' => FinancialRules::amount($receivedCents),
                'discrepancy_amount' => FinancialRules::amount(abs($receivedCents - $expectedCents)),
                'confirmation_status' => $status,
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => now()->toIso8601String(),
                'discrepancy_resolution' => null,
                'notes' => $notes,
            ]);
            $locked->update(['status' => $status, 'metadata' => $metadata]);
            $this->audit($status === 'RECEIVED' ? 'ADMIN_ACKNOWLEDGED_PRODUCT_PROCEEDS' : 'ADMIN_RECORDED_PRODUCT_PROCEEDS_DISCREPANCY', $locked, $actor, $before, $metadata, $notes);

            return $locked->refresh();
        });
    }

    public function resolveAdminDiscrepancy(User $actor, FinancialLedgerEntry $entry, string|int|float $finalReceivedAmount, string $resolution): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }

        return DB::transaction(function () use ($actor, $entry, $finalReceivedAmount, $resolution): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $current = (array) $locked->metadata;
            if ($locked->entry_type === 'PRODUCT_PROCEEDS_REMITTANCE' && $locked->status === 'RECEIVED'
                && (int) data_get($current, 'discrepancy_resolution.resolved_by_user_id') === (int) $actor->id
                && data_get($current, 'discrepancy_resolution.resolution') === trim($resolution)
                && FinancialRules::cents($current['amount_received'] ?? 0) === FinancialRules::cents($finalReceivedAmount)) {
                return $locked;
            }
            if ($locked->entry_type !== 'PRODUCT_PROCEEDS_REMITTANCE' || $locked->status !== 'DISCREPANCY') {
                throw new RuntimeException('Only an open product proceeds discrepancy can be resolved.');
            }
            $expectedCents = FinancialRules::cents($locked->amount);
            $receivedCents = FinancialRules::cents($finalReceivedAmount);
            if ($receivedCents !== $expectedCents) {
                throw new RuntimeException('The final reconciled amount must equal product proceeds plus the Logistics shipping share.');
            }
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $metadata = array_merge((array) $locked->metadata, [
                'amount_received' => FinancialRules::amount($receivedCents),
                'discrepancy_amount' => '0.00',
                'confirmation_status' => 'RECEIVED',
                'discrepancy_resolution' => ['resolved_by_user_id' => $actor->id, 'resolved_at' => now()->toIso8601String(), 'resolution' => trim($resolution)],
                'resolution_notes' => trim($resolution),
            ]);
            $locked->update(['status' => 'RECEIVED', 'metadata' => $metadata]);
            $this->audit('ADMIN_RESOLVED_PRODUCT_PROCEEDS_DISCREPANCY', $locked, $actor, $before, $metadata, $resolution);

            return $locked->refresh();
        });
    }

    public function resolveBuyerCollectionDiscrepancy(User $actor, CodCollection $collection, string $resolution): CodCollection
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }

        return DB::transaction(function () use ($actor, $collection, $resolution): CodCollection {
            $locked = CodCollection::query()->with(['shipment', 'order'])
                ->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $priorResolution = FinanceAuditEvent::query()
                ->where('event_type', 'ADMIN_RESOLVED_BUYER_COD_DISCREPANCY')
                ->where('auditable_type', CodCollection::class)
                ->where('auditable_id', $locked->id)
                ->latest('id')->first();
            if ($locked->status === CodCollection::REMITTED && $priorResolution
                && (int) data_get($priorResolution->after_state, 'resolved_by_user_id') === (int) $actor->id
                && data_get($priorResolution->after_state, 'discrepancy_resolution') === trim($resolution)) {
                return $locked;
            }
            $remittance = RiderRemittance::query()->whereIn('id', DB::table('rider_remittance_items')->where('cod_collection_id', $locked->id)->select('rider_remittance_id'))->first();
            if ($locked->status !== CodCollection::REMITTED_DISCREPANCY || $remittance?->status !== RiderRemittance::RECONCILED) {
                throw new RuntimeException('The rider handoff must be reconciled before resolving a buyer collection discrepancy.');
            }
            $shipment = $locked->shipment;
            $expectedCents = FinancialRules::cents($locked->expected_amount);
            $before = ['status' => $locked->status, 'collected_amount' => $locked->collected_amount];
            $locked->update(['status' => CodCollection::REMITTED, 'collected_amount' => FinancialRules::amount($expectedCents)]);
            $ledger = FinancialLedgerEntry::query()->where('shipment_id', $shipment->id)->where('entry_type', 'COD_COLLECTED')->first();
            if ($ledger) {
                $ledger->update(['amount' => FinancialRules::amount($expectedCents), 'status' => 'POSTED', 'metadata' => array_merge((array) $ledger->metadata, [
                    'collection_status' => CodCollection::REMITTED,
                    'discrepancy_resolution' => ['resolved_by_user_id' => $actor->id, 'resolved_at' => now()->toIso8601String(), 'resolution' => trim($resolution)],
                ])]);
            }
            $this->audit('ADMIN_RESOLVED_BUYER_COD_DISCREPANCY', $locked, $actor, $before, [
                'parcel_id' => $shipment->id,
                'parcel_reference' => $shipment->tracking_number,
                'order_id' => $locked->order_id,
                'order_reference' => $locked->order?->order_number,
                'amount_expected' => $locked->expected_amount,
                'amount_collected_final' => FinancialRules::amount($expectedCents),
                'previous_amount_collected' => $before['collected_amount'],
                'currency' => 'PHP',
                'confirmation_status' => 'RESOLVED',
                'resolved_by_user_id' => $actor->id,
                'resolved_at' => now()->toIso8601String(),
                'discrepancy_resolution' => trim($resolution),
            ], $resolution);

            return $locked->refresh();
        });
    }

    private function assertActiveRole(User $actor, string $role): void
    {
        if (! $actor->isActive() || ! $actor->isAccountType($role)) {
            throw new RuntimeException('Only an active '.$role.' account may perform this finance transition.');
        }
    }

    private function assertShipmentCenter(User $actor, Shipment $shipment): void
    {
        $actor->loadMissing('logisticsCenter');
        $centerId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
        if (! $actor->logisticsCenter || (int) $actor->logisticsCenter->id !== (int) $centerId) {
            throw new RuntimeException('Only the parcel destination logistics center may remit proceeds to Admin.');
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $type, FinancialLedgerEntry|CodCollection $record, User $actor, array $before, array $after, ?string $notes): void
    {
        FinanceAuditEvent::query()->create([
            'event_type' => $type,
            'auditable_type' => $record::class,
            'auditable_id' => $record->id,
            'actor_user_id' => $actor->id,
            'before_state' => $before,
            'after_state' => $after,
            'notes' => $notes,
        ]);
    }
}
