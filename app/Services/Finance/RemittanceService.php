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
            ->where('status', CodCollection::COLLECTED)
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

        return DB::transaction(function () use ($actor, $rider, $ids, $submittedAmount, $notes): RiderRemittance {
            $lockedRider = RiderProfile::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $collections = CodCollection::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($collections->count() !== count($ids)) {
                throw new RuntimeException('One or more COD collections no longer exist.');
            }
            foreach ($collections as $collection) {
                if ((int) $collection->rider_profile_id !== (int) $lockedRider->id
                    || (int) $collection->logistics_center_id !== (int) $lockedRider->logistics_center_id
                    || $collection->status !== CodCollection::COLLECTED
                    || $collection->remittanceItem()->exists()) {
                    throw new RuntimeException('A selected COD collection is not owned by this rider, is not collectible, or was already remitted.');
                }
            }

            $expectedCents = $collections->sum(fn (CodCollection $collection): int => FinancialRules::cents($collection->expected_amount));
            $submittedCents = FinancialRules::cents($submittedAmount);
            if ($submittedCents !== $expectedCents) {
                throw new RuntimeException('The rider must remit the full server-calculated COD amount. Earnings are not deducted from remittance.');
            }
            $remittance = RiderRemittance::query()->create([
                'rider_profile_id' => $lockedRider->id,
                'logistics_center_id' => $lockedRider->logistics_center_id,
                'remittance_reference' => $this->uniqueReference(),
                'expected_amount' => FinancialRules::amount($expectedCents),
                'submitted_amount' => FinancialRules::amount($submittedCents),
                'status' => RiderRemittance::SUBMITTED,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
                'notes' => $notes,
            ]);
            foreach ($collections as $collection) {
                $remittance->items()->create(['cod_collection_id' => $collection->id]);
                $collection->update(['status' => CodCollection::REMITTED]);
            }
            FinanceAuditEvent::query()->create([
                'event_type' => 'REMITTANCE_SUBMITTED',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $remittance->only(['status', 'expected_amount', 'submitted_amount', 'remittance_reference']),
                'notes' => 'Rider submitted full-cash remittance batch for destination-center verification.',
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
            if ($remittance->status !== RiderRemittance::SUBMITTED) {
                throw new RuntimeException('Only submitted remittances can be verified.');
            }

            $remittance->update([
                'verified_amount' => FinancialRules::amount(FinancialRules::cents($verifiedAmount)),
                'status' => RiderRemittance::VERIFIED,
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'notes' => $notes ?: $remittance->notes,
            ]);
            FinanceAuditEvent::query()->create([
                'event_type' => 'REMITTANCE_VERIFIED',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $remittance->only(['status', 'expected_amount', 'submitted_amount', 'verified_amount']),
                'notes' => 'Destination logistics cashier/admin recorded the physically verified cash amount.',
            ]);

            return $remittance->refresh();
        });
    }

    public function reconcile(User $actor, RiderRemittance $remittance, ?string $notes = null): RiderRemittance
    {
        return DB::transaction(function () use ($actor, $remittance, $notes): RiderRemittance {
            $remittance = RiderRemittance::query()->with('riderProfile.user')->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            $this->assertAuthorizedCenterActor($actor, (int) $remittance->logistics_center_id, 'reconcile');
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
                'event_type' => $remittance->status === RiderRemittance::RECONCILED ? 'REMITTANCE_RECONCILED' : 'REMITTANCE_DISCREPANCY',
                'auditable_type' => RiderRemittance::class,
                'auditable_id' => $remittance->id,
                'actor_user_id' => $actor->id,
                'after_state' => $remittance->only(['status', 'expected_amount', 'submitted_amount', 'verified_amount', 'discrepancy_amount']),
                'notes' => $difference === 0 ? 'Expected and verified cash matched.' : 'Expected and verified cash differ; discrepancy remains open.',
            ]);

            return $remittance->refresh();
        });
    }

    public function uniqueReference(): string
    {
        do {
            $value = 'REM-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (RiderRemittance::query()->where('remittance_reference', $value)->exists());

        return $value;
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
