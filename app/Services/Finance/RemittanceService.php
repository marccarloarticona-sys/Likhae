<?php

namespace App\Services\Finance;

use App\Models\Finance\CodCollection;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\RiderRemittance;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RemittanceService
{
    public function __construct(private readonly FinanceSchemaReadiness $schema) {}

    public function availableCollections(RiderProfile $rider)
    {
        if (! $this->schema->step6Ready()) {
            return collect();
        }

        return CodCollection::query()
            ->where('rider_profile_id', $rider->id)
            ->whereIn('status', [CodCollection::COLLECTED, CodCollection::DISCREPANCY])
            ->whereDoesntHave('remittanceItem')
            ->with(['shipment', 'sellerOrder', 'order'])
            ->latest('collected_at')
            ->get();
    }

    /** @param list<int> $collectionIds */
    public function submit(User $actor, array $collectionIds, string|int|float $submittedAmount, ?string $notes = null): RiderRemittance
    {
        if (! $this->schema->step6Ready()) {
            throw new RuntimeException('Remittance schema is not installed. Apply the additive Step 6 migration first.');
        }
        $rider = $actor->riderProfile;
        if ($actor->status !== User::STATUS_ACTIVE || ! $rider || $rider->status !== 'ACTIVE') {
            throw new RuntimeException('Only an active authenticated rider may submit a remittance.');
        }
        $ids = array_values(array_unique(array_map('intval', $collectionIds)));
        if ($ids === []) {
            throw new RuntimeException('Select at least one collected COD parcel.');
        }
        if (count($ids) !== 1) {
            throw new RuntimeException('Submit one COD parcel per remittance so its receipt and cash discrepancy remain parcel specific.');
        }

        return DB::transaction(function () use ($actor, $rider, $ids, $submittedAmount, $notes): RiderRemittance {
            $lockedRider = RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $collections = CodCollection::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($collections->count() !== count($ids)) {
                throw new RuntimeException('One or more COD collections no longer exist.');
            }
            $existingRemittance = $collections->count() === 1
                ? RiderRemittance::query()->whereHas('items', fn ($query) => $query->where('cod_collection_id', $collections->first()->id))->first()
                : null;
            if ($existingRemittance) {
                if ((int) $existingRemittance->rider_profile_id === (int) $lockedRider->id
                    && FinancialRules::cents($existingRemittance->submitted_amount) === FinancialRules::cents($submittedAmount)) {
                    return $existingRemittance;
                }
                throw new RuntimeException('This parcel already has a remittance with a different amount.');
            }
            foreach ($collections as $collection) {
                if ((int) $collection->rider_profile_id !== (int) $lockedRider->id) {
                    throw new RuntimeException('This COD collection is assigned to a different rider.');
                }
                if ((int) $collection->logistics_center_id !== (int) $lockedRider->logistics_center_id) {
                    throw new RuntimeException('This COD collection belongs to a different logistics center.');
                }
                if (! in_array($collection->status, [CodCollection::COLLECTED, CodCollection::DISCREPANCY], true)) {
                    throw new RuntimeException('This COD collection is not in a remittable status.');
                }
                if ($collection->collected_amount === null) {
                    throw new RuntimeException('The COD collection has no recorded amount.');
                }
                if ($collection->remittanceItem()->exists()) {
                    throw new RuntimeException('This COD collection already has a remittance receipt.');
                }
            }

            $expectedCents = $collections->sum(fn (CodCollection $collection): int => FinancialRules::cents($collection->collected_amount));
            $submittedCents = FinancialRules::cents($submittedAmount);
            $remittance = RiderRemittance::query()->create([
                'rider_profile_id' => $lockedRider->id,
                'logistics_center_id' => $lockedRider->logistics_center_id,
                'remittance_reference' => $this->uniqueReference(),
                'expected_amount' => FinancialRules::amount($expectedCents),
                'submitted_amount' => FinancialRules::amount($submittedCents),
                'discrepancy_amount' => FinancialRules::amount(abs($submittedCents - $expectedCents)),
                'status' => RiderRemittance::SUBMITTED,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
                'notes' => $notes,
            ]);
            foreach ($collections as $collection) {
                $remittance->items()->create(['cod_collection_id' => $collection->id]);
                $collection->update([
                    'status' => $collection->status === CodCollection::DISCREPANCY
                        ? CodCollection::REMITTED_DISCREPANCY
                        : CodCollection::REMITTED,
                ]);
            }
            FinanceAuditEvent::query()->create([
                'event_type' => 'RIDER_TO_LOGISTICS_HANDOFF_SUBMITTED',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $this->handoffReceipt($remittance->load('logisticsCenter', 'items.codCollection.shipment', 'items.codCollection.order'), [
                    'receipt_reference' => 'RCPT-'.$remittance->remittance_reference,
                    'confirmation_status' => RiderRemittance::SUBMITTED,
                    'amount_received' => null,
                    'acknowledged_by_user_id' => null,
                    'acknowledged_at' => null,
                    'submitted_at' => $remittance->submitted_at?->toIso8601String(),
                    'discrepancy_amount' => FinancialRules::amount(abs($submittedCents - $expectedCents)),
                ]),
                'notes' => 'Rider recorded the parcel cash handoff for destination-center acknowledgment.',
            ]);

            return $remittance->refresh();
        });
    }

    public function verify(User $actor, RiderRemittance $remittance, string|int|float $verifiedAmount, ?string $notes = null): RiderRemittance
    {
        return DB::transaction(function () use ($actor, $remittance, $verifiedAmount, $notes): RiderRemittance {
            $remittance = RiderRemittance::query()->with('riderProfile.user')->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertAuthorizedCenterActor($actor, (int) $remittance->logistics_center_id, 'verify');
            if ((int) $remittance->riderProfile?->user_id === (int) $actor->id) {
                throw new RuntimeException('A rider cannot verify their own remittance.');
            }
            if (in_array($remittance->status, [RiderRemittance::VERIFIED, RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY], true)) {
                if (FinancialRules::cents($remittance->verified_amount) === FinancialRules::cents($verifiedAmount)
                    && (int) $remittance->verified_by_user_id === (int) $actor->id) {
                    return $remittance;
                }
                throw new RuntimeException('This remittance was already acknowledged with a different amount or by another Logistics account.');
            }
            if ($remittance->status !== RiderRemittance::SUBMITTED) {
                throw new RuntimeException('Only submitted remittances can be verified.');
            }

            $remittance->update([
                'verified_amount' => FinancialRules::amount(FinancialRules::cents($verifiedAmount)),
                'discrepancy_amount' => FinancialRules::amount(abs(
                    FinancialRules::cents($verifiedAmount) - FinancialRules::cents($remittance->expected_amount),
                )),
                'status' => RiderRemittance::VERIFIED,
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'notes' => $notes ?: $remittance->notes,
            ]);
            FinanceAuditEvent::query()->create([
                'event_type' => 'LOGISTICS_ACKNOWLEDGED_RIDER_HANDOFF',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $this->handoffReceipt($remittance->load('logisticsCenter', 'items.codCollection.shipment', 'items.codCollection.order'), [
                    'receipt_reference' => 'RCPT-'.$remittance->remittance_reference,
                    'confirmation_status' => RiderRemittance::VERIFIED,
                    'amount_received' => $remittance->verified_amount,
                    'acknowledged_by_user_id' => $actor->id,
                    'acknowledged_at' => $remittance->verified_at?->toIso8601String(),
                    'submitted_at' => $remittance->submitted_at?->toIso8601String(),
                    'discrepancy_amount' => FinancialRules::amount(abs(
                        FinancialRules::cents($remittance->verified_amount) - FinancialRules::cents($remittance->expected_amount),
                    )),
                ]),
                'notes' => 'Destination logistics account acknowledged the physical cash received. Reconciliation is still required before funds can advance.',
            ]);

            return $remittance->refresh();
        });
    }

    public function reconcile(User $actor, RiderRemittance $remittance, ?string $notes = null): RiderRemittance
    {
        return DB::transaction(function () use ($actor, $remittance, $notes): RiderRemittance {
            $remittance = RiderRemittance::query()->with('riderProfile.user')->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertAuthorizedCenterActor($actor, (int) $remittance->logistics_center_id, 'reconcile');
            if (in_array($remittance->status, [RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY], true)) {
                return $remittance;
            }
            if ($remittance->status !== RiderRemittance::VERIFIED) {
                throw new RuntimeException('Verify the physical cash before reconciling the remittance.');
            }

            $difference = FinancialRules::cents($remittance->verified_amount) - FinancialRules::cents($remittance->expected_amount);
            $remittance->update([
                'status' => $difference === 0 ? RiderRemittance::RECONCILED : RiderRemittance::DISCREPANCY,
                'discrepancy_amount' => FinancialRules::amount(abs($difference)),
                'notes' => $notes ?: $remittance->notes,
            ]);
            FinanceAuditEvent::query()->create([
                'event_type' => $remittance->status === RiderRemittance::RECONCILED ? 'RIDER_HANDOFF_RECONCILED' : 'RIDER_HANDOFF_DISCREPANCY',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $this->handoffReceipt($remittance->load('logisticsCenter', 'items.codCollection.shipment', 'items.codCollection.order'), [
                    'receipt_reference' => 'RCPT-'.$remittance->remittance_reference,
                    'confirmation_status' => $remittance->status,
                    'amount_received' => $remittance->verified_amount,
                    'acknowledged_by_user_id' => $remittance->verified_by_user_id,
                    'acknowledged_at' => $remittance->verified_at?->toIso8601String(),
                    'submitted_at' => $remittance->submitted_at?->toIso8601String(),
                    'reconciled_by_user_id' => $actor->id,
                    'reconciled_at' => now()->toIso8601String(),
                    'discrepancy_amount' => $remittance->discrepancy_amount,
                ]),
                'notes' => $difference === 0 ? 'Expected and verified cash matched.' : 'Expected and verified cash differ; discrepancy remains open.',
            ]);

            return $remittance->refresh();
        });
    }

    public function resolveDiscrepancy(User $actor, RiderRemittance $remittance, string|int|float $finalReceivedAmount, string $resolution): RiderRemittance
    {
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }

        return DB::transaction(function () use ($actor, $remittance, $finalReceivedAmount, $resolution): RiderRemittance {
            $locked = RiderRemittance::query()->with('riderProfile.user', 'logisticsCenter', 'items.codCollection.shipment', 'items.codCollection.order')
                ->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertAuthorizedCenterActor($actor, (int) $locked->logistics_center_id, 'resolve');
            $priorResolution = FinanceAuditEvent::query()
                ->where('event_type', 'RIDER_HANDOFF_DISCREPANCY_RESOLVED')
                ->where('auditable_type', RiderRemittance::class)
                ->where('auditable_id', $locked->id)->latest('id')->first();
            if ($locked->status === RiderRemittance::RECONCILED && $priorResolution
                && (int) data_get($priorResolution->after_state, 'resolved_by_user_id') === (int) $actor->id
                && data_get($priorResolution->after_state, 'discrepancy_resolution') === trim($resolution)
                && FinancialRules::cents($locked->verified_amount) === FinancialRules::cents($finalReceivedAmount)) {
                return $locked;
            }
            if ($locked->status !== RiderRemittance::DISCREPANCY) {
                throw new RuntimeException('Only an open rider handoff discrepancy can be resolved.');
            }
            $expectedCents = FinancialRules::cents($locked->expected_amount);
            $receivedCents = FinancialRules::cents($finalReceivedAmount);
            if ($receivedCents !== $expectedCents) {
                throw new RuntimeException('The final reconciled amount must equal the remittance expected amount.');
            }
            $before = ['status' => $locked->status, 'verified_amount' => $locked->verified_amount, 'discrepancy_amount' => $locked->discrepancy_amount];
            $locked->update([
                'status' => RiderRemittance::RECONCILED,
                'verified_amount' => FinancialRules::amount($receivedCents),
                'discrepancy_amount' => '0.00',
                'notes' => trim($resolution),
            ]);
            FinanceAuditEvent::query()->create([
                'event_type' => 'RIDER_HANDOFF_DISCREPANCY_RESOLVED',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'before_state' => $before,
                'after_state' => $this->handoffReceipt($locked, [
                    'receipt_reference' => 'RCPT-'.$locked->remittance_reference,
                    'confirmation_status' => RiderRemittance::RECONCILED,
                    'amount_received' => FinancialRules::amount($receivedCents),
                    'acknowledged_by_user_id' => $locked->verified_by_user_id,
                    'acknowledged_at' => $locked->verified_at?->toIso8601String(),
                    'resolved_by_user_id' => $actor->id,
                    'resolved_at' => now()->toIso8601String(),
                    'discrepancy_amount' => '0.00',
                    'discrepancy_resolution' => trim($resolution),
                ]),
                'notes' => trim($resolution),
            ]);

            return $locked->refresh();
        });
    }

    public function uniqueReference(): string
    {
        do {
            $value = 'REM-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (RiderRemittance::query()->where('remittance_reference', $value)->exists());

        return $value;
    }

    /** @param array<string, mixed> $receiptFields
     *  @return array<string, mixed>
     */
    private function handoffReceipt(RiderRemittance $remittance, array $receiptFields): array
    {
        $item = $remittance->items->first();
        $collection = $item?->codCollection;
        $shipment = $collection?->shipment;

        return array_merge([
            'remittance_id' => $remittance->id,
            'remittance_reference' => $remittance->remittance_reference,
            'parcel_id' => $shipment?->id,
            'parcel_reference' => $shipment?->tracking_number,
            'seller_order_id' => $collection?->seller_order_id,
            'order_id' => $collection?->order_id,
            'order_reference' => $collection?->order?->order_number,
            'currency' => 'PHP',
            'amount_expected' => $remittance->expected_amount,
            'amount_submitted' => $remittance->submitted_amount,
            'amount_received' => null,
            'sender' => ['user_id' => $remittance->submitted_by_user_id, 'role' => 'RIDER', 'rider_profile_id' => $remittance->rider_profile_id],
            'recipient' => ['user_id' => $remittance->logisticsCenter?->owner_user_id, 'role' => 'LOGISTICS', 'logistics_center_id' => $remittance->logistics_center_id],
            'submitted_at' => $remittance->submitted_at?->toIso8601String(),
            'confirmation_status' => $remittance->status,
            'discrepancy_amount' => $remittance->discrepancy_amount,
            'discrepancy_resolution' => null,
            'items' => $remittance->items->map(fn ($remittanceItem): array => [
                'parcel_id' => $remittanceItem->codCollection?->shipment_id,
                'parcel_reference' => $remittanceItem->codCollection?->shipment?->tracking_number,
                'seller_order_id' => $remittanceItem->codCollection?->seller_order_id,
                'order_id' => $remittanceItem->codCollection?->order_id,
                'order_reference' => $remittanceItem->codCollection?->order?->order_number,
                'collection_id' => $remittanceItem->cod_collection_id,
                'collection_reference' => $remittanceItem->codCollection?->collection_reference,
                'amount_expected' => $remittanceItem->codCollection?->collected_amount,
                'buyer_cod_expected_amount' => $remittanceItem->codCollection?->expected_amount,
                'buyer_amount_collected' => $remittanceItem->codCollection?->collected_amount,
                'buyer_collection_status' => $remittanceItem->codCollection?->status,
                'currency' => 'PHP',
            ])->values()->all(),
        ], $receiptFields);
    }

    private function assertAuthorizedCenterActor(User $actor, int $centerId, string $action): void
    {
        $actor->loadMissing('logisticsCenter');
        if ($actor->account_type !== User::TYPE_LOGISTICS
            || $actor->status !== User::STATUS_ACTIVE
            || ! $actor->logisticsCenter
            || (int) $actor->logisticsCenter->id !== $centerId) {
            throw new RuntimeException('Only an active authorized destination logistics center account may '.$action.' this remittance.');
        }
    }
}
