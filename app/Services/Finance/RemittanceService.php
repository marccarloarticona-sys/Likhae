<?php

namespace App\Services\Finance;

use App\Models\Finance\CodCollection;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\RiderRemittance;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            $collections = CodCollection::query()->with('shipment')->whereIn('id', $ids)->lockForUpdate()->get();
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
                $destinationCenterId = $collection->shipment?->destination_logistics_center_id ?: $collection->shipment?->logistics_center_id;
                if (! $destinationCenterId || (int) $collection->logistics_center_id !== (int) $destinationCenterId) {
                    throw new RuntimeException('COD must be handed to the parcel destination logistics center.');
                }
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

            // The obligation is the checkout COD snapshot, never the amount
            // entered by the Rider. A short/incorrect declaration must not
            // reduce what Logistics is expected to receive.
            $expectedCents = $collections->sum(fn (CodCollection $collection): int => FinancialRules::cents($collection->expected_amount));
            $submittedCents = FinancialRules::cents($submittedAmount);
            if ($submittedCents < 0) {
                throw new RuntimeException('A cash handoff amount cannot be negative.');
            }
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
            $this->assertDestinationHandoff($remittance);
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
            $verifiedCents = FinancialRules::cents($verifiedAmount);
            if ($verifiedCents < 0) {
                throw new RuntimeException('Verified cash received cannot be negative.');
            }

            $remittance->update([
                'verified_amount' => FinancialRules::amount($verifiedCents),
                'discrepancy_amount' => FinancialRules::amount(abs(
                    $verifiedCents - FinancialRules::cents($remittance->expected_amount),
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
            $this->assertDestinationHandoff($remittance);
            if (in_array($remittance->status, [RiderRemittance::RECONCILED, RiderRemittance::DISCREPANCY], true)) {
                return $remittance;
            }
            if ($remittance->status !== RiderRemittance::VERIFIED) {
                throw new RuntimeException('Verify the physical cash before reconciling the remittance.');
            }

            $expectedCents = FinancialRules::cents($remittance->expected_amount);
            $submittedDifference = FinancialRules::cents($remittance->submitted_amount) - $expectedCents;
            $verifiedDifference = FinancialRules::cents($remittance->verified_amount) - $expectedCents;
            $cleanHandoff = $submittedDifference === 0 && $verifiedDifference === 0;
            $remittance->update([
                'status' => $cleanHandoff ? RiderRemittance::RECONCILED : RiderRemittance::DISCREPANCY,
                'discrepancy_amount' => FinancialRules::amount(max(abs($submittedDifference), abs($verifiedDifference))),
                'notes' => $notes ?: $remittance->notes,
            ]);
            if ($cleanHandoff) {
                foreach ($remittance->items as $item) {
                    $item->codCollection?->update([
                        'confirmed_by_user_id' => $actor->id,
                        'status' => $item->codCollection->status === CodCollection::REMITTED_DISCREPANCY
                            ? CodCollection::REMITTED_DISCREPANCY
                            : CodCollection::REMITTED,
                    ]);
                }
            }
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
                'notes' => $cleanHandoff ? 'Rider declaration, expected COD, and verified cash matched.' : 'The Rider declaration or verified cash differs from full COD; discrepancy remains open.',
            ]);
            if ($cleanHandoff) {
                $this->postRiderToLogisticsLedgerTransfer($remittance, $actor);
            }

            return $remittance->refresh();
        });
    }

    public function resolveDiscrepancy(
        User $actor,
        RiderRemittance $remittance,
        string|int|float $finalReceivedAmount,
        string $resolution,
        ?string $evidencePath = null,
    ): RiderRemittance
    {
        if (trim($resolution) === '') {
            throw new RuntimeException('A resolution note is required.');
        }
        if (! is_string($evidencePath)
            || ! str_starts_with($evidencePath, 'cod-cash-evidence/')
            || ! Storage::disk('local')->exists($evidencePath)) {
            throw new RuntimeException('Upload actual cash evidence before resolving this discrepancy.');
        }

        return DB::transaction(function () use ($actor, $remittance, $finalReceivedAmount, $resolution, $evidencePath): RiderRemittance {
            $locked = RiderRemittance::query()->with('riderProfile.user', 'logisticsCenter', 'items.codCollection.shipment', 'items.codCollection.order')
                ->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            if (! $actor->isActive() || ! $actor->isAccountType(User::TYPE_ADMIN)) {
                throw new RuntimeException('Only an active Admin account may independently resolve a Rider cash discrepancy.');
            }
            $this->assertDestinationHandoff($locked);
            if ((int) $locked->verified_by_user_id === (int) $actor->id) {
                throw new RuntimeException('The account that first verified the cash cannot independently resolve its discrepancy.');
            }
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
            $before = [
                'status' => $locked->status,
                'submitted_amount' => $locked->submitted_amount,
                'verified_amount' => $locked->verified_amount,
                'discrepancy_amount' => $locked->discrepancy_amount,
            ];
            $locked->update([
                'status' => RiderRemittance::RECONCILED,
                'verified_amount' => FinancialRules::amount($receivedCents),
                'discrepancy_amount' => '0.00',
                'notes' => trim($resolution),
            ]);
            foreach ($locked->items as $item) {
                $collection = $item->codCollection;
                if (! $collection) {
                    continue;
                }
                $collection->update([
                    'status' => FinancialRules::cents($collection->collected_amount) === FinancialRules::cents($collection->expected_amount)
                        ? CodCollection::REMITTED
                        : CodCollection::REMITTED_DISCREPANCY,
                ]);
            }
            FinanceAuditEvent::query()->create([
                'event_type' => 'RIDER_HANDOFF_DISCREPANCY_RESOLVED',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $locked->id,
                'actor_user_id' => $actor->id,
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
                    'resolution_evidence_path' => $evidencePath,
                ]),
                'before_state' => $before,
                'notes' => 'Admin independently reviewed the actual cash evidence and resolved the Rider handoff discrepancy: '.trim($resolution),
            ]);
            $this->postRiderToLogisticsLedgerTransfer($locked, $actor);

            return $locked->refresh();
        });
    }

    private function assertDestinationHandoff(RiderRemittance $remittance): void
    {
        $remittance->loadMissing('items.codCollection.shipment');
        if ($remittance->items->isEmpty()) {
            throw new RuntimeException('The remittance has no parcel collection to verify.');
        }
        foreach ($remittance->items as $item) {
            $collection = $item->codCollection;
            $destinationId = $collection?->shipment?->destination_logistics_center_id ?: $collection?->shipment?->logistics_center_id;
            if (! $destinationId || (int) $destinationId !== (int) $remittance->logistics_center_id
                || (int) $collection?->logistics_center_id !== (int) $destinationId) {
                throw new RuntimeException('Only the destination logistics center may receive this parcel COD.');
            }
        }
    }

    private function postRiderToLogisticsLedgerTransfer(RiderRemittance $remittance, User $actor): void
    {
        if (! $this->schema->step7Ready()) {
            return;
        }
        $remittance->loadMissing('items.codCollection');
        $collections = $remittance->items->map(fn ($item) => $item->codCollection)->filter()->values();
        $singleCollection = $collections->count() === 1 ? $collections->first() : null;
        app(FinancialLedgerTransferService::class)->postPair(
            'rider-logistics-remittance-'.$remittance->id,
            'RIDER_LOGISTICS_COD_HANDOFF',
            FinancialRules::cents($remittance->verified_amount),
            'RIDER_COD_CASH',
            'LOGISTICS_COD_CASH',
            $actor,
            [
                'remittance_id' => $remittance->id,
                'remittance_reference' => $remittance->remittance_reference,
                'cod_collection_ids' => $collections->pluck('id')->all(),
                'amount_expected' => $remittance->expected_amount,
                'amount_submitted' => $remittance->submitted_amount,
                'amount_verified' => $remittance->verified_amount,
                'sender' => ['role' => 'RIDER', 'rider_profile_id' => $remittance->rider_profile_id],
                'recipient' => ['role' => 'LOGISTICS', 'logistics_center_id' => $remittance->logistics_center_id],
                'verification_status' => $remittance->status,
            ],
            $singleCollection?->shipment?->financialAllocation?->id,
            $singleCollection?->shipment_id,
            $singleCollection?->seller_order_id,
            $singleCollection?->order_id,
        );
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
                'amount_expected' => $remittanceItem->codCollection?->expected_amount,
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
