<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\CodCollection;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\SellerSettlement;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;


final class EarningPayoutService
{
    public function __construct(
        private readonly FinanceSchemaReadiness $schema,
        private readonly PayoutDestinationReadiness $destinations,
        private readonly SimulatedPayoutGateway $simulatedPayouts,
    ) {}

    /** @return Collection<int, RiderEarningPoolParticipant> */
    public function eligibleRiderParticipants(RiderProfile $rider): Collection
    {
        if (! $this->schema->parcelSettlementReady()) {
            return new Collection;
        }

        return RiderEarningPoolParticipant::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'PENDING')
            ->where('share_amount', '>', 0)
            ->whereHas('pool.shipment.codCollection', fn ($collections) => $collections
                ->where('status', 'REMITTED')
                ->whereHas('remittanceItem.remittance', fn ($remittances) => $remittances->where('status', 'RECONCILED')))
            ->with(['pool.allocation', 'pool.shipment.sellerOrder.order', 'pool.shipment.codCollection.remittanceItem.remittance'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (RiderEarningPoolParticipant $participant): bool => $this->hasConfirmedGrossLogisticsPayout($participant))
            ->values();
    }

    /** @return Collection<int, FinancialLedgerEntry> */
    public function riderPayoutReceipts(RiderProfile $rider): Collection
    {
        if (! $this->schema->parcelSettlementReady()) {
            return new Collection;
        }
        $participantIds = RiderEarningPoolParticipant::query()
            ->where('rider_profile_id', $rider->id)
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->pluck('id');
        if ($participantIds->isEmpty()) {
            return new Collection;
        }

        return FinancialLedgerEntry::query()
            ->where('entry_type', 'RIDER_PAYOUT')
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->whereIn('idempotency_key', $participantIds->map(fn ($id): string => 'rider-payout-'.$id))
            ->with('shipment')
            ->latest('paid_at')
            ->get();
    }

