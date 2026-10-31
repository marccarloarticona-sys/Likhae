<?php

namespace App\Console\Commands;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Services\Finance\DeliveryEarningsBreakdown;
use App\Services\Finance\ShippingRatePolicyService;
use App\Support\FinancialRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconcileRiderShippingAllocations extends Command
{
    protected $signature = 'finance:reconcile-rider-shipping-allocations
                            {--apply : Back up and correct unpaid Rider shares}
                            {--target= : Required database name when applying changes}';

    protected $description = 'Preview or reconcile unpaid Rider shipping shares to the 20/40 role split';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        $changes = $this->plannedChanges();
        $logisticsChanges = $this->plannedLogisticsChanges();
        $preservedPaidRoutes = $this->preservedPaidInterHubRoutes();
        $newPreservationAudits = array_values(array_filter($preservedPaidRoutes, fn (array $route): bool => ! $route['audit_exists']));

        if (! $this->option('apply')) {
            $this->displayPlan($changes, $logisticsChanges, $preservedPaidRoutes);

            return self::SUCCESS;
        }

        if (! filled($this->option('target')) || (string) $this->option('target') !== $database) {
            $this->error('Applying changes requires --target='.$database.' to match the configured database. No data was changed.');

            return self::FAILURE;
        }

        if ($changes === [] && $logisticsChanges === [] && $newPreservationAudits === []) {
            $this->info('No unpaid Rider or Inter-Hub Logistics allocations need correction.');

            return self::SUCCESS;
        }

        $backupPath = $this->writeBackup($database, $changes, $logisticsChanges, $newPreservationAudits);
        [$updated, $updatedLogistics, $auditedPreservedRoutes] = DB::transaction(function () use ($changes, $logisticsChanges, $newPreservationAudits): array {
            $updated = 0;
            foreach ($changes as $change) {
                $participant = RiderEarningPoolParticipant::query()
                    ->whereKey($change['participant_id'])
                    ->lockForUpdate()
                    ->firstOrFail();
                if (FinancialRules::cents($participant->share_amount ?? 0) !== $change['before_share_cents']
                    || $participant->status !== $change['participant_status']) {
                    throw new RuntimeException('A Rider share changed after the backup was made. No reconciliation changes were committed.');
                }

                $entry = null;
                if ($change['ledger_id'] !== null) {
                    $entry = FinancialLedgerEntry::query()->whereKey($change['ledger_id'])->lockForUpdate()->firstOrFail();
                    if ($entry->status !== $change['ledger_status']
                        || FinancialRules::cents($entry->amount) !== $change['before_ledger_cents']) {
                        throw new RuntimeException('A Rider payout request changed after the backup was made. No reconciliation changes were committed.');
                    }
                    if (in_array($entry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                        throw new RuntimeException('A Rider transfer was confirmed after the preview. No reconciliation changes were committed.');
                    }
                }

                $now = now();
                $before = [
                    'participant' => [
                        'id' => $participant->id,
                        'rider_earning_pool_id' => $participant->rider_earning_pool_id,
                        'rider_assignment_id' => $participant->rider_assignment_id,
                        'rider_profile_id' => $participant->rider_profile_id,
                        'participant_role' => $participant->participant_role,
                        'share_amount' => $participant->share_amount,
                        'status' => $participant->status,
                    ],
                    'ledger_entry' => $entry ? [
                        'id' => $entry->id,
                        'amount' => $entry->amount,
                        'status' => $entry->status,
                        'metadata' => $entry->metadata,
                    ] : null,
                ];

                $participant->forceFill(['share_amount' => FinancialRules::amount($change['after_share_cents'])])->save();
                if ($entry && $entry->status === 'PAYOUT_REQUESTED') {
                    $metadata = (array) $entry->metadata;
                    $history = (array) data_get($metadata, 'allocation_reconciliation_history', []);
                    $history[] = [
                        'previous_amount' => FinancialRules::amount($change['before_ledger_cents']),
                        'corrected_amount' => FinancialRules::amount($change['after_share_cents']),
                        'policy' => '20% pickup Rider / 40% delivery Rider of shipping fee',
                        'reconciled_at' => $now->toIso8601String(),
                    ];
                    $metadata['amount_expected'] = FinancialRules::amount($change['after_share_cents']);
                    $metadata['allocation_reconciliation_history'] = $history;
                    $entry->update([
                        'amount' => FinancialRules::amount($change['after_share_cents']),
                        'metadata' => $metadata,
                    ]);
                }

                FinanceAuditEvent::query()->create([
                    'event_type' => 'RIDER_SHIPPING_ALLOCATION_RECONCILED',
                    'auditable_type' => RiderEarningPoolParticipant::class,
                    'auditable_id' => $participant->id,
                    'actor_user_id' => null,
                    'before_state' => $before,
                    'after_state' => [
                        'participant' => [
                            'share_amount' => FinancialRules::amount($change['after_share_cents']),
                            'status' => $participant->status,
                        ],
                        'ledger_entry' => $entry ? [
                            'id' => $entry->id,
                            'amount' => $entry->fresh()->amount,
                            'status' => $entry->status,
                        ] : null,
                    ],
                    'notes' => 'Corrected unpaid Rider allocation to 20% Pickup / 40% Delivery of the parcel shipping fee. Additional Care allocation rules remain unchanged.',
                ]);
                $updated++;
            }

            $updatedLogistics = 0;
            foreach ($logisticsChanges as $change) {
                $this->applyLogisticsChange($change);
                $updatedLogistics++;
            }

            $auditedPreservedRoutes = 0;
            foreach ($newPreservationAudits as $route) {
                $this->auditPreservedPaidRoute($route);
                $auditedPreservedRoutes++;
            }

            return [$updated, $updatedLogistics, $auditedPreservedRoutes];
        }, 3);

        $this->info("Corrected {$updated} unpaid Rider shares, {$updatedLogistics} unpaid route allocations, and documented {$auditedPreservedRoutes} protected paid route(s). Backup saved to {$backupPath}");

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function plannedChanges(): array
    {
        $changes = [];
        RiderEarningPool::query()
            ->with(['allocation', 'shipment.riderAssignments', 'shipment.sellerOrder.shippingRateVersion', 'participants'])
            ->orderBy('id')
            ->chunkById(100, function ($pools) use (&$changes): void {
                foreach ($pools as $pool) {
                    $allocation = $pool->allocation;
                    $shipment = $pool->shipment;
                    if (! $allocation || ! $shipment) {
                        continue;
                    }

                    $completedRidersByRole = ['PICKUP' => [], 'HUB_TO_HUB' => [], 'DELIVERY' => []];
                    foreach ($shipment->riderAssignments as $assignment) {
                        if ($assignment->status !== 'COMPLETED' || ! $assignment->rider_profile_id) {
                            continue;
                        }
                        if (! in_array($assignment->assignment_type, ['PICKUP', 'DELIVERY'], true)) {
                            continue;
                        }
                        $completedRidersByRole[$assignment->assignment_type][] = (int) $assignment->rider_profile_id;
                    }

                    $rateVersion = $shipment->sellerOrder?->shippingRateVersion;
                    $weights = [
                        'PICKUP' => (int) ($rateVersion?->pickup_rider_weight ?? ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT),
                        'HUB_TO_HUB' => (int) ($rateVersion?->hub_to_hub_rider_weight ?? ShippingRatePolicyService::DEFAULT_HUB_WEIGHT),
                        'DELIVERY' => (int) ($rateVersion?->last_mile_rider_weight ?? ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT),
                    ];
                    $careCents = max(0, FinancialRules::cents($allocation->protection_amount ?? 0));
                    $distribution = FinancialRules::riderPoolRoleDistribution(
                        FinancialRules::shippingAllocationCents(
                            FinancialRules::cents($allocation->shipping_fee),
                            FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE,
                        )['rider_cents'],
                        FinancialRules::shippingAllocationCents($careCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE)['rider_cents'],
                        $completedRidersByRole,
                        $weights,
                    );
                    $targets = [];
                    foreach ($distribution['participants'] as $target) {
                        $targets[$target['participant_role'].':'.$target['rider_profile_id']] = (int) $target['share_cents'];
                    }

                    $participants = $pool->participants;
                    $entries = FinancialLedgerEntry::query()
                        ->whereIn('idempotency_key', $participants->map(fn ($participant): string => 'rider-payout-'.$participant->id))
                        ->get()
                        ->keyBy('idempotency_key');

                    foreach ($participants as $participant) {
                        if (! in_array($participant->status, ['PENDING', 'PAYOUT_REQUESTED'], true)) {
                            continue;
                        }
                        $key = $participant->participant_role.':'.$participant->rider_profile_id;
                        if (! array_key_exists($key, $targets)) {
                            continue;
                        }
                        $targetCents = $targets[$key];
                        $entry = $entries->get('rider-payout-'.$participant->id);
                        if ($entry && in_array($entry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                            continue;
                        }
                        if ($participant->status === 'PAYOUT_REQUESTED' && (! $entry || $entry->status !== 'PAYOUT_REQUESTED')) {
                            continue;
                        }
                        $beforeShareCents = FinancialRules::cents($participant->share_amount ?? 0);
                        $beforeLedgerCents = $entry ? FinancialRules::cents($entry->amount) : null;
                        if ($beforeShareCents === $targetCents
                            && ($entry === null || $beforeLedgerCents === $targetCents)) {
                            continue;
                        }
                        $changes[] = [
                            'participant_id' => (int) $participant->id,
                            'pool_id' => (int) $pool->id,
                            'shipment_id' => (int) $pool->shipment_id,
                            'role' => $participant->participant_role,
                            'participant_status' => $participant->status,
                            'before_share_cents' => $beforeShareCents,
                            'after_share_cents' => $targetCents,
                            'ledger_id' => $entry ? (int) $entry->id : null,
                            'ledger_status' => $entry?->status,
                            'before_ledger_cents' => $beforeLedgerCents,
                        ];
                    }
                }
            });

        return $changes;
    }

    /** @return list<array<string,mixed>> */
    private function plannedLogisticsChanges(): array
    {
        $changes = [];
        ShipmentFinancialAllocation::query()
            ->with(['shipment', 'logisticsPools'])
            ->orderBy('id')
            ->chunkById(100, function ($allocations) use (&$changes): void {
                foreach ($allocations as $allocation) {
                    $shipment = $allocation->shipment;
                    $originId = (int) ($shipment?->origin_logistics_center_id ?? 0);
                    $destinationId = (int) ($shipment?->destination_logistics_center_id ?? $shipment?->logistics_center_id ?? 0);
                    if (! $shipment || $originId <= 0 || $destinationId <= 0 || $originId === $destinationId) {
                        continue;
                    }

                    $pools = $allocation->logisticsPools->keyBy(fn (LogisticsEarningPool $pool): int => (int) $pool->logistics_center_id);
                    $allocationEntries = FinancialLedgerEntry::query()
                        ->where('shipment_financial_allocation_id', $allocation->id)
                        ->where('entry_type', 'LOGISTICS_ALLOCATION')
                        ->get();
                    $payoutEntries = FinancialLedgerEntry::query()
                        ->where('shipment_financial_allocation_id', $allocation->id)
                        ->where('entry_type', 'LOGISTICS_PAYOUT')
                        ->get();
                    $hasConfirmedTransfer = $pools->contains(fn (LogisticsEarningPool $pool): bool =>
                        FinancialRules::cents($pool->paid_amount ?? 0) > 0
                        || in_array($pool->status, ['PAYOUT_PENDING_ACK', 'PAID'], true))
                        || $payoutEntries->contains(fn (FinancialLedgerEntry $entry): bool =>
                            in_array($entry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true));
                    if ($hasConfirmedTransfer) {
                        // Preserve any route once a transfer was sent or received.
                        continue;
                    }

                    foreach ([$originId, $destinationId] as $centerId) {
                        $expected = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $centerId);
                        $pool = $pools->get($centerId);
                        $siblingCenterId = $centerId === $originId ? $destinationId : $originId;
                        $siblingPool = $pools->get($siblingCenterId);
                        $allocationKey = 'logistics-allocation-'.$allocation->id.'-'.$centerId;
                        $allocationEntry = $allocationEntries->first(function (FinancialLedgerEntry $entry) use (
                            $centerId,
                            $destinationId,
                            $allocationKey,
                            $allocationEntries,
                        ): bool {
                            $entryCenter = (int) data_get($entry->metadata, 'logistics_center_id', 0);

                            return $entry->idempotency_key === $allocationKey
                                || $entryCenter === $centerId
                                || ($entryCenter === 0 && $allocationEntries->count() === 1 && $centerId === $destinationId);
                        });
                        $payoutEntry = $pool
                            ? $payoutEntries->first(fn (FinancialLedgerEntry $entry): bool =>
                                (int) data_get($entry->metadata, 'logistics_pool_id', 0) === (int) $pool->id
                                || $entry->idempotency_key === 'logistics-payout-'.$pool->id)
                            : null;
                        $routeMetadata = [
                            'base_shipping_fee' => (string) $allocation->shipping_fee,
                            'additional_care_amount' => (string) ($allocation->protection_amount ?? '0.00'),
                            'combined_delivery_earnings' => FinancialRules::amount(FinancialRules::deliveryEarningsCents(
                                $allocation->shipping_fee,
                                $allocation->protection_amount ?? 0,
                            )),
                            'logistics_center_id' => $centerId,
                            'route_role' => $expected['role'],
                            'rider_reserve_amount' => FinancialRules::amount($expected['rider_reserve_cents']),
                            'transfer_amount' => FinancialRules::amount($expected['transfer_cents']),
                        ];
                        $existingMetadata = (array) $allocationEntry?->metadata;
                        $targetPoolStatus = $pool?->status ?? $siblingPool?->status ?? 'PENDING';
                        if ($pool?->status === 'REVIEW_REQUIRED' && $siblingPool?->status === 'PENDING') {
                            // Existing Logistics pools remain PENDING even when
                            // seller discount funding needs separate review.
                            $targetPoolStatus = 'PENDING';
                        }
                        $siblingAllocationEntry = $allocationEntries->first(function (FinancialLedgerEntry $entry) use ($siblingCenterId): bool {
                            return (int) data_get($entry->metadata, 'logistics_center_id', 0) === $siblingCenterId;
                        });
                        $targetEntryStatus = $allocationEntry?->status ?? $siblingAllocationEntry?->status ?? 'PENDING';
                        if ($allocationEntry?->status === 'REVIEW_REQUIRED' && $siblingAllocationEntry?->status === 'PENDING') {
                            $targetEntryStatus = 'PENDING';
                        }
                        $metadataMismatch = $allocationEntry === null
                            || collect($routeMetadata)->contains(fn ($value, $key): bool => data_get($existingMetadata, $key) !== $value);
                        $payoutMetadata = (array) $payoutEntry?->metadata;
                        $payoutMetadataMismatch = $payoutEntry && $payoutEntry->status === 'PAYOUT_REQUESTED'
                            && collect($routeMetadata)->contains(fn ($value, $key): bool => data_get($payoutMetadata, $key) !== $value);
                        $needsUpdate = ! $pool
                            || FinancialRules::cents($pool->total_amount ?? 0) !== $expected['net_share_cents']
                            || FinancialRules::cents($pool->unresolved_share_amount ?? 0) !== $expected['net_share_cents']
                            || $allocationEntry === null
                            || FinancialRules::cents($allocationEntry->amount ?? 0) !== $expected['net_share_cents']
                            || $allocationEntry->status !== $targetEntryStatus
                            || $allocationEntry->idempotency_key !== $allocationKey
                            || $metadataMismatch
                            || ($pool && $pool->status !== $targetPoolStatus)
                            || ($payoutEntry && $payoutEntry->status === 'PAYOUT_REQUESTED'
                                && (FinancialRules::cents($payoutEntry->amount) !== $expected['transfer_cents'] || $payoutMetadataMismatch));
                        if (! $needsUpdate) {
                            continue;
                        }

                        $changes[] = [
                            'allocation_id' => (int) $allocation->id,
                            'shipment_id' => (int) $shipment->id,
                            'center_id' => $centerId,
                            'role' => $expected['role'],
                            'net_share_cents' => $expected['net_share_cents'],
                            'transfer_cents' => $expected['transfer_cents'],
                            'rider_reserve_cents' => $expected['rider_reserve_cents'],
                            'pool_id' => $pool ? (int) $pool->id : null,
                            'pool_status' => $pool?->status,
                            'target_pool_status' => $targetPoolStatus,
                            'pool_before' => $pool?->toArray(),
                            'allocation_entry_id' => $allocationEntry ? (int) $allocationEntry->id : null,
                            'allocation_entry_status' => $allocationEntry?->status,
                            'target_entry_status' => $targetEntryStatus,
                            'allocation_entry_key' => $allocationEntry?->idempotency_key,
                            'allocation_entry_before' => $allocationEntry?->toArray(),
                            'payout_entry_id' => $payoutEntry ? (int) $payoutEntry->id : null,
                            'payout_entry_status' => $payoutEntry?->status,
                            'payout_entry_before' => $payoutEntry?->toArray(),
                            'route_metadata' => $routeMetadata,
                        ];
                    }
                }
            });

        return $changes;
    }

    /** @param array<string,mixed> $change */
    private function applyLogisticsChange(array $change): void
    {
        $allocation = ShipmentFinancialAllocation::query()->whereKey($change['allocation_id'])->lockForUpdate()->firstOrFail();
        $shipment = $allocation->shipment()->lockForUpdate()->firstOrFail();
        $pool = $change['pool_id'] !== null
            ? LogisticsEarningPool::query()->whereKey($change['pool_id'])->lockForUpdate()->firstOrFail()
            : null;
        if ($pool) {
            if ($pool->status !== $change['pool_status']
                || FinancialRules::cents($pool->total_amount) !== FinancialRules::cents($change['pool_before']['total_amount'] ?? 0)
                || FinancialRules::cents($pool->paid_amount ?? 0) > 0
                || in_array($pool->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
                throw new RuntimeException('A Logistics route transfer changed after the backup was made. No reconciliation changes were committed.');
            }
        }

        $allocationEntry = $change['allocation_entry_id'] !== null
            ? FinancialLedgerEntry::query()->whereKey($change['allocation_entry_id'])->lockForUpdate()->firstOrFail()
            : null;
        if ($allocationEntry
            && ($allocationEntry->status !== $change['allocation_entry_status']
                || FinancialRules::cents($allocationEntry->amount) !== FinancialRules::cents($change['allocation_entry_before']['amount'] ?? 0))) {
            throw new RuntimeException('A Logistics allocation ledger entry changed after the backup was made. No reconciliation changes were committed.');
        }
        $payoutEntry = $change['payout_entry_id'] !== null
            ? FinancialLedgerEntry::query()->whereKey($change['payout_entry_id'])->lockForUpdate()->firstOrFail()
            : null;
        if ($payoutEntry && in_array($payoutEntry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true)) {
            throw new RuntimeException('A Logistics transfer was confirmed after the preview. No reconciliation changes were committed.');
        }

        $before = [
            'pool' => $change['pool_before'],
            'allocation_entry' => $change['allocation_entry_before'],
            'payout_entry' => $change['payout_entry_before'],
        ];
        if ($pool) {
            $pool->update([
                'total_amount' => FinancialRules::amount($change['net_share_cents']),
                'unresolved_share_amount' => FinancialRules::amount($change['net_share_cents']),
                'status' => $change['target_pool_status'],
            ]);
        } else {
            $pool = LogisticsEarningPool::query()->create([
                'shipment_financial_allocation_id' => $allocation->id,
                'shipment_id' => $shipment->id,
                'logistics_center_id' => $change['center_id'],
                'total_amount' => FinancialRules::amount($change['net_share_cents']),
                'unresolved_share_amount' => FinancialRules::amount($change['net_share_cents']),
                'paid_amount' => '0.00',
                'status' => $change['target_pool_status'],
            ]);
        }

        $allocationKey = 'logistics-allocation-'.$allocation->id.'-'.$change['center_id'];
        if (FinancialLedgerEntry::query()->where('idempotency_key', $allocationKey)
            ->when($allocationEntry, fn ($query) => $query->whereKeyNot($allocationEntry->id))
            ->exists()) {
            throw new RuntimeException('A duplicate Logistics allocation idempotency key exists. No reconciliation changes were committed.');
        }
        $allocationMetadata = array_merge((array) $allocationEntry?->metadata, $change['route_metadata'], [
            'earning_recipient' => 'LOGISTICS',
        ]);
        if ($allocationEntry) {
            $allocationEntry->update([
                'amount' => FinancialRules::amount($change['net_share_cents']),
                'idempotency_key' => $allocationKey,
                'status' => $change['target_entry_status'],
                'metadata' => $allocationMetadata,
            ]);
        } else {
            $allocationEntry = FinancialLedgerEntry::query()->create([
                'shipment_financial_allocation_id' => $allocation->id,
                'shipment_id' => $shipment->id,
                'seller_order_id' => $shipment->seller_order_id,
                'order_id' => $allocation->order_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'LOGISTICS_ALLOCATION',
                'direction' => 'CREDIT',
                'amount' => FinancialRules::amount($change['net_share_cents']),
                'status' => $change['target_entry_status'],
                'idempotency_key' => $allocationKey,
                'posted_at' => now(),
                'metadata' => $allocationMetadata,
            ]);
        }

        if ($payoutEntry && $payoutEntry->status === 'PAYOUT_REQUESTED') {
            $payoutMetadata = array_merge((array) $payoutEntry->metadata, $change['route_metadata'], [
                'amount_expected' => FinancialRules::amount($change['transfer_cents']),
                'logistics_pool_id' => $pool->id,
            ]);
            $payoutEntry->update([
                'amount' => FinancialRules::amount($change['transfer_cents']),
                'metadata' => $payoutMetadata,
            ]);
        }

        FinanceAuditEvent::query()->create([
            'event_type' => 'LOGISTICS_ROUTE_ALLOCATION_RECONCILED',
            'auditable_type' => LogisticsEarningPool::class,
            'auditable_id' => $pool->id,
            'actor_user_id' => null,
            'before_state' => $before,
            'after_state' => [
                'pool' => $pool->fresh()->toArray(),
                'allocation_entry' => $allocationEntry->fresh()->toArray(),
                'payout_entry' => $payoutEntry?->fresh()?->toArray(),
            ],
            'notes' => 'Corrected unpaid Inter-Hub Logistics route allocation to Origin 30% / Destination 10% of shipping fee, with the 60% Rider reserve paid through Destination Logistics. Additional Care follows existing allocation rules.',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function preservedPaidInterHubRoutes(): array
    {
        $routes = [];
        ShipmentFinancialAllocation::query()
            ->with(['shipment', 'logisticsPools'])
            ->orderBy('id')
            ->chunkById(100, function ($allocations) use (&$routes): void {
                foreach ($allocations as $allocation) {
                    $shipment = $allocation->shipment;
                    $originId = (int) ($shipment?->origin_logistics_center_id ?? 0);
                    $destinationId = (int) ($shipment?->destination_logistics_center_id ?? $shipment?->logistics_center_id ?? 0);
                    if (! $shipment || $originId <= 0 || $destinationId <= 0 || $originId === $destinationId) {
                        continue;
                    }
                    $payouts = FinancialLedgerEntry::query()
                        ->where('shipment_financial_allocation_id', $allocation->id)
                        ->where('entry_type', 'LOGISTICS_PAYOUT')
                        ->get();
                    $pools = $allocation->logisticsPools;
                    $protected = $pools->contains(fn (LogisticsEarningPool $pool): bool =>
                        FinancialRules::cents($pool->paid_amount ?? 0) > 0
                        || in_array($pool->status, ['PAYOUT_PENDING_ACK', 'PAID'], true))
                        || $payouts->contains(fn (FinancialLedgerEntry $entry): bool =>
                            in_array($entry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true));
                    if (! $protected) {
                        continue;
                    }

                    $expected = [];
                    foreach ([$originId, $destinationId] as $centerId) {
                        $split = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $centerId);
                        $expected[$centerId] = [
                            'role' => $split['role'],
                            'net_share' => FinancialRules::amount($split['net_share_cents']),
                            'admin_transfer' => FinancialRules::amount($split['transfer_cents']),
                            'rider_reserve' => FinancialRules::amount($split['rider_reserve_cents']),
                        ];
                    }
                    $poolSnapshots = $pools->map(fn (LogisticsEarningPool $pool): array => [
                        'pool_id' => $pool->id,
                        'center_id' => $pool->logistics_center_id,
                        'net_share' => $pool->total_amount,
                        'paid_amount' => $pool->paid_amount,
                        'status' => $pool->status,
                    ])->values()->all();
                    $confirmedTransfers = $payouts
                        ->filter(fn (FinancialLedgerEntry $entry): bool => in_array($entry->status, ['PAYOUT_PENDING_ACK', 'PAID'], true))
                        ->map(fn (FinancialLedgerEntry $entry): array => [
                            'entry_id' => $entry->id,
                            'center_id' => data_get($entry->metadata, 'logistics_center_id'),
                            'amount' => $entry->amount,
                            'status' => $entry->status,
                        ])->values()->all();
                    $currentCenters = $pools->pluck('logistics_center_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                    $expectedCenters = [$originId, $destinationId];
                    sort($expectedCenters);
                    $mismatch = $currentCenters !== $expectedCenters
                        || $pools->contains(fn (LogisticsEarningPool $pool): bool =>
                            ! isset($expected[(int) $pool->logistics_center_id])
                            || FinancialRules::cents($pool->total_amount) !== FinancialRules::cents($expected[(int) $pool->logistics_center_id]['net_share']))
                        || collect($confirmedTransfers)->contains(fn (array $entry): bool =>
                            isset($expected[(int) ($entry['center_id'] ?? 0)])
                            && FinancialRules::cents($entry['amount']) !== FinancialRules::cents($expected[(int) $entry['center_id']]['admin_transfer']));
                    if (! $mismatch) {
                        continue;
                    }

                    $beforeState = [
                        'pools' => $poolSnapshots,
                        'confirmed_transfers' => $confirmedTransfers,
                    ];
                    $routes[] = [
                        'allocation_id' => (int) $allocation->id,
                        'shipment_id' => (int) $shipment->id,
                        'before_state' => $beforeState,
                        'expected_route' => $expected,
                        'audit_exists' => FinanceAuditEvent::query()
                            ->where('event_type', 'LOGISTICS_PAID_LEGACY_ROUTE_PRESERVED')
                            ->where('auditable_type', ShipmentFinancialAllocation::class)
                            ->where('auditable_id', $allocation->id)
                            ->exists(),
                    ];
                }
            });

        return $routes;
    }

    /** @param array<string,mixed> $route */
    private function auditPreservedPaidRoute(array $route): void
    {
        FinanceAuditEvent::query()->firstOrCreate(
            [
                'event_type' => 'LOGISTICS_PAID_LEGACY_ROUTE_PRESERVED',
                'auditable_type' => ShipmentFinancialAllocation::class,
                'auditable_id' => $route['allocation_id'],
            ],
            [
                'actor_user_id' => null,
                'before_state' => $route['before_state'],
                'after_state' => [
                    'financial_records_changed' => false,
                    'current_route' => $route['before_state'],
                    'new_route_policy' => $route['expected_route'],
                ],
                'notes' => 'Preserved a verified historical Inter-Hub transfer because changing a paid record would rewrite actual payment history. Future and unpaid routes use Rider 60%, Origin Logistics 30%, and Destination Logistics 10%; this parcel needs a separate verified settlement adjustment if it is to be brought to that split.',
            ],
        );
    }

    /** @param list<array<string,mixed>> $changes @param list<array<string,mixed>> $logisticsChanges @param list<array<string,mixed>> $preservedPaidRoutes */
    private function displayPlan(array $changes, array $logisticsChanges, array $preservedPaidRoutes): void
    {
        if ($changes === [] && $logisticsChanges === [] && $preservedPaidRoutes === []) {
            $this->info('No unpaid Rider or Inter-Hub Logistics allocations need correction.');

            return;
        }

        $this->table(
            ['Shipment', 'Participant', 'Role', 'Status', 'Current', 'Corrected', 'Payout request'],
            array_map(fn (array $change): array => [
                $change['shipment_id'],
                $change['participant_id'],
                $change['role'],
                $change['participant_status'],
                FinancialRules::amount($change['before_share_cents']),
                FinancialRules::amount($change['after_share_cents']),
                $change['ledger_status'] ?? 'none',
            ], $changes),
        );
        if ($logisticsChanges !== []) {
            $this->newLine();
            $this->line('Unpaid Inter-Hub Logistics routes:');
            $this->table(
                ['Shipment', 'Center', 'Route role', 'Current net', 'Correct net', 'Admin transfer', 'Rider reserve', 'Pool'],
                array_map(fn (array $change): array => [
                    $change['shipment_id'],
                    $change['center_id'],
                    $change['role'],
                    $change['pool_before']['total_amount'] ?? 'missing',
                    FinancialRules::amount($change['net_share_cents']),
                    FinancialRules::amount($change['transfer_cents']),
                    FinancialRules::amount($change['rider_reserve_cents']),
                    $change['pool_id'] ?? 'create',
                ], $logisticsChanges),
            );
        }
        if ($preservedPaidRoutes !== []) {
            $this->newLine();
            $this->line('Protected paid Inter-Hub routes (will remain unchanged):');
            $this->table(
                ['Shipment', 'Current confirmed transfers', 'Expected Origin transfer', 'Expected Destination transfer', 'Audit'],
                array_map(fn (array $route): array => [
                    $route['shipment_id'],
                    collect($route['before_state']['confirmed_transfers'])->map(fn (array $entry): string => $entry['amount'].' '.$entry['status'])->implode(', '),
                    $route['expected_route'][array_key_first($route['expected_route'])]['admin_transfer'] ?? '—',
                    $route['expected_route'][array_key_last($route['expected_route'])]['admin_transfer'] ?? '—',
                    $route['audit_exists'] ? 'already recorded' : 'record decision on apply',
                ], $preservedPaidRoutes),
            );
        }
        $this->line('Dry run only. Use --apply --target=<configured database name> after reviewing the plan.');
    }

    /** @param list<array<string,mixed>> $changes @param list<array<string,mixed>> $logisticsChanges @param list<array<string,mixed>> $preservedPaidRoutes */
    private function writeBackup(string $database, array $changes, array $logisticsChanges, array $preservedPaidRoutes): string
    {
        $participantIds = array_column($changes, 'participant_id');
        $ledgerIds = array_values(array_filter(array_column($changes, 'ledger_id')));
        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'database' => $database,
            'reason' => 'Rider and Inter-Hub Logistics allocation correction: Pickup 20%, Delivery 40%, Origin 30%, Destination 10%',
            'participants' => RiderEarningPoolParticipant::query()->whereIn('id', $participantIds)->get()->toArray(),
            'ledger_entries' => FinancialLedgerEntry::query()->whereIn('id', $ledgerIds)->get()->toArray(),
            'inter_hub_logistics_changes' => array_map(fn (array $change): array => [
                'allocation_id' => $change['allocation_id'],
                'shipment_id' => $change['shipment_id'],
                'center_id' => $change['center_id'],
                'pool_before' => $change['pool_before'],
                'allocation_entry_before' => $change['allocation_entry_before'],
                'payout_entry_before' => $change['payout_entry_before'],
                'route_target' => [
                    'role' => $change['role'],
                    'net_share_cents' => $change['net_share_cents'],
                    'transfer_cents' => $change['transfer_cents'],
                    'rider_reserve_cents' => $change['rider_reserve_cents'],
                ],
            ], $logisticsChanges),
            'preserved_paid_inter_hub_routes' => array_map(fn (array $route): array => [
                'allocation_id' => $route['allocation_id'],
                'shipment_id' => $route['shipment_id'],
                'before_state' => $route['before_state'],
                'expected_route' => $route['expected_route'],
            ], $preservedPaidRoutes),
        ];
        $directory = storage_path('app/private/finance-reconciliation');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private finance backup directory. No data was changed.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'rider-shipping-allocation-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $written = file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Could not write the finance backup. No data was changed.');
        }

        return $path;
    }
}
