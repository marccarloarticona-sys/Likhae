<?php

namespace App\Console\Commands;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Logistics\Shipment;
use App\Services\Finance\DeliveryEarningsBreakdown;
use App\Support\FinancialRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconcilePaidInterhubRoute extends Command
{
    protected $signature = 'finance:reconcile-paid-interhub-route
                            {shipment : Shipment ID to reconcile}
                            {--apply : Back up and open the verified route reallocation}
                            {--target= : Required database name when applying changes}';

    protected $description = 'Reclassify a paid Inter-Hub route to the current policy while preserving the actual payout';

    public function handle(): int
    {
        $shipmentId = (int) $this->argument('shipment');
        if ($shipmentId <= 0) {
            $this->error('A valid shipment ID is required.');

            return self::FAILURE;
        }

        $plan = $this->plannedAdjustment($shipmentId);
        if ($plan === null) {
            $this->info('No new route adjustment is needed. Any existing adjustment entries were left unchanged.');

            return self::SUCCESS;
        }

        $this->displayPlan($plan);
        if (! $this->option('apply')) {
            $this->line('Dry run only. Apply with --apply --target='.$plan['database'].'.');

            return self::SUCCESS;
        }

        if ((string) $this->option('target') !== $plan['database']) {
            $this->error('Applying changes requires --target='.$plan['database'].' to match the configured database. No data was changed.');

            return self::FAILURE;
        }

        $backupPath = $this->writeBackup($plan);
        DB::transaction(function () use ($plan): void {
            $this->applyAdjustment($plan);
        }, 3);

        $this->info('Opened the PHP '.FinancialRules::amount($plan['amount_cents']).' route reallocation as outstanding. Confirmed cash payouts and COD remittance amounts were preserved. Backup saved to '.$backupPath);

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    private function plannedAdjustment(int $shipmentId): ?array
    {
        $allocation = ShipmentFinancialAllocation::query()
            ->with(['shipment', 'logisticsPools'])
            ->where('shipment_id', $shipmentId)
            ->first();
        if (! $allocation || ! $allocation->shipment) {
            throw new RuntimeException('No financial allocation was found for that shipment.');
        }

        $shipment = $allocation->shipment;
        $originId = (int) ($shipment->origin_logistics_center_id ?? 0);
        $destinationId = (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id ?? 0);
        if ($originId <= 0 || $destinationId <= 0 || $originId === $destinationId) {
            throw new RuntimeException('This shipment does not have a valid Inter-Hub route.');
        }

        $existingAdjustments = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_ROUTE_POLICY_ADJUSTMENT')
            ->get();
        if ($existingAdjustments->isNotEmpty()) {
            return null;
        }

        $centers = [$originId, $destinationId];
        $pools = $allocation->logisticsPools->keyBy(fn (LogisticsEarningPool $pool): int => (int) $pool->logistics_center_id);
        $expected = [];
        $actual = [];
        foreach ($centers as $centerId) {
            $breakdown = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $centerId);
            $expected[$centerId] = $breakdown;
            $actual[$centerId] = FinancialRules::cents($pools->get($centerId)?->total_amount ?? 0);
        }

        $overpaidCenters = [];
        $underallocatedCenters = [];
        foreach ($centers as $centerId) {
            $difference = $expected[$centerId]['net_share_cents'] - $actual[$centerId];
            if ($difference < 0) {
                $overpaidCenters[$centerId] = -$difference;
            } elseif ($difference > 0) {
                $underallocatedCenters[$centerId] = $difference;
            }
        }
        if ($overpaidCenters === [] && $underallocatedCenters === []) {
            return null;
        }
        if (count($overpaidCenters) !== 1 || count($underallocatedCenters) !== 1) {
            throw new RuntimeException('This route needs a manual multi-party review; no records were changed.');
        }

        $fromCenterId = (int) array_key_first($overpaidCenters);
        $toCenterId = (int) array_key_first($underallocatedCenters);
        $amountCents = (int) $overpaidCenters[$fromCenterId];
        if ($amountCents !== (int) $underallocatedCenters[$toCenterId]) {
            throw new RuntimeException('The current and expected route totals do not balance; no records were changed.');
        }

        $sourcePool = $pools->get($fromCenterId);
        if (! $sourcePool || FinancialRules::cents($sourcePool->paid_amount ?? 0) < $amountCents) {
            throw new RuntimeException('The overallocated route share is not backed by a confirmed payout; no records were changed.');
        }

        $sourcePayouts = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->where('metadata->logistics_center_id', $fromCenterId)
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->orderBy('id')
            ->get();
        if ($sourcePayouts->isEmpty()) {
            throw new RuntimeException('No confirmed payout receipt backs the overallocated route share; no records were changed.');
        }

        $allocationEntries = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_ALLOCATION')
            ->orderBy('id')
            ->get();
        $remittanceEntries = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->orderBy('id')
            ->get();

        $centerRows = DB::table('logistics_centers')
            ->whereIn('id', $centers)
            ->get(['id', 'code', 'business_name'])
            ->keyBy(fn ($center): int => (int) $center->id);

        return [
            'database' => (string) DB::connection()->getDatabaseName(),
            'allocation_id' => (int) $allocation->id,
            'shipment_id' => $shipmentId,
            'origin_center_id' => $originId,
            'destination_center_id' => $destinationId,
            'from_center_id' => $fromCenterId,
            'to_center_id' => $toCenterId,
            'from_center' => $centerRows->get($fromCenterId)?->business_name ?? 'Logistics '.$fromCenterId,
            'from_center_code' => $centerRows->get($fromCenterId)?->code ?? (string) $fromCenterId,
            'to_center' => $centerRows->get($toCenterId)?->business_name ?? 'Logistics '.$toCenterId,
            'to_center_code' => $centerRows->get($toCenterId)?->code ?? (string) $toCenterId,
            'amount_cents' => $amountCents,
            'expected' => $expected,
            'pools_before' => $pools->values()->map(fn (LogisticsEarningPool $pool): array => $pool->toArray())->all(),
            'allocation_entries_before' => $allocationEntries->map(fn (FinancialLedgerEntry $entry): array => $entry->toArray())->all(),
            'payouts_before' => $sourcePayouts->map(fn (FinancialLedgerEntry $entry): array => $entry->toArray())->all(),
            'remittances_before' => $remittanceEntries->map(fn (FinancialLedgerEntry $entry): array => $entry->toArray())->all(),
            'allocation_before' => $allocation->toArray(),
        ];
    }

    /** @param array<string, mixed> $plan */
    private function displayPlan(array $plan): void
    {
        $this->table(
            ['Route role', 'Logistics center', 'Current allocation', 'Current-policy allocation'],
            collect($plan['expected'])->map(function (array $breakdown, int $centerId) use ($plan): array {
                $before = collect($plan['pools_before'])->first(fn (array $pool): bool => (int) $pool['logistics_center_id'] === $centerId);
                $centerName = $centerId === $plan['origin_center_id'] ? 'Origin' : 'Destination';

                return [
                    $centerName,
                    $centerId === $plan['from_center_id'] ? $plan['from_center'] : ($centerId === $plan['to_center_id'] ? $plan['to_center'] : 'Center '.$centerId),
                    'PHP '.($before['total_amount'] ?? '0.00'),
                    'PHP '.FinancialRules::amount($breakdown['net_share_cents']),
                ];
            })->values()->all(),
        );
        $this->line('Outstanding reallocation: PHP '.FinancialRules::amount($plan['amount_cents']).' from '.$plan['from_center'].' to '.$plan['to_center'].'.');
        $this->line('Recorded payout receipts and COD remittance totals stay unchanged; affected Logistics pools will require review until the reallocation is verified.');
    }

    /** @param array<string, mixed> $plan */
    private function writeBackup(array $plan): string
    {
        $directory = storage_path('app/private/finance-reconciliation');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private finance backup directory. No data was changed.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.'paid-interhub-route-'.$plan['shipment_id'].'-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'database' => $plan['database'],
            'reason' => 'Open current-policy inter-hub reallocation; preserve confirmed payment history',
            'shipment_id' => $plan['shipment_id'],
            'allocation_id' => $plan['allocation_id'],
            'before_state' => [
                'allocation' => $plan['allocation_before'],
                'logistics_pools' => $plan['pools_before'],
                'allocation_entries' => $plan['allocation_entries_before'],
                'confirmed_payouts' => $plan['payouts_before'],
                'product_proceeds_remittances' => $plan['remittances_before'],
            ],
            'target' => [
                'from_center_id' => $plan['from_center_id'],
                'to_center_id' => $plan['to_center_id'],
                'amount_cents' => $plan['amount_cents'],
                'expected_route' => $plan['expected'],
            ],
        ];
        $written = file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Could not write the private finance backup. No data was changed.');
        }

        return $path;
    }

    /** @param array<string, mixed> $plan */
    private function applyAdjustment(array $plan): void
    {
        $allocation = ShipmentFinancialAllocation::query()->whereKey($plan['allocation_id'])->lockForUpdate()->firstOrFail();
        $shipment = Shipment::query()->whereKey($plan['shipment_id'])->lockForUpdate()->firstOrFail();
        $pools = LogisticsEarningPool::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (LogisticsEarningPool $pool): int => (int) $pool->logistics_center_id);
        $allocationEntries = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_ALLOCATION')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        $sourcePayouts = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'LOGISTICS_PAYOUT')
            ->where('metadata->logistics_center_id', $plan['from_center_id'])
            ->whereIn('status', ['PAYOUT_PENDING_ACK', 'PAID'])
            ->lockForUpdate()
            ->get();
        $adjustmentKeys = [
            'route-policy-adjustment-'.$allocation->id.'-debit',
            'route-policy-adjustment-'.$allocation->id.'-credit',
        ];
        if (FinancialLedgerEntry::query()->whereIn('idempotency_key', $adjustmentKeys)->exists()) {
            throw new RuntimeException('A route adjustment appeared after the backup was made. No reconciliation changes were committed.');
        }

        if (FinancialRules::cents($allocation->shipping_fee) !== FinancialRules::cents($plan['allocation_before']['shipping_fee'] ?? 0)
            || (int) $shipment->origin_logistics_center_id !== (int) $plan['origin_center_id']
            || (int) ($shipment->destination_logistics_center_id ?? $shipment->logistics_center_id) !== (int) $plan['destination_center_id']) {
            throw new RuntimeException('The shipment route or allocation changed after the backup was made. No reconciliation changes were committed.');
        }

        if ($pools->count() !== count($plan['pools_before'])) {
            throw new RuntimeException('A Logistics pool changed after the backup was made. No reconciliation changes were committed.');
        }
        foreach ($plan['pools_before'] as $beforePool) {
            $currentPool = $pools->get((int) $beforePool['logistics_center_id']);
            if (! $currentPool
                || (int) $currentPool->id !== (int) $beforePool['id']
                || $currentPool->status !== $beforePool['status']
                || FinancialRules::cents($currentPool->total_amount) !== FinancialRules::cents($beforePool['total_amount'])
                || FinancialRules::cents($currentPool->paid_amount ?? 0) !== FinancialRules::cents($beforePool['paid_amount'] ?? 0)) {
                throw new RuntimeException('A Logistics pool changed after the backup was made. No reconciliation changes were committed.');
            }
        }

        if ($allocationEntries->count() !== count($plan['allocation_entries_before'])) {
            throw new RuntimeException('A Logistics allocation entry changed after the backup was made. No reconciliation changes were committed.');
        }
        foreach ($plan['allocation_entries_before'] as $beforeEntry) {
            $currentEntry = $allocationEntries->firstWhere('id', (int) $beforeEntry['id']);
            if (! $currentEntry
                || $currentEntry->status !== $beforeEntry['status']
                || $currentEntry->idempotency_key !== $beforeEntry['idempotency_key']
                || FinancialRules::cents($currentEntry->amount) !== FinancialRules::cents($beforeEntry['amount'])) {
                throw new RuntimeException('A Logistics allocation entry changed after the backup was made. No reconciliation changes were committed.');
            }
        }

        $beforePayouts = collect($plan['payouts_before'])->keyBy('id');
        if ($sourcePayouts->count() !== $beforePayouts->count()) {
            throw new RuntimeException('A confirmed payout changed after the backup was made. No reconciliation changes were committed.');
        }
        foreach ($sourcePayouts as $payout) {
            $beforePayout = $beforePayouts->get($payout->id);
            if (! $beforePayout
                || $payout->status !== $beforePayout['status']
                || FinancialRules::cents($payout->amount) !== FinancialRules::cents($beforePayout['amount'])) {
                throw new RuntimeException('A confirmed payout changed after the backup was made. No reconciliation changes were committed.');
            }
        }

        $remittanceEntries = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
        if ($remittanceEntries->count() !== count($plan['remittances_before'])) {
            throw new RuntimeException('A COD proceeds remittance changed after the backup was made. No reconciliation changes were committed.');
        }
        foreach ($plan['remittances_before'] as $beforeRemittance) {
            $currentRemittance = $remittanceEntries->firstWhere('id', (int) $beforeRemittance['id']);
            if (! $currentRemittance
                || $currentRemittance->status !== $beforeRemittance['status']
                || FinancialRules::cents($currentRemittance->amount) !== FinancialRules::cents($beforeRemittance['amount'])) {
                throw new RuntimeException('A COD proceeds remittance changed after the backup was made. No reconciliation changes were committed.');
            }
        }

        $sourcePoolBefore = collect($plan['pools_before'])->first(fn (array $pool): bool => (int) $pool['logistics_center_id'] === $plan['from_center_id']);
        $sourcePool = $pools->get($plan['from_center_id']);
        if (! $sourcePool
            || ! $sourcePoolBefore
            || $sourcePool->status !== $sourcePoolBefore['status']
            || FinancialRules::cents($sourcePool->total_amount) !== FinancialRules::cents($sourcePoolBefore['total_amount'])
            || FinancialRules::cents($sourcePool->paid_amount) !== FinancialRules::cents($sourcePoolBefore['paid_amount'] ?? 0)
            || $sourcePayouts->isEmpty()) {
            throw new RuntimeException('A source pool or confirmed transfer changed after the backup was made. No reconciliation changes were committed.');
        }

        $routeCenters = [$plan['origin_center_id'], $plan['destination_center_id']];
        foreach ($routeCenters as $centerId) {
            $expected = $plan['expected'][$centerId];
            $pool = $pools->get($centerId);
            $note = 'Current-policy route reallocation of PHP '.FinancialRules::amount($plan['amount_cents']).' from '.$plan['from_center'].' to '.$plan['to_center'].' is still outstanding. Confirmed payout history is preserved; payout is held for finance review.';
            if ($pool) {
                $pool->update([
                    'total_amount' => FinancialRules::amount($expected['net_share_cents']),
                    'unresolved_share_amount' => FinancialRules::amount(max(0, $expected['net_share_cents'] - FinancialRules::cents($pool->paid_amount ?? 0))),
                    'status' => 'REVIEW_REQUIRED',
                    'notes' => trim(implode("\n", array_filter([$pool->notes, $note]))),
                ]);
            } else {
                $pool = LogisticsEarningPool::query()->create([
                    'shipment_financial_allocation_id' => $allocation->id,
                    'shipment_id' => $shipment->id,
                    'logistics_center_id' => $centerId,
                    'total_amount' => FinancialRules::amount($expected['net_share_cents']),
                    'approved_amount' => null,
                    'paid_amount' => '0.00',
                    'unresolved_share_amount' => FinancialRules::amount($expected['net_share_cents']),
                    'status' => 'REVIEW_REQUIRED',
                    'notes' => $note,
                ]);
                $pools->put($centerId, $pool);
            }

            $allocationKey = 'logistics-allocation-'.$allocation->id.'-'.$centerId;
            $entry = $allocationEntries->first(fn (FinancialLedgerEntry $candidate): bool =>
                (int) data_get($candidate->metadata, 'logistics_center_id', 0) === $centerId
                || ($centerId === $plan['destination_center_id']
                    && data_get($candidate->metadata, 'logistics_center_id') === null
                    && $allocationEntries->count() === 1));
            if (FinancialLedgerEntry::query()->where('idempotency_key', $allocationKey)
                ->when($entry, fn ($query) => $query->whereKeyNot($entry->id))
                ->exists()) {
                throw new RuntimeException('A duplicate Logistics allocation key exists. No reconciliation changes were committed.');
            }

            $routeMetadata = [
                'logistics_center_id' => $centerId,
                'route_role' => $expected['role'],
                'logistics_net_share' => FinancialRules::amount($expected['net_share_cents']),
                'rider_reserve_amount' => FinancialRules::amount($expected['rider_reserve_cents']),
                'transfer_amount' => FinancialRules::amount($expected['transfer_cents']),
                'base_shipping_fee' => (string) $allocation->shipping_fee,
                'additional_care_amount' => (string) ($allocation->protection_amount ?? '0.00'),
                'combined_delivery_earnings' => FinancialRules::amount(FinancialRules::deliveryEarningsCents(
                    $allocation->shipping_fee,
                    $allocation->protection_amount ?? 0,
                )),
                'earning_recipient' => 'LOGISTICS',
                'route_policy_adjustment_status' => 'OUTSTANDING',
            ];

            if ($entry) {
                $entry->update([
                    'amount' => FinancialRules::amount($expected['net_share_cents']),
                    'status' => 'REVIEW_REQUIRED',
                    'idempotency_key' => $allocationKey,
                    'metadata' => array_merge((array) $entry->metadata, $routeMetadata),
                ]);
            } else {
                FinancialLedgerEntry::query()->create([
                    'shipment_financial_allocation_id' => $allocation->id,
                    'shipment_id' => $shipment->id,
                    'seller_order_id' => $allocation->seller_order_id,
                    'order_id' => $allocation->order_id,
                    'account_type' => 'LOGISTICS',
                    'entry_type' => 'LOGISTICS_ALLOCATION',
                    'direction' => 'CREDIT',
                    'amount' => FinancialRules::amount($expected['net_share_cents']),
                    'status' => 'REVIEW_REQUIRED',
                    'idempotency_key' => $allocationKey,
                    'posted_at' => now(),
                    'metadata' => $routeMetadata,
                ]);
            }
        }

        $reference = 'RPA-'.$allocation->id.'-'.$plan['from_center_id'].'-'.$plan['to_center_id'];
        foreach ([
            ['direction' => 'DEBIT', 'center_id' => $plan['from_center_id'], 'role' => 'SOURCE_RECOVERY'],
            ['direction' => 'CREDIT', 'center_id' => $plan['to_center_id'], 'role' => 'DESTINATION_PAYABLE'],
        ] as $side) {
            FinancialLedgerEntry::query()->create([
                'shipment_financial_allocation_id' => $allocation->id,
                'shipment_id' => $shipment->id,
                'seller_order_id' => $allocation->seller_order_id,
                'order_id' => $allocation->order_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'LOGISTICS_ROUTE_POLICY_ADJUSTMENT',
                'direction' => $side['direction'],
                'amount' => FinancialRules::amount($plan['amount_cents']),
                'status' => 'ADJUSTMENT_REQUIRED',
                'idempotency_key' => 'route-policy-adjustment-'.$allocation->id.'-'.strtolower($side['direction']),
                'actor_user_id' => null,
                'posted_at' => null,
                'metadata' => [
                    'adjustment_reference' => $reference,
                    'adjustment_status' => 'OUTSTANDING',
                    'adjustment_role' => $side['role'],
                    'logistics_center_id' => $side['center_id'],
                    'from_logistics_center_id' => $plan['from_center_id'],
                    'to_logistics_center_id' => $plan['to_center_id'],
                    'amount_expected' => FinancialRules::amount($plan['amount_cents']),
                    'transfer_reference' => null,
                    'policy' => 'Pickup Rider 20%, Delivery Rider 40%, Origin Logistics 30%, Destination Logistics 10% of base shipping fee',
                    'confirmed_payout_ids_preserved' => $sourcePayouts->pluck('id')->all(),
                ],
            ]);
        }

        $remittance = FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $allocation->id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->whereIn('status', ['RECEIVED', 'PAYOUT_PENDING_ACK', 'PAID'])
            ->lockForUpdate()
            ->first();
        if ($remittance) {
            $metadata = (array) $remittance->metadata;
            $metadata['route_logistics_shares'] = [
                'origin' => FinancialRules::amount($plan['expected'][$plan['origin_center_id']]['net_share_cents']),
                'destination' => FinancialRules::amount($plan['expected'][$plan['destination_center_id']]['net_share_cents']),
            ];
            $metadata['route_policy_adjustment_reference'] = $reference;
            $metadata['route_policy_adjustment_status'] = 'OUTSTANDING';
            $remittance->update(['metadata' => $metadata]);
        }

        FinanceAuditEvent::query()->create([
            'event_type' => 'LOGISTICS_PAID_ROUTE_REALLOCATION_OPENED',
            'auditable_type' => ShipmentFinancialAllocation::class,
            'auditable_id' => $allocation->id,
            'actor_user_id' => null,
            'before_state' => [
                'logistics_pools' => $plan['pools_before'],
                'allocation_entries' => $plan['allocation_entries_before'],
                'confirmed_payouts' => $plan['payouts_before'],
                'product_proceeds_remittances' => $plan['remittances_before'],
            ],
            'after_state' => [
                'route_policy_adjustment_reference' => $reference,
                'adjustment_status' => 'OUTSTANDING',
                'from_center_id' => $plan['from_center_id'],
                'to_center_id' => $plan['to_center_id'],
                'amount' => FinancialRules::amount($plan['amount_cents']),
                'expected_route' => collect($plan['expected'])->map(fn (array $route): array => [
                    'role' => $route['role'],
                    'net_share' => FinancialRules::amount($route['net_share_cents']),
                    'transfer_amount' => FinancialRules::amount($route['transfer_cents']),
                    'rider_reserve' => FinancialRules::amount($route['rider_reserve_cents']),
                ])->all(),
                'confirmed_payouts_changed' => false,
                'cash_remittance_amounts_changed' => false,
            ],
            'notes' => 'The PHP '.FinancialRules::amount($plan['amount_cents']).' route reallocation remains outstanding. Current policy allocations were recorded with both sides in REVIEW_REQUIRED. Confirmed payout and COD remittance amounts were preserved; settlement proof is still required.',
        ]);
    }
}