    /** @param list<int> $participantIds
     *  @return Collection<int, FinancialLedgerEntry>
     */
    public function requestRiderPayout(User $actor, array $participantIds): Collection
    {
        $this->assertActiveRole($actor, User::TYPE_RIDER);
        if (! $this->schema->parcelSettlementReady()) {
            throw new \RuntimeException('Parcel earning payouts are unavailable until the reviewed finance migrations are installed.');
        }
        $rider = $actor->riderProfile;
        if (! $rider || $rider->status !== 'ACTIVE') {
            throw new \RuntimeException('Only an active Rider can request an earning payout.');
        }
        if (! $this->destinations->readyFor('rider_profiles') || ! $this->destinations->hasDestination($rider)) {
            throw new \RuntimeException('Save your online payout details in Wallet before requesting a payout.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $participantIds), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new \RuntimeException('Select at least one eligible Rider earning.');
        }

        return DB::transaction(function () use ($actor, $rider, $ids): Collection {
            RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $participants = RiderEarningPoolParticipant::query()
                ->whereIn('id', $ids)
                ->where('rider_profile_id', $rider->id)
                ->with(['pool.allocation', 'pool.shipment.codCollection.remittanceItem.remittance', 'pool.shipment.sellerOrder.order'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($participants->count() !== count($ids)) {
                throw new \RuntimeException('One or more selected earnings do not belong to this Rider.');
            }

            $entries = new Collection;
            foreach ($participants as $participant) {
                $idempotencyKey = 'rider-payout-'.$participant->id;
                $existing = FinancialLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if (in_array($existing->status, ['PAYOUT_REQUESTED', 'PAYOUT_PENDING_ACK', 'PAID'], true)
                        && (int) data_get($existing->metadata, 'rider_profile_id') === (int) $rider->id) {
                        if ($existing->status === 'PAYOUT_REQUESTED') {
                            $this->assertReconciledRiderCash($participant);
                        }
                        $entries->push($existing);
                        continue;
                    }
                    throw new \RuntimeException('This Rider earning already has a payout record that needs review.');
                }
                if ($participant->status !== 'PENDING' || FinancialRules::cents($participant->share_amount) <= 0) {
                    throw new \RuntimeException('Only unpaid, allocated Rider shares can be requested.');
                }
                $this->assertReconciledRiderCash($participant);

                $now = now();
                $metadata = [
                    'payout_reference' => 'RDP-'.Str::upper((string) Str::uuid()),
                    'participant_id' => $participant->id,
                    'payout_logistics_center_id' => $this->payoutCenterIdForParticipant($participant),
                    'rider_pool_id' => $participant->rider_earning_pool_id,
                    'rider_profile_id' => $rider->id,
                    'rider_user_id' => $actor->id,
                    'shipment_id' => $participant->pool?->shipment_id,
                    'amount_expected' => (string) $participant->share_amount,
                    'amount_paid' => null,
                    'currency' => 'PHP',
                    'confirmation_status' => 'PAYOUT_REQUESTED',
                    'requested_by_user_id' => $actor->id,
                    'requested_at' => $now->toIso8601String(),
                    'paid_by_user_id' => null,
                    'paid_at' => null,
                    'acknowledged_by_user_id' => null,
                    'acknowledged_at' => null,
                ];
                $entry = FinancialLedgerEntry::query()->create([
                    'shipment_financial_allocation_id' => $participant->pool?->shipment_financial_allocation_id,
                    'shipment_id' => $participant->pool?->shipment_id,
                    'seller_order_id' => $participant->pool?->shipment?->seller_order_id,
                    'order_id' => $participant->pool?->shipment?->sellerOrder?->order_id,
                    'account_type' => 'RIDER',
                    'entry_type' => 'RIDER_PAYOUT',
                    'direction' => 'CREDIT',
                    'amount' => $participant->share_amount,
                    'status' => 'PAYOUT_REQUESTED',
                    'idempotency_key' => $idempotencyKey,
                    'actor_user_id' => $actor->id,
                    'posted_at' => $now,
                    'metadata' => $metadata,
                ]);
                $participant->forceFill(['status' => 'PAYOUT_REQUESTED'])->save();
                $this->audit('RIDER_EARNING_PAYOUT_REQUESTED', $entry, $actor, [], $metadata, 'Rider requested the allocated share after the full COD handoff and Admin delivery-charge release were verified.');
                $entries->push($entry);
            }

            return $entries;
        }, 3);
    }

    public function recordRiderPayout(
        User $actor,
        FinancialLedgerEntry $entry,
        string|int|float $amount,
        string $method,
        ?string $externalReference = null,
        ?string $notes = null,
        ?string $proofPath = null,
        ?string $proofName = null,
    ): FinancialLedgerEntry {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);
        $method = trim($method);
        if ($method === '') {
            throw new \RuntimeException('Record whether the Rider payout was manual or online.');
        }
        if ($this->simulatedPayouts->simulate($method) !== null) {
            throw new \RuntimeException('This payout channel is demo only and cannot be recorded as money sent. Record the actual transfer as Manual with its reference and proof.');
        }
        if ($method !== 'Manual'
            || ! filled($externalReference)
            || $this->simulatedPayouts->isSimulationReference($externalReference)
            || ! filled($proofPath)
            || ! Storage::disk('local')->exists($proofPath)) {
            throw new \RuntimeException('Record an actual Rider transfer as Manual with a real transaction reference and saved proof.');
        }

        return DB::transaction(function () use ($actor, $entry, $amount, $method, $externalReference, $notes, $proofPath, $proofName): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'RIDER_PAYOUT') {
                throw new \RuntimeException('This ledger entry is not a Rider payout request.');
            }
            $participant = RiderEarningPoolParticipant::query()
                ->with(['pool.allocation', 'pool.shipment.codCollection.remittanceItem.remittance', 'pool.shipment.sellerOrder.order', 'riderProfile'])
                ->whereKey((int) data_get($locked->metadata, 'participant_id'))
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCenterOwnsParticipant($actor, $participant);
            $amountCents = FinancialRules::cents($amount);
            $expectedCents = FinancialRules::cents($locked->amount);
            if ($amountCents !== $expectedCents) {
                throw new \RuntimeException('The recorded Rider payout must equal the approved parcel earning exactly.');
            }
            if (in_array($locked->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                $metadata = (array) $locked->metadata;
                $replaceLegacySimulation = in_array($locked->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)
                    && $this->isSimulatedPayout($metadata)
                    && $method === 'Manual';
                if (! $replaceLegacySimulation
                    && FinancialRules::cents($metadata['amount_paid'] ?? -1) === $amountCents
                    && (string) ($metadata['payout_method'] ?? '') === $method
                    && ($externalReference === null || (string) ($metadata['payout_external_reference'] ?? '') === $externalReference)) {
                    return $locked;
                }
                if (! $replaceLegacySimulation) {
                    throw new \RuntimeException('This Rider payout was already recorded with different payment details.');
                }
            } else {
                $replaceLegacySimulation = false;
            }
            if ($locked->status !== 'PAYOUT_REQUESTED' && ! $replaceLegacySimulation) {
                throw new \RuntimeException('Only a requested Rider payout can be recorded.');
            }
            $this->assertReconciledRiderCash($participant);
            $this->assertUnusedTransferReference((array) $locked->metadata, (string) $externalReference);
            $this->assertGloballyUnusedTransferReference((string) $externalReference, $locked->id);

            $now = now();
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $attemptHistory = (array) data_get($locked->metadata, 'payout_attempt_history', []);
            if ($replaceLegacySimulation) {
                $attemptHistory[] = [
                    'status' => 'SIMULATED_NOT_PAID',
                    'payout_method' => data_get($locked->metadata, 'payout_method'),
                    'payout_external_reference' => data_get($locked->metadata, 'payout_external_reference'),
                    'attempted_at' => data_get($locked->metadata, 'paid_at'),
                    'notice' => data_get($locked->metadata, 'simulation_notice', SimulatedPayoutGateway::NOTICE),
                ];
            }
            $metadata = array_merge((array) $locked->metadata, [
                'amount_paid' => FinancialRules::amount($amountCents),
                'payout_method' => $method,
                'payout_external_reference' => $externalReference,
                'payout_proof_path' => $proofPath,
                'payout_proof_name' => $proofName,
                'is_simulated' => false,
                'simulation_notice' => null,
                'payout_attempt_history' => $attemptHistory,
                'payout_receipt_reference' => 'RDP-'.Str::upper((string) Str::uuid()),
                'confirmation_status' => 'PAYOUT_PENDING_ACK',
                'paid_by_user_id' => $actor->id,
                'paid_at' => $now->toIso8601String(),
                'acknowledged_by_user_id' => null,
                'acknowledged_at' => null,
                'notes' => $notes,
            ]);
            $locked->update(['status' => 'PAYOUT_PENDING_ACK', 'actor_user_id' => $actor->id, 'paid_at' => $now, 'metadata' => $metadata]);
            $participant->forceFill([
                'status' => 'PAYOUT_PENDING_ACK',
                'approved_by_user_id' => $actor->id,
                'approved_at' => $now,
            ])->save();
            $this->refreshRiderPoolTotals($participant->pool);
            $this->audit(
                $replaceLegacySimulation ? 'LOGISTICS_REPLACED_SIMULATED_RIDER_PAYOUT' : 'LOGISTICS_RECORDED_RIDER_PAYOUT',
                $locked,
                $actor,
                $before,
                $metadata,
                $notes,
            );

            return $locked->refresh();
        }, 3);
    }

