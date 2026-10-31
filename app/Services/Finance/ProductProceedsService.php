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
use Illuminate\Support\Facades\Storage;
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
            if (! $collection || ! $riderRemittance?->coversCollectionWithVerifiedFullCash($collection)) {
                throw new RuntimeException('Logistics must verify the full Rider remittance batch before this parcel COD can move to Admin.');
            }

            $fullCodCents = FinancialRules::cents($collection->expected_amount);
            $destinationId = $locked->shipment->destination_logistics_center_id ?: $locked->shipment->logistics_center_id;
            if (! $destinationId || (int) $collection->logistics_center_id !== (int) $destinationId
                || (int) $riderRemittance->logistics_center_id !== (int) $destinationId) {
                throw new RuntimeException('The verified Rider batch must belong to this parcel destination.');
            }

            $metadata = (array) $locked->metadata;
            if ($locked->status === 'PENDING'
                && ($metadata['amount_submitted'] ?? null) === null
                && (FinancialRules::cents($locked->amount) !== $fullCodCents
                    || ($metadata['remittance_scope'] ?? null) !== 'FULL_COD'
                    || ($metadata['rider_shipping_share_included'] ?? false) !== true)) {
                $before = ['amount' => $locked->amount, 'metadata' => $metadata];
                $metadata = array_merge($metadata, [
                    'amount_expected' => FinancialRules::amount($fullCodCents),
                    'remittance_scope' => 'FULL_COD',
                    'product_amount' => $locked->allocation->product_amount,
                    'shipping_fee' => $locked->allocation->shipping_fee,
                    'additional_care_amount' => $locked->allocation->protection_amount ?? '0.00',
                    'logistics_shipping_share' => $locked->allocation->logistics_allocation,
                    'rider_shipping_share' => $locked->allocation->rider_allocation,
                    'rider_shipping_share_included' => true,
                ]);
                $locked->update(['amount' => FinancialRules::amount($fullCodCents), 'metadata' => $metadata]);
                FinanceAuditEvent::query()->create([
                    'event_type' => 'PENDING_COD_REMITTANCE_EXPECTATION_UPDATED',
                    'auditable_type' => FinancialLedgerEntry::class,
                    'auditable_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'before_state' => $before,
                    'after_state' => ['amount' => $locked->amount, 'metadata' => $metadata],
                    'notes' => 'Pending unsubmitted Admin remittance updated to the full collected COD amount; no remittance has yet been submitted.',
                ]);
            }
            $submittedCents = FinancialRules::cents($amount);
            if ($submittedCents < 0) {
                throw new RuntimeException('The Admin cash remittance cannot be negative.');
            }
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
            if ($submittedCents !== $expectedCents) {
                throw new RuntimeException($submittedCents < $expectedCents
                    ? 'The full COD handoff must equal PHP '.number_format($expectedCents / 100, 2, '.', ',').'. Logistics must cover the PHP '.number_format(($expectedCents - $submittedCents) / 100, 2, '.', ',').' shortfall before submitting to Admin.'
                    : 'The full COD handoff must equal PHP '.number_format($expectedCents / 100, 2, '.', ',').'. Remove the PHP '.number_format(($submittedCents - $expectedCents) / 100, 2, '.', ',').' excess before submitting.');
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

    /** @param array<int, int|string> $entryIds @return array<int, FinancialLedgerEntry> */
    public function submitBatchToAdmin(
        User $actor,
        array $entryIds,
        ?string $notes = null,
        ?string $handoffMethod = null,
        ?string $externalReference = null,
    ): array {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);
        $ids = array_values(array_unique(array_map('intval', $entryIds)));
        if ($ids === [] || count($ids) !== count($entryIds) || in_array(0, $ids, true)) {
            throw new RuntimeException('Select one or more valid pending parcel remittances.');
        }

        return DB::transaction(function () use ($actor, $ids, $notes, $handoffMethod, $externalReference): array {
            $entries = FinancialLedgerEntry::query()
                ->with(['shipment.codCollection.remittanceItem.remittance', 'allocation', 'shipment.sellerOrder.order'])
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($entries->count() !== count($ids)) {
                throw new RuntimeException('One or more selected parcel remittances are no longer available. Refresh and select them again.');
            }

            $expectedTotalCents = 0;
            foreach ($entries as $entry) {
                if ($entry->entry_type !== 'PRODUCT_PROCEEDS_REMITTANCE'
                    || $entry->status !== 'PENDING'
                    || ! $entry->shipment
                    || ! $entry->allocation) {
                    throw new RuntimeException('Every selected parcel must still be pending COD remittance to Admin.');
                }
                $this->assertShipmentCenter($actor, $entry->shipment);
                $collection = $entry->shipment->codCollection;
                $riderRemittance = $collection?->remittanceItem?->remittance;
                if (! $collection || ! $riderRemittance?->coversCollectionWithVerifiedFullCash($collection)) {
                    throw new RuntimeException('Logistics must verify the full Rider remittance batch for every selected parcel before sending COD to Admin.');
                }
                $expectedTotalCents += FinancialRules::cents($collection->expected_amount);
            }

            // Each selected parcel is eligible only after its full Rider batch
            // was reconciled. That verified amount already includes Rider-funded
            // shortfalls, so forward the COD due without charging Logistics again.
            $cashFromRiderCents = $expectedTotalCents;
            $logisticsTopUpCents = 0;

            $batchReference = 'PRB-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
            $submittedEntries = [];
            foreach ($entries as $entry) {
                $collection = $entry->shipment->codCollection;
                $submitted = $this->submitToAdmin(
                    $actor,
                    $entry,
                    $collection->expected_amount,
                    $notes,
                    $handoffMethod,
                    $externalReference,
                );
                $before = (array) $submitted->metadata;
                $metadata = array_merge($before, [
                    'batch_receipt_reference' => $batchReference,
                    'batch_expected_amount' => FinancialRules::amount($expectedTotalCents),
                    'batch_cash_from_rider_amount' => FinancialRules::amount($cashFromRiderCents),
                    'batch_logistics_top_up_amount' => FinancialRules::amount($logisticsTopUpCents),
                    'batch_submitted_amount' => FinancialRules::amount($expectedTotalCents),
                    'batch_parcel_count' => $entries->count(),
                    'batch_position' => count($submittedEntries) + 1,
                ]);
                $submitted->update(['metadata' => $metadata]);
                $submittedEntries[] = $submitted->refresh();
            }

            $this->audit('LOGISTICS_TO_ADMIN_BATCH_SUBMITTED', $submittedEntries[0], $actor, [], [
                'batch_receipt_reference' => $batchReference,
                'parcel_entry_ids' => collect($submittedEntries)->map(fn (FinancialLedgerEntry $entry): int => (int) $entry->id)->all(),
                'batch_expected_amount' => FinancialRules::amount($expectedTotalCents),
                'cash_from_rider_amount' => FinancialRules::amount($cashFromRiderCents),
                'logistics_top_up_amount' => FinancialRules::amount($logisticsTopUpCents),
                'batch_submitted_amount' => FinancialRules::amount($expectedTotalCents),
                'batch_parcel_count' => count($submittedEntries),
                'handoff_method' => filled($handoffMethod) ? trim($handoffMethod) : 'Manual cash handoff',
                'handoff_external_reference' => filled($externalReference) ? trim($externalReference) : null,
            ], $notes);

            return $submittedEntries;
        });
    }

    public function acknowledgeByAdmin(User $actor, FinancialLedgerEntry $entry, string|int|float $receivedAmount, ?string $notes = null): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);

        return DB::transaction(function () use ($actor, $entry, $receivedAmount, $notes): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->with(['shipment.sellerOrder.order', 'shipment.codCollection.remittanceItem.remittance', 'allocation'])
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
            $fullCodCents = $this->assertDestinationHandoff($locked);
            $receivedCents = FinancialRules::cents($receivedAmount);
            if ($receivedCents < 0) {
                throw new RuntimeException('Cash received cannot be negative.');
            }
            $expectedCents = FinancialRules::cents($locked->amount);
            $submittedCents = FinancialRules::cents($metadata['amount_submitted'] ?? 0);
            $cleanReceipt = $expectedCents === $fullCodCents && $receivedCents === $fullCodCents && $submittedCents === $fullCodCents;
            $status = $cleanReceipt ? 'RECEIVED' : 'DISCREPANCY';
            $before = ['status' => $locked->status, 'metadata' => $metadata];
            $metadata = array_merge($metadata, [
                'amount_received' => FinancialRules::amount($receivedCents),
                'full_cod_expected' => FinancialRules::amount($fullCodCents),
                'discrepancy_amount' => FinancialRules::amount(max(abs($receivedCents - $fullCodCents), abs($submittedCents - $fullCodCents), abs($expectedCents - $fullCodCents))),
                'confirmation_status' => $status,
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => now()->toIso8601String(),
                'discrepancy_resolution' => null,
                'notes' => $notes,
            ]);
            $locked->update(['status' => $status, 'metadata' => $metadata]);
            $this->audit($status === 'RECEIVED' ? 'ADMIN_ACKNOWLEDGED_PRODUCT_PROCEEDS' : 'ADMIN_RECORDED_PRODUCT_PROCEEDS_DISCREPANCY', $locked, $actor, $before, $metadata, $notes);
            if ($cleanReceipt) {
                app(FinancialLedgerTransferService::class)->postCounterpart(
                    $locked,
                    'ADMIN_COD_CASH',
                    'CREDIT',
                    'LOGISTICS_ADMIN_COD_HANDOFF',
                    $actor,
                    [
                        'amount_expected' => FinancialRules::amount($expectedCents),
                        'amount_submitted' => FinancialRules::amount($submittedCents),
                        'amount_received' => FinancialRules::amount($receivedCents),
                        'sender' => data_get($metadata, 'sender'),
                        'recipient' => ['role' => 'ADMIN'],
                    ],
                );
            }

            return $locked->refresh();
        });
    }

    public function resolveAdminDiscrepancy(
        User $actor,
        FinancialLedgerEntry $entry,
        string|int|float $finalReceivedAmount,
        string $resolution,
        ?string $evidencePath = null,
    ): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }
        if (! is_string($evidencePath)
            || ! str_starts_with($evidencePath, 'cod-cash-evidence/')
            || ! Storage::disk('local')->exists($evidencePath)) {
            throw new RuntimeException('Upload actual cash evidence before resolving this discrepancy.');
        }

        return DB::transaction(function () use ($actor, $entry, $finalReceivedAmount, $resolution, $evidencePath): FinancialLedgerEntry {
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
            $fullCodCents = $this->assertDestinationHandoff($locked);
            if ((int) data_get($current, 'acknowledged_by_user_id') === (int) $actor->id) {
                throw new RuntimeException('The Admin who first verified this receipt cannot independently resolve its discrepancy.');
            }
            $expectedCents = FinancialRules::cents($locked->amount);
            $receivedCents = FinancialRules::cents($finalReceivedAmount);
            if ($expectedCents !== $fullCodCents || $receivedCents !== $fullCodCents) {
                throw new RuntimeException('The final reconciled amount must equal the full buyer COD, including all delivery charges.');
            }
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $metadata = array_merge((array) $locked->metadata, [
                'amount_received' => FinancialRules::amount($receivedCents),
                'discrepancy_amount' => '0.00',
                'confirmation_status' => 'RECEIVED',
                'discrepancy_resolution' => [
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => now()->toIso8601String(),
                    'resolution' => trim($resolution),
                    'evidence_path' => $evidencePath,
                ],
                'resolution_notes' => trim($resolution),
            ]);
            $locked->update(['status' => 'RECEIVED', 'metadata' => $metadata]);
            $this->audit('ADMIN_RESOLVED_PRODUCT_PROCEEDS_DISCREPANCY', $locked, $actor, $before, $metadata, $resolution);
            app(FinancialLedgerTransferService::class)->postCounterpart(
                $locked,
                'ADMIN_COD_CASH',
                'CREDIT',
                'LOGISTICS_ADMIN_COD_HANDOFF',
                $actor,
                [
                    'amount_expected' => FinancialRules::amount($expectedCents),
                    'amount_received' => FinancialRules::amount($receivedCents),
                    'resolution' => trim($resolution),
                    'evidence_path' => $evidencePath,
                    'sender' => data_get($metadata, 'sender'),
                    'recipient' => ['role' => 'ADMIN'],
                ],
            );

            return $locked->refresh();
        });
    }

    public function resolveBuyerCollectionDiscrepancy(
        User $actor,
        CodCollection $collection,
        string $resolution,
        ?string $evidencePath = null,
    ): CodCollection
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }
        if (! is_string($evidencePath)
            || ! str_starts_with($evidencePath, 'cod-cash-evidence/')
            || ! Storage::disk('local')->exists($evidencePath)) {
            throw new RuntimeException('Upload actual cash evidence before resolving this discrepancy.');
        }

        return DB::transaction(function () use ($actor, $collection, $resolution, $evidencePath): CodCollection {
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
            if ($locked->status !== CodCollection::REMITTED_DISCREPANCY
                || $remittance?->status !== RiderRemittance::RECONCILED
                || FinancialRules::cents($remittance->expected_amount) !== FinancialRules::cents($locked->expected_amount)
                || FinancialRules::cents($remittance->verified_amount) !== FinancialRules::cents($locked->expected_amount)) {
                throw new RuntimeException('The full server-calculated COD must be verified at Logistics before resolving a buyer collection discrepancy.');
            }
            $shipment = $locked->shipment;
            $expectedCents = FinancialRules::cents($locked->expected_amount);
            $before = ['status' => $locked->status, 'collected_amount' => $locked->collected_amount];
            $locked->update([
                'status' => CodCollection::REMITTED,
                'collected_amount' => FinancialRules::amount($expectedCents),
                'evidence_path' => $evidencePath,
            ]);
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
                'resolution_evidence_path' => $evidencePath,
            ], $resolution);

            return $locked->refresh();
        });
    }

    private function assertDestinationHandoff(FinancialLedgerEntry $entry): int
    {
        $entry->loadMissing('shipment.codCollection.remittanceItem.remittance');
        $shipment = $entry->shipment;
        $collection = $shipment?->codCollection;
        $remittance = $collection?->remittanceItem?->remittance;
        $destinationId = $shipment?->destination_logistics_center_id ?: $shipment?->logistics_center_id;
        $fullCodCents = $collection ? FinancialRules::cents($collection->expected_amount) : -1;
        if (! $destinationId || ! $collection || ! $remittance
            || ! $remittance->coversCollectionWithVerifiedFullCash($collection)
            || (int) $collection->logistics_center_id !== (int) $destinationId
            || (int) $remittance->logistics_center_id !== (int) $destinationId
            || (int) data_get($entry->metadata, 'sender.logistics_center_id') !== (int) $destinationId) {
            throw new RuntimeException('The full Rider COD must be reconciled at Destination Logistics before Admin verifies the handoff.');
        }

        return $fullCodCents;
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
