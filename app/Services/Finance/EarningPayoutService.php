<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
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
            ->with(['pool.shipment.sellerOrder.order', 'pool.shipment.codCollection'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, FinancialLedgerEntry> */
    public function riderPayoutReceipts(RiderProfile $rider): Collection
    {
        if (! $this->schema->parcelSettlementReady()) {
            return new Collection;
        }
        $participantIds = RiderEarningPoolParticipant::query()
            ->where('rider_profile_id', $rider->id)
            ->where('status', 'PAYOUT_PENDING_ACK')
            ->pluck('id');
        if ($participantIds->isEmpty()) {
            return new Collection;
        }

        return FinancialLedgerEntry::query()
            ->where('entry_type', 'RIDER_PAYOUT')
            ->where('status', 'PAYOUT_PENDING_ACK')
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
                ->with(['pool.shipment.codCollection.remittanceItem.remittance', 'pool.shipment.sellerOrder.order'])
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
                $this->audit('RIDER_EARNING_PAYOUT_REQUESTED', $entry, $actor, [], $metadata, 'Rider requested the allocated share after the cash handoff was reconciled.');
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
    ): FinancialLedgerEntry {
        $this->assertActiveRole($actor, User::TYPE_LOGISTICS);
        $method = trim($method);
        if ($method === '') {
            throw new \RuntimeException('Record whether the Rider payout was manual or online.');
        }

        return DB::transaction(function () use ($actor, $entry, $amount, $method, $externalReference, $notes): FinancialLedgerEntry {
            $locked = FinancialLedgerEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($locked->entry_type !== 'RIDER_PAYOUT') {
                throw new \RuntimeException('This ledger entry is not a Rider payout request.');
            }
            $participant = RiderEarningPoolParticipant::query()
                ->with(['pool.shipment.codCollection.remittanceItem.remittance', 'pool.shipment.sellerOrder.order', 'riderProfile'])
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
                if (FinancialRules::cents($metadata['amount_paid'] ?? -1) === $amountCents
                    && (string) ($metadata['payout_method'] ?? '') === $method
                    && ($externalReference === null || (string) ($metadata['payout_external_reference'] ?? '') === $externalReference)) {
                    return $locked;
                }
                throw new \RuntimeException('This Rider payout was already recorded with different payment details.');
            }
            if ($locked->status !== 'PAYOUT_REQUESTED') {
                throw new \RuntimeException('Only a requested Rider payout can be recorded.');
            }
            $this->assertReconciledRiderCash($participant);
            $simulation = $this->simulatedPayouts->simulate($method);
            if ($simulation) {
                $externalReference = $simulation['external_reference'];
            }

            $now = now();
            $before = ['status' => $locked->status, 'metadata' => $locked->metadata];
            $metadata = array_merge((array) $locked->metadata, [
                'amount_paid' => FinancialRules::amount($amountCents),
                'payout_method' => $method,
                'payout_external_reference' => $externalReference,
                'is_simulated' => $simulation !== null,
                'simulation_notice' => $simulation['notice'] ?? null,
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
            $this->audit('LOGISTICS_RECORDED_RIDER_PAYOUT', $locked, $actor, $before, $metadata, $notes);

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
            $this->audit('RIDER_ACKNOWLEDGED_PAYOUT', $locked, $actor, $before, $metadata, $notes);

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
    ): FinancialLedgerEntry {
        $this->assertActiveRole($actor, User::TYPE_ADMIN);
        $method = trim($method);
        if ($method === '') {
            throw new \RuntimeException('Record whether the Logistics payout was manual or online.');
        }

        return DB::transaction(function () use ($actor, $pool, $amount, $method, $externalReference, $notes): FinancialLedgerEntry {
            $lockedPool = LogisticsEarningPool::query()->with('shipment')->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $proceeds = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $lockedPool->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
                ->lockForUpdate()
                ->first();
            if ($proceeds?->status !== 'RECEIVED') {
                throw new \RuntimeException('Admin can pay the Logistics share after the Logistics COD remittance is received and reconciled.');
            }
            $amountCents = FinancialRules::cents($amount);
            $expectedCents = FinancialRules::cents($lockedPool->total_amount);
            if ($amountCents !== $expectedCents) {
                throw new \RuntimeException('The Logistics payout must equal the approved shipping share exactly.');
            }
            $idempotencyKey = 'logistics-payout-'.$lockedPool->shipment_financial_allocation_id;
            $existing = FinancialLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing && in_array($existing->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                $metadata = (array) $existing->metadata;
                if (FinancialRules::cents($metadata['amount_paid'] ?? -1) === $amountCents
                    && (string) ($metadata['payout_method'] ?? '') === $method
                    && ($externalReference === null || (string) ($metadata['payout_external_reference'] ?? '') === $externalReference)) {
                    return $existing;
                }
                throw new \RuntimeException('A Logistics payout record already exists with different details.');
            }
            if ($existing && ((int) data_get($existing->metadata, 'logistics_pool_id') !== (int) $lockedPool->id || $existing->status !== 'PAYOUT_REQUESTED')) {
                throw new \RuntimeException('A Logistics payout request already exists and needs review.');
            }

            $simulation = $this->simulatedPayouts->simulate($method);
            if ($simulation) {
                $externalReference = $simulation['external_reference'];
            }
            $before = $existing ? ['status' => $existing->status, 'metadata' => $existing->metadata] : [];
            $now = now();
            $metadata = array_merge((array) ($existing?->metadata), [
                'payout_reference' => 'LGP-'.Str::upper((string) Str::uuid()),
                'logistics_pool_id' => $lockedPool->id,
                'logistics_center_id' => $lockedPool->logistics_center_id,
                'shipment_id' => $lockedPool->shipment_id,
                'amount_expected' => FinancialRules::amount($expectedCents),
                'amount_paid' => FinancialRules::amount($amountCents),
                'currency' => 'PHP',
                'payout_method' => $method,
                'payout_external_reference' => $externalReference,
                'is_simulated' => $simulation !== null,
                'simulation_notice' => $simulation['notice'] ?? null,
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
                'approved_amount' => FinancialRules::amount($amountCents),
                'status' => 'PAYOUT_PENDING_ACK',
                'settlement_reference' => $metadata['payout_reference'],
                'approved_by_user_id' => $actor->id,
                'approved_at' => $now,
                'notes' => $notes,
            ]);
            $this->audit('ADMIN_RECORDED_LOGISTICS_PAYOUT', $entry, $actor, $before, $metadata, $notes);

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
            $pool->update(['status' => 'PAID', 'paid_amount' => FinancialRules::amount($amountCents), 'paid_at' => now(), 'notes' => $notes ?: $pool->notes]);
            $this->audit('LOGISTICS_ACKNOWLEDGED_PAYOUT', $locked, $actor, $before, $metadata, $notes);

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
            throw new \RuntimeException('Save your online payout details in Wallet before requesting a payout.');
        }

        return DB::transaction(function () use ($actor, $center, $pool): FinancialLedgerEntry {
            $lockedPool = LogisticsEarningPool::query()->with('shipment')->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedPool->logistics_center_id !== (int) $center->id) {
                throw new \RuntimeException('This parcel payout belongs to a different Logistics center.');
            }
            if ($lockedPool->status === 'PAYOUT_REQUESTED') {
                return FinancialLedgerEntry::query()
                    ->where('idempotency_key', 'logistics-payout-'.$lockedPool->shipment_financial_allocation_id)
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
            if ($proceeds?->status !== 'RECEIVED') {
                throw new \RuntimeException('Request this Logistics share after Admin reconciles the parcel proceeds.');
            }

            $now = now();
            $idempotencyKey = 'logistics-payout-'.$lockedPool->shipment_financial_allocation_id;
            $metadata = [
                'payout_reference' => 'LGP-'.Str::upper((string) Str::uuid()),
                'logistics_pool_id' => $lockedPool->id,
                'logistics_center_id' => $lockedPool->logistics_center_id,
                'shipment_id' => $lockedPool->shipment_id,
                'amount_expected' => (string) $lockedPool->total_amount,
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
                'amount' => $lockedPool->total_amount,
                'status' => 'PAYOUT_REQUESTED',
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actor->id,
                'posted_at' => $now,
                'metadata' => $metadata,
            ]);
            $lockedPool->update(['status' => 'PAYOUT_REQUESTED']);
            $this->audit('LOGISTICS_PAYOUT_REQUESTED', $entry, $actor, [], $metadata, 'Logistics requested its reconciled shipping share.');

            return $entry;
        }, 3);
    }

    private function assertReconciledRiderCash(RiderEarningPoolParticipant $participant): void
    {
        $collection = $participant->pool?->shipment?->codCollection;
        if ($collection?->status !== 'REMITTED' || $collection?->remittanceItem?->remittance?->status !== 'RECONCILED') {
            throw new \RuntimeException('The Rider cash handoff must be reconciled by Logistics before this earning can be requested or paid.');
        }
    }

    private function assertCenterOwnsParticipant(User $actor, RiderEarningPoolParticipant $participant): void
    {
        $actor->loadMissing('logisticsCenter');
        $centerId = (int) ($participant->pool?->shipment?->destination_logistics_center_id
            ?: $participant->pool?->shipment?->logistics_center_id);
        if (! $actor->logisticsCenter || $centerId !== (int) $actor->logisticsCenter->id) {
            throw new \RuntimeException('Only the destination Logistics center can record this Rider payout.');
        }
        if ((int) $participant->riderProfile?->logistics_center_id !== (int) $actor->logisticsCenter->id) {
            throw new \RuntimeException('This Rider payout does not belong to your Logistics center.');
        }
    }

    private function refreshRiderPoolTotals(?RiderEarningPool $pool): void
    {
        if (! $pool) {
            return;
        }
        $participants = $pool->participants()->get(['share_amount', 'status']);
        $approved = $participants
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->sum(fn ($participant): int => FinancialRules::cents($participant->share_amount));
        $paid = $participants
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