    public function acknowledgeRiderPayout(User $actor, FinancialLedgerEntry $entry, string|int|float $amount, ?string $notes = null): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_RIDER);

        return DB::transaction(function () use ($actor, $entry, $amount, $notes): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'RIDER_PAYOUT'
                || (int) data_get($locked->metadata, 'rider_user_id') !== (int) $actor->id
                || (int) data_get($locked->metadata, 'rider_profile_id') !== (int) $actor->riderProfile?->id) {
                throw new \RuntimeException('This Rider payout receipt does not belong to the signed-in Rider.');
            }
            $payoutMetadata = (array) $locked->metadata;
            $proofPath = $payoutMetadata['payout_proof_path'] ?? null;
            if ($this->isSimulatedPayout($payoutMetadata)
                || ! filled($payoutMetadata['payout_external_reference'] ?? null)
                || ! filled($proofPath)
                || ! Storage::disk('local')->exists($proofPath)) {
                throw new \RuntimeException('A demo or unverified transfer cannot be confirmed as Paid. Logistics must record the actual transfer reference and proof first.');
            }
            if ($locked->status === 'PAID'
                && (int) data_get($locked->metadata, 'acknowledged_by_user_id') === (int) $actor->id
                && FinancialRules::cents(data_get($locked->metadata, 'amount_paid', 0)) === FinancialRules::cents($amount)) {
                return $locked;
            }
            if ($locked->status !== 'PAYOUT_PENDING_ACK') {
                throw new \RuntimeException('Only a recorded payout awaiting your receipt confirmation can be acknowledged.');
            }
            $amountCents = FinancialRules::cents($amount);
            if ($amountCents !== FinancialRules::cents($locked->amount)) {
                throw new \RuntimeException('Confirm the exact amount paid on this receipt.');
            }
            $participant = RiderEarningPoolParticipant::query()->with('pool')->whereKey((int) data_get($locked->metadata, 'participant_id'))->lockForUpdate()->firstOrFail();
            if ((int) $participant->rider_profile_id !== (int) $actor->riderProfile?->id) {
                throw new \RuntimeException('This payout is assigned to another Rider profile.');
            }
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $metadata = array_merge((array) $locked->metadata, [
                'confirmation_status' => 'PAID',
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => now()->toIso8601String(),
                'receipt_notes' => $notes,
            ]);
            $locked->update(['status' => 'PAID', 'metadata' => $metadata]);
            $participant->forceFill(['status' => 'PAID'])->save();
            $this->refreshRiderPoolTotals($participant->pool);
            app(FinancialLedgerTransferService::class)->postCounterpart(
                $locked,
                'LOGISTICS_RIDER_LIABILITY',
                'DEBIT',
                'RIDER_FUNDS_RELEASED',
                $actor,
                [
                    'participant_id' => $participant->id,
                    'rider_profile_id' => $participant->rider_profile_id,
                    'logistics_center_id' => data_get($locked->metadata, 'payout_logistics_center_id'),
                    'amount_paid' => FinancialRules::amount($amountCents),
                ],
            );
            $this->audit('RIDER_ACKNOWLEDGED_PAYOUT', $locked, $actor, $before, $metadata, $notes);

            return $locked->refresh();
        }, 3);
    }

    public function markRiderPayoutFailed(User $actor, FinancialLedgerEntry $entry, string $reason): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Record why the Rider transfer failed.');
        }

        return DB::transaction(function () use ($actor, $entry, $reason): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'RIDER_PAYOUT') {
                throw new \RuntimeException('This ledger entry is not a Rider payout.');
            }
            $participant = RiderEarningPoolParticipant::query()
                ->with('pool')
                ->whereKey((int) data_get($locked->metadata, 'participant_id'))
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCenterOwnsParticipant($actor, $participant);
            if ($locked->status !== 'PAYOUT_PENDING_ACK') {
                throw new \RuntimeException('Only a Rider transfer awaiting receipt confirmation can be marked failed.');
            }
            $metadataBefore = (array) $locked->metadata;
            $proofPath = $metadataBefore['payout_proof_path'] ?? null;
            if ($this->isSimulatedPayout($metadataBefore)
                || ! filled($metadataBefore['payout_external_reference'] ?? null)
                || ! filled($proofPath)
                || ! Storage::disk('local')->exists($proofPath)) {
                throw new \RuntimeException('A demo or unverified payout cannot be recorded as a failed transfer.');
            }

            $now = now();
            $attempt = [
                'status' => 'FAILED',
                'amount' => $metadataBefore['amount_paid'] ?? $locked->amount,
                'payout_method' => $metadataBefore['payout_method'] ?? null,
                'payout_external_reference' => $metadataBefore['payout_external_reference'] ?? null,
                'payout_proof_path' => $proofPath,
                'payout_proof_name' => $metadataBefore['payout_proof_name'] ?? null,
                'attempted_at' => $metadataBefore['paid_at'] ?? $locked->paid_at?->toIso8601String(),
                'failed_at' => $now->toIso8601String(),
                'failed_by_user_id' => $actor->id,
                'failure_reason' => $reason,
            ];
            $attemptHistory = (array) data_get($metadataBefore, 'payout_attempt_history', []);
            $attemptHistory[] = $attempt;
            $metadataAfter = array_merge($metadataBefore, [
                'confirmation_status' => 'PAYOUT_REQUESTED',
                'last_failed_attempt' => $attempt,
                'payout_attempt_history' => $attemptHistory,
                'amount_paid' => null,
                'payout_method' => null,
                'payout_external_reference' => null,
                'payout_proof_path' => null,
                'payout_proof_name' => null,
                'paid_by_user_id' => null,
                'paid_at' => null,
                'acknowledged_by_user_id' => null,
                'acknowledged_at' => null,
            ]);
            $before = ['status' => $locked->status, 'metadata' => $metadataBefore];
            $locked->update(['status' => 'PAYOUT_REQUESTED', 'paid_at' => null, 'metadata' => $metadataAfter]);
            $participant->forceFill([
                'status' => 'PAYOUT_REQUESTED',
                'approved_by_user_id' => null,
                'approved_at' => null,
            ])->save();
            $this->refreshRiderPoolTotals($participant->pool);
            $this->audit('LOGISTICS_RECORDED_RIDER_PAYOUT_FAILURE', $locked, $actor, $before, $metadataAfter, $reason);

            return $locked->refresh();
        }, 3);
    }

    public function recordLogisticsPayout(
        User $actor,
        LogisticsEarningPool $pool,
        string|int|float $amount,
        string $method,
        ?string $externalReference = null,
        ?string $notes = null,
        ?string $proofPath = null,
        ?string $proofName = null,
    ): FinancialLedgerEntry {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        $method = trim($method);
        if ($method === '') {
            throw new \RuntimeException('The Logistics payout channel is missing from its saved account details.');
        }
        if (! filled($externalReference)
            || $this->simulatedPayouts->isSimulationReference($externalReference)
            || ! filled($proofPath)
            || ! Storage::disk('local')->exists($proofPath)) {
            throw new \RuntimeException('Enter the external transfer reference and upload proof of payment.');
        }

        return DB::transaction(function () use ($actor, $pool, $amount, $method, $externalReference, $notes, $proofPath, $proofName): FinancialLedgerEntry {
            $lockedPool = LogisticsEarningPool::query()->with(['shipment', 'logisticsCenter', 'allocation'])->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            if (! $this->destinations->readyFor('logistics_centers')
                || ! $lockedPool->logisticsCenter
                || ! $this->destinations->hasDestination($lockedPool->logisticsCenter)
                || $lockedPool->logisticsCenter->payout_method !== $method) {
                throw new \RuntimeException('The Logistics center must save complete payout details before Admin can record a transfer.');
            }
            $proceeds = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $lockedPool->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
                ->lockForUpdate()
                ->first();
            $this->assertFullCodReceived($lockedPool, $proceeds);
            $amountCents = FinancialRules::cents($amount);
            $breakdown = $lockedPool->payoutBreakdown();
            if (FinancialRules::cents($lockedPool->total_amount) !== $breakdown['net_share_cents']
                || ($breakdown['rider_reserve_cents'] > 0 && FinancialRules::cents($lockedPool->allocation?->rider_allocation) !== $breakdown['rider_reserve_cents'])) {
                throw new \RuntimeException('This historical Logistics allocation differs from the current route split and requires finance review.');
            }
            $expectedCents = $breakdown['transfer_cents'];
            if ($amountCents !== $expectedCents) {
                throw new \RuntimeException('The Logistics transfer amount must match this route allocation, including any Rider reserve.');
            }
            $logisticsShareCents = $breakdown['net_share_cents'];
            $riderPassThroughCents = $breakdown['rider_reserve_cents'];
            $idempotencyKey = $this->logisticsPayoutKey($lockedPool, $breakdown['role']);
            $existing = FinancialLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                $metadata = (array) $existing->metadata;
                $replaceLegacySimulation = $this->isSimulatedPayout($metadata);
                if (! $replaceLegacySimulation
                    && FinancialRules::cents($metadata['amount_paid'] ?? -1) === $amountCents
                    && (string) ($metadata['payout_method'] ?? '') === $method
                    && ($externalReference === null || (string) ($metadata['payout_external_reference'] ?? '') === $externalReference)) {
                    return $existing;
                }
                if (! $replaceLegacySimulation) {
                    throw new \RuntimeException('A Logistics payout record already exists with different details.');
                }
            } else {
                $replaceLegacySimulation = false;
            }
            if ($existing && ((int) data_get($existing->metadata, 'logistics_pool_id') !== (int) $lockedPool->id
                || ($existing->status !== 'PAYOUT_REQUESTED' && ! $replaceLegacySimulation))) {
                throw new \RuntimeException('A Logistics payout request already exists and needs review.');
            }
            if ($existing) {
                $this->assertUnusedTransferReference((array) $existing->metadata, (string) $externalReference);
            }
            $this->assertGloballyUnusedTransferReference((string) $externalReference, $existing?->id);

            $before = $existing ? ['status' => $existing->status, 'metadata' => $existing->metadata] : [];
            $now = now();
            $deliveryTotalCents = FinancialRules::deliveryEarningsCents(
                $lockedPool->allocation?->shipping_fee,
                $lockedPool->allocation?->protection_amount ?? 0,
            );
            $attemptHistory = (array) data_get($existing?->metadata, 'payout_attempt_history', []);
            if ($replaceLegacySimulation) {
                $attemptHistory[] = [
                    'status' => 'SIMULATED_NOT_PAID',
                    'payout_method' => data_get($existing?->metadata, 'payout_method'),
                    'payout_external_reference' => data_get($existing?->metadata, 'payout_external_reference'),
                    'attempted_at' => data_get($existing?->metadata, 'paid_at'),
                    'notice' => data_get($existing?->metadata, 'simulation_notice', SimulatedPayoutGateway::NOTICE),
                ];
            }
            $metadata = array_merge((array) ($existing?->metadata), [
                'payout_reference' => 'LGP-'.Str::upper((string) Str::uuid()),
                'logistics_pool_id' => $lockedPool->id,
                'logistics_center_id' => $lockedPool->logistics_center_id,
                'route_role' => $breakdown['role'],
                'shipment_id' => $lockedPool->shipment_id,
                'amount_expected' => FinancialRules::amount($expectedCents),
                'amount_paid' => FinancialRules::amount($amountCents),
                'gross_delivery_earnings' => FinancialRules::amount($deliveryTotalCents),
                'logistics_net_share' => FinancialRules::amount($logisticsShareCents),
                'rider_pass_through' => FinancialRules::amount($riderPassThroughCents),
                'base_shipping_fee' => $lockedPool->allocation?->shipping_fee,
                'additional_care_amount' => $lockedPool->allocation?->protection_amount ?? '0.00',
                'currency' => 'PHP',
                'payout_method' => $method,
                'payout_external_reference' => trim($externalReference),
                'payout_proof_path' => $proofPath,
                'payout_proof_name' => $proofName,
                'is_simulated' => false,
                'simulation_notice' => null,
                'payout_attempt_history' => $attemptHistory,
                'confirmation_status' => 'PAYOUT_PENDING_ACK',
                'paid_by_user_id' => $actor->id,
                'paid_at' => $now->toIso8601String(),
                'acknowledged_by_user_id' => null,
                'acknowledged_at' => null,
                'notes' => $notes,
            ]);
            $entry = $existing ?? new FinancialLedgerEntry;
            $entry->fill([
                'shipment_financial_allocation_id' => $lockedPool->shipment_financial_allocation_id,
                'shipment_id' => $lockedPool->shipment_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'LOGISTICS_PAYOUT',
                'direction' => 'CREDIT',
                'amount' => FinancialRules::amount($amountCents),
                'status' => 'PAYOUT_PENDING_ACK',
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actor->id,
                'posted_at' => $now,
                'paid_at' => $now,
                'metadata' => $metadata,
            ])->save();
            $lockedPool->update([
                'approved_amount' => FinancialRules::amount($logisticsShareCents),
                'status' => 'PAYOUT_PENDING_ACK',
                'settlement_reference' => $metadata['payout_reference'],
                'approved_by_user_id' => $actor->id,
                'approved_at' => $now,
                'paid_amount' => '0.00',
                'paid_at' => null,
                'notes' => $notes,
            ]);
            $this->audit(
                $replaceLegacySimulation ? 'ADMIN_REPLACED_SIMULATED_LOGISTICS_PAYOUT' : 'ADMIN_RECORDED_LOGISTICS_PAYOUT',
                $entry,
                $actor,
                $before,
                $metadata,
                $notes,
            );

            return $entry;
        }, 3);
    }

    public function acknowledgeLogisticsPayout(User $actor, FinancialLedgerEntry $entry, string|int|float $amount, ?string $notes = null): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);

        return DB::transaction(function () use ($actor, $entry, $amount, $notes): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $center = $actor->logisticsCenter;
            if ($locked->entry_type !== 'LOGISTICS_PAYOUT'
                || ! $center
                || (int) data_get($locked->metadata, 'logistics_center_id') !== (int) $center->id) {
                throw new \RuntimeException('This Logistics payout receipt does not belong to your center.');
            }
            if (data_get($locked->metadata, 'is_simulated') || str_starts_with((string) data_get($locked->metadata, 'payout_external_reference'), 'SIM-')) {
                throw new \RuntimeException('This legacy demo payout has no transfer proof and cannot be confirmed as received. Contact Admin for help.');
            }
            $proofPath = data_get($locked->metadata, 'payout_proof_path');
            if (! filled(data_get($locked->metadata, 'payout_external_reference'))
                || ! filled($proofPath)
                || ! Storage::disk('local')->exists($proofPath)) {
                throw new \RuntimeException('Admin must provide a saved transfer reference and proof before Logistics can confirm receipt.');
            }
            if ($locked->status === 'PAID'
                && (int) data_get($locked->metadata, 'acknowledged_by_user_id') === (int) $actor->id
                && FinancialRules::cents(data_get($locked->metadata, 'amount_paid', 0)) === FinancialRules::cents($amount)) {
                return $locked;
            }
            if ($locked->status !== 'PAYOUT_PENDING_ACK') {
                throw new \RuntimeException('Only a recorded Admin payout awaiting acknowledgment can be confirmed.');
            }
            $amountCents = FinancialRules::cents($amount);
            if ($amountCents !== FinancialRules::cents($locked->amount)) {
                throw new \RuntimeException('Confirm the exact amount received on this payout receipt.');
            }
            $pool = LogisticsEarningPool::query()->whereKey((int) data_get($locked->metadata, 'logistics_pool_id'))->lockForUpdate()->firstOrFail();
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $metadata = array_merge((array) $locked->metadata, [
                'confirmation_status' => 'PAID',
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => now()->toIso8601String(),
                'receipt_notes' => $notes,
            ]);
            $locked->update(['status' => 'PAID', 'metadata' => $metadata]);
            $pool->update(['status' => 'PAID', 'paid_amount' => $pool->total_amount, 'paid_at' => now(), 'notes' => $notes ?: $pool->notes]);
            app(FinancialLedgerTransferService::class)->postCounterpart(
                $locked,
                'ADMIN_CASH',
                'DEBIT',
                'ADMIN_LOGISTICS_PAYOUT',
                $actor,
                [
                    'logistics_center_id' => $pool->logistics_center_id,
                    'logistics_pool_id' => $pool->id,
                    'amount_paid' => FinancialRules::amount($amountCents),
                    'payout_external_reference' => data_get($metadata, 'payout_external_reference'),
                ],
            );
            $riderReserveCents = FinancialRules::cents(data_get($metadata, 'rider_pass_through', 0));
            if ($riderReserveCents > 0) {
                app(FinancialLedgerTransferService::class)->postPair(
                    'logistics-rider-reserve-'.$locked->id,
                    'RIDER_FUNDS_RESERVED',
                    $riderReserveCents,
                    'LOGISTICS_CASH',
                    'LOGISTICS_RIDER_LIABILITY',
                    $actor,
                    [
                        'logistics_pool_id' => $pool->id,
                        'logistics_center_id' => $pool->logistics_center_id,
                        'rider_earning_pool_id' => $pool->allocation?->riderPool?->id,
                        'shipment_id' => $pool->shipment_id,
                        'reserve_status' => 'RESERVED',
                    ],
                    $pool->shipment_financial_allocation_id,
                    $pool->shipment_id,
                    $pool->allocation?->seller_order_id,
                    $pool->allocation?->order_id,
                );
            }
            $this->audit('LOGISTICS_ACKNOWLEDGED_PAYOUT', $locked, $actor, $before, $metadata, $notes);

            return $locked->refresh();
        }, 3);
    }

    public function markLogisticsPayoutFailed(User $actor, FinancialLedgerEntry $entry, string $reason): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('Record why the Logistics transfer failed.');
        }

        return DB::transaction(function () use ($actor, $entry, $reason): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'LOGISTICS_PAYOUT') {
                throw new \RuntimeException('This ledger entry is not a Logistics payout.');
            }
            if ($locked->status !== 'PAYOUT_PENDING_ACK') {
                throw new \RuntimeException('Only a Logistics transfer awaiting receipt confirmation can be marked failed.');
            }
            $metadataBefore = (array) $locked->metadata;
            $proofPath = $metadataBefore['payout_proof_path'] ?? null;
            if ($this->isSimulatedPayout($metadataBefore)
                || ! filled($metadataBefore['payout_external_reference'] ?? null)
                || ! filled($proofPath)
                || ! Storage::disk('local')->exists($proofPath)) {
                throw new \RuntimeException('A demo or unverified payout cannot be recorded as a failed transfer.');
            }
            $pool = LogisticsEarningPool::query()
                ->whereKey((int) data_get($metadataBefore, 'logistics_pool_id'))
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $pool->logistics_center_id !== (int) data_get($metadataBefore, 'logistics_center_id')) {
                throw new \RuntimeException('The payout record does not match its Logistics earning pool.');
            }

            $now = now();
            $attempt = [
                'status' => 'FAILED',
                'amount' => $metadataBefore['amount_paid'] ?? $locked->amount,
                'payout_method' => $metadataBefore['payout_method'] ?? null,
                'payout_external_reference' => $metadataBefore['payout_external_reference'] ?? null,
                'payout_proof_path' => $proofPath,
                'payout_proof_name' => $metadataBefore['payout_proof_name'] ?? null,
                'attempted_at' => $metadataBefore['paid_at'] ?? $locked->paid_at?->toIso8601String(),
                'failed_at' => $now->toIso8601String(),
                'failed_by_user_id' => $actor->id,
                'failure_reason' => $reason,
            ];
            $attemptHistory = (array) data_get($metadataBefore, 'payout_attempt_history', []);
            $attemptHistory[] = $attempt;
            $metadataAfter = array_merge($metadataBefore, [
                'confirmation_status' => 'PAYOUT_REQUESTED',
                'last_failed_attempt' => $attempt,
                'payout_attempt_history' => $attemptHistory,
                'amount_paid' => null,
                'payout_method' => null,
                'payout_external_reference' => null,
                'payout_proof_path' => null,
                'payout_proof_name' => null,
                'paid_by_user_id' => null,
                'paid_at' => null,
                'acknowledged_by_user_id' => null,
                'acknowledged_at' => null,
            ]);
            $before = ['status' => $locked->status, 'metadata' => $metadataBefore];
            $locked->update(['status' => 'PAYOUT_REQUESTED', 'paid_at' => null, 'metadata' => $metadataAfter]);
            $pool->update(['status' => 'PAYOUT_REQUESTED', 'paid_amount' => '0.00', 'paid_at' => null]);
            $this->audit('ADMIN_RECORDED_LOGISTICS_PAYOUT_FAILURE', $locked, $actor, $before, $metadataAfter, $reason);

            return $locked->refresh();
        }, 3);
    }

    public function requestLogisticsPayout(User $actor, LogisticsEarningPool $pool): FinancialLedgerEntry
    {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);
        if (! $this->schema->parcelSettlementReady()) {
            throw new \RuntimeException('Logistics payout requests are unavailable until the reviewed finance migrations are installed.');
        }
        $center = $actor->logisticsCenter;
        if (! $center || $center->status !== 'ACTIVE') {
            throw new \RuntimeException('Only an active Logistics center can request a payout.');
        }
        if (! $this->destinations->readyFor('logistics_centers') || ! $this->destinations->hasDestination($center)) {
            throw new \RuntimeException('Save your GCash, Maya, or bank payout details in Account settings before requesting a payout.');
        }

        return DB::transaction(function () use ($actor, $center, $pool): FinancialLedgerEntry {
            $lockedPool = LogisticsEarningPool::query()->with(['shipment', 'allocation'])->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedPool->logistics_center_id !== (int) $center->id) {
                throw new \RuntimeException('This parcel payout belongs to a different Logistics center.');
            }
            $breakdown = $lockedPool->payoutBreakdown();
            if (FinancialRules::cents($lockedPool->total_amount) !== $breakdown['net_share_cents']
                || ($breakdown['rider_reserve_cents'] > 0 && FinancialRules::cents($lockedPool->allocation?->rider_allocation) !== $breakdown['rider_reserve_cents'])) {
                throw new \RuntimeException('This historical Logistics allocation differs from the current route split and requires finance review.');
            }
            $idempotencyKey = $this->logisticsPayoutKey($lockedPool, $breakdown['role']);
            if ($lockedPool->status === 'PAYOUT_REQUESTED') {
                return FinancialLedgerEntry::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }
            if (! in_array($lockedPool->status, ['PENDING', 'APPROVED'], true) || FinancialRules::cents($lockedPool->total_amount) <= 0) {
                throw new \RuntimeException('This Logistics share is not eligible for a payout request.');
            }
            $proceeds = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $lockedPool->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
                ->lockForUpdate()
                ->first();
            $this->assertFullCodReceived($lockedPool, $proceeds);

            $now = now();
            $grossCents = $breakdown['transfer_cents'];
            $deliveryTotalCents = FinancialRules::deliveryEarningsCents(
                $lockedPool->allocation?->shipping_fee,
                $lockedPool->allocation?->protection_amount ?? 0,
            );
            $logisticsShareCents = $breakdown['net_share_cents'];
            $riderPassThroughCents = $breakdown['rider_reserve_cents'];
            $metadata = [
                'payout_reference' => 'LGP-'.Str::upper((string) Str::uuid()),
                'logistics_pool_id' => $lockedPool->id,
                'logistics_center_id' => $lockedPool->logistics_center_id,
                'route_role' => $breakdown['role'],
                'shipment_id' => $lockedPool->shipment_id,
                'amount_expected' => FinancialRules::amount($grossCents),
                'gross_delivery_earnings' => FinancialRules::amount($deliveryTotalCents),
                'logistics_net_share' => FinancialRules::amount($logisticsShareCents),
                'rider_pass_through' => FinancialRules::amount($riderPassThroughCents),
                'base_shipping_fee' => $lockedPool->allocation?->shipping_fee,
                'additional_care_amount' => $lockedPool->allocation?->protection_amount ?? '0.00',
                'amount_paid' => null,
                'currency' => 'PHP',
                'confirmation_status' => 'PAYOUT_REQUESTED',
                'requested_by_user_id' => $actor->id,
                'requested_at' => $now->toIso8601String(),
                'paid_by_user_id' => null,
                'paid_at' => null,
            ];
            $entry = FinancialLedgerEntry::query()->create([
                'shipment_financial_allocation_id' => $lockedPool->shipment_financial_allocation_id,
                'shipment_id' => $lockedPool->shipment_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'LOGISTICS_PAYOUT',
                'direction' => 'CREDIT',
                'amount' => FinancialRules::amount($grossCents),
                'status' => 'PAYOUT_REQUESTED',
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actor->id,
                'posted_at' => $now,
                'metadata' => $metadata,
            ]);
            $lockedPool->update(['status' => 'PAYOUT_REQUESTED']);
            $this->audit('LOGISTICS_PAYOUT_REQUESTED', $entry, $actor, [], $metadata, 'Logistics requested gross delivery charges, including the Rider pass-through share.');

            return $entry;
        }, 3);
    }

    private function assertReconciledRiderCash(RiderEarningPoolParticipant $participant): void
    {
        $collection = $participant->pool?->shipment?->codCollection;
        if ($collection?->status !== 'REMITTED' || $collection?->remittanceItem?->remittance?->status !== 'RECONCILED') {
            throw new \RuntimeException('The Rider cash handoff must be reconciled by Logistics before this earning can be requested or paid.');
        }
        if (! $this->hasConfirmedGrossLogisticsPayout($participant)) {
            throw new \RuntimeException('Admin must release the full delivery charges to Logistics and Logistics must confirm receipt before the Rider payout can be requested or recorded.');
        }
    }

    private function assertFullCodReceived(LogisticsEarningPool $pool, ?FinancialLedgerEntry $proceeds): void
    {
        if (! in_array($pool->shipment?->current_status, ['DELIVERED', 'COMPLETED'], true)
            || ! $pool->shipment?->deliveryAttempts()->where('status', 'DELIVERED')->whereNotNull('proof_path')->exists()) {
            throw new \RuntimeException('A delivered parcel with recorded delivery proof is required before Admin can release delivery charges.');
        }

        $collection = CodCollection::query()
            ->with('remittanceItem.remittance')
            ->where('shipment_id', $pool->shipment_id)
            ->first();
        if (! $collection
            || $collection->status !== CodCollection::REMITTED
            || $collection->remittanceItem?->remittance?->status !== 'RECONCILED') {
            throw new \RuntimeException('The full Rider-to-Logistics COD handoff must be reconciled before Admin can release delivery charges.');
        }
        $fullCodCents = FinancialRules::cents($collection->expected_amount);
        $riderRemittance = $collection->remittanceItem->remittance;
        $destinationId = $pool->shipment?->destination_logistics_center_id ?: $pool->shipment?->logistics_center_id;
        if (! $destinationId || (int) $collection->logistics_center_id !== (int) $destinationId
            || (int) $riderRemittance->logistics_center_id !== (int) $destinationId
            || $collection->collected_amount === null || FinancialRules::cents($collection->collected_amount) !== $fullCodCents
            || $riderRemittance->verified_amount === null
            || FinancialRules::cents($riderRemittance->expected_amount) !== $fullCodCents
            || FinancialRules::cents($riderRemittance->verified_amount) !== $fullCodCents) {
            throw new \RuntimeException('The verified Rider handoff must equal the full COD collected before Admin can release delivery charges.');
        }
        if ($proceeds?->status !== 'RECEIVED') {
            throw new \RuntimeException('Admin must verify receipt of the Logistics-to-Admin COD handoff before releasing delivery charges.');
        }

        if (FinancialRules::cents($proceeds->amount) !== $fullCodCents
            || FinancialRules::cents(data_get($proceeds->metadata, 'amount_received', -1)) !== $fullCodCents
            || ! filled(data_get($proceeds->metadata, 'acknowledged_at'))
            || (int) data_get($proceeds->metadata, 'sender.logistics_center_id') !== (int) $destinationId) {
            throw new \RuntimeException('Admin may release delivery charges only after the full COD amount has been remitted and verified.');
        }
    }

    private function hasConfirmedGrossLogisticsPayout(RiderEarningPoolParticipant $participant): bool
    {
        $pool = $participant->pool;
        $allocation = $pool?->allocation;
        $shipment = $pool?->shipment;
        if (! $pool || ! $allocation || ! $shipment) {
            return false;
        }

        $centerId = $this->payoutCenterIdForParticipant($participant);
        try {
            $logisticsPool = LogisticsEarningPool::query()
                ->with(['shipment', 'allocation'])
                ->where('shipment_financial_allocation_id', $allocation->id)
                ->where('logistics_center_id', $centerId)->first();
            if (! $logisticsPool) {
                return false;
            }
            $proceeds = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $allocation->id)
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')->first();
            $this->assertFullCodReceived($logisticsPool, $proceeds);
            $expected = $logisticsPool->payoutBreakdown()['transfer_cents'];
        } catch (\RuntimeException) {
            return false;
        }

        return FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $pool->shipment_financial_allocation_id)
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->where('status', 'PAID')
            ->where('metadata->logistics_center_id', $centerId)
            ->get()
            ->contains(fn (FinancialLedgerEntry $payout): bool => FinancialRules::cents($payout->amount) === $expected
                && ! $this->isSimulatedPayout((array) $payout->metadata)
                && filled(data_get($payout->metadata, 'payout_external_reference'))
                && filled(data_get($payout->metadata, 'payout_proof_path'))
                && Storage::disk('local')->exists((string) data_get($payout->metadata, 'payout_proof_path'))
                && filled(data_get($payout->metadata, 'acknowledged_at')));
    }

    private function grossDeliveryEarningsCents($allocation): int
    {
        if (! $allocation) {
            throw new \RuntimeException('The parcel delivery charge allocation is unavailable.');
        }

        return FinancialRules::deliveryEarningsCents(
            $allocation->shipping_fee,
            $allocation->protection_amount ?? 0,
        );
    }

    private function assertCenterOwnsParticipant(User $actor, RiderEarningPoolParticipant $participant): void
    {
        $actor->loadMissing('logisticsCenter');
        if (! $actor->logisticsCenter
            || $this->payoutCenterIdForParticipant($participant) !== (int) $actor->logisticsCenter->id) {
            throw new \RuntimeException('This Rider payout does not belong to your Logistics center.');
        }
    }

    private function payoutCenterIdForParticipant(RiderEarningPoolParticipant $participant): int
    {
        $participant->loadMissing('pool.shipment', 'riderProfile');
        $shipment = $participant->pool?->shipment;
        if ($shipment
            && filled($shipment->origin_logistics_center_id)
            && filled($shipment->destination_logistics_center_id)
            && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id) {
            return (int) $shipment->destination_logistics_center_id;
        }

        return (int) ($participant->riderProfile?->logistics_center_id ?? 0);
    }

    private function logisticsPayoutKey(LogisticsEarningPool $pool, string $routeRole): string
    {
        return in_array($routeRole, ['ORIGIN', 'DESTINATION'], true)
            ? 'logistics-payout-pool-'.$pool->id
            : 'logistics-payout-'.$pool->shipment_financial_allocation_id;
    }

    private function refreshRiderPoolTotals(?RiderEarningPool $pool): void
    {
        if (! $pool) {
            return;
        }
        $participants = $pool->participants()->get(['id', 'share_amount', 'status']);
        $participantIds = $participants->pluck('id')->map(fn ($id): int => (int) $id);
        $simulatedParticipantIds = $participantIds->isEmpty()
            ? []
            : FinancialLedgerEntry::query()
                ->where('entry_type', 'RIDER_PAYOUT')
                ->whereIn('idempotency_key', $participantIds->map(fn (int $id): string => 'rider-payout-'.$id))
                ->get(['metadata'])
                ->filter(fn (FinancialLedgerEntry $entry): bool => $this->isSimulatedPayout((array) $entry->metadata))
                ->map(fn (FinancialLedgerEntry $entry): int => (int) data_get($entry->metadata, 'participant_id'))
                ->all();
        $realParticipants = $participants->reject(fn ($participant): bool => in_array((int) $participant->id, $simulatedParticipantIds, true));
        $approved = $realParticipants
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->sum(fn ($participant): int => FinancialRules::cents($participant->share_amount));
        $paid = $realParticipants
            ->where('status', 'PAID')
            ->sum(fn ($participant): int => FinancialRules::cents($participant->share_amount));
        $total = FinancialRules::cents($pool->total_amount);
        $pool->update([
            'approved_amount' => FinancialRules::amount($approved),
            'paid_amount' => FinancialRules::amount($paid),
            'status' => $paid >= $total ? 'PAID' : ($approved > 0 ? 'APPROVED' : 'PENDING'),
            'approved_at' => $approved > 0 ? ($pool->approved_at ?? now()) : null,
            'paid_at' => $paid >= $total && $total > 0 ? now() : null,
        ]);
    }

    private function assertActiveRole(User $actor, string $role): void
    {
        if (! $actor->isActive() || ! $actor->isAccountType($role)) {
            throw new \RuntimeException('Only an active '.$role.' account may perform this payout transition.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function isSimulatedPayout(array $metadata): bool
    {
        return (bool) ($metadata['is_simulated'] ?? false)
            || $this->simulatedPayouts->isSimulationReference((string) ($metadata['payout_external_reference'] ?? ''));
    }

    /** @param array<string, mixed> $metadata */
    private function assertUnusedTransferReference(array $metadata, string $reference): void
    {
        $usedReferences = collect((array) data_get($metadata, 'payout_attempt_history', []))
            ->pluck('payout_external_reference')
            ->filter()
            ->map(fn ($used): string => (string) $used)
            ->all();
        if (in_array(trim($reference), $usedReferences, true)) {
            throw new \RuntimeException('Use a new transaction reference for each transfer attempt.');
        }
    }

    private function assertGloballyUnusedTransferReference(string $reference, ?int $exceptEntryId = null): void
    {
        $query = FinancialLedgerEntry::query()
            ->where('metadata->payout_external_reference', trim($reference));
        if ($exceptEntryId) {
            $query->whereKeyNot($exceptEntryId);
        }
        if ($query->exists() || SellerSettlement::query()->where('payout_external_reference', trim($reference))->exists()) {
            throw new \RuntimeException('This transfer reference is already recorded on another payout. Use the bank or wallet transaction reference for this transfer.');
        }
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $event, FinancialLedgerEntry $entry, User $actor, array $before, array $after, ?string $notes): void
    {
        FinanceAuditEvent::query()->create([
            'event_type' => $event,
            'auditable_type' => FinancialLedgerEntry::class,
            'auditable_id' => $entry->id,
            'actor_user_id' => $actor->id,
            'before_state' => $before,
            'after_state' => $after,
            'notes' => $notes,
        ]);
    }
}
