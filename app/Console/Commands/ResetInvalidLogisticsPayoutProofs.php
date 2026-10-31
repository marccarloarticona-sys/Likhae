<?php

namespace App\Console\Commands;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Support\FinancialRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ResetInvalidLogisticsPayoutProofs extends Command
{
    protected $signature = 'finance:reset-invalid-logistics-payout-proofs
                            {entries* : Payout ledger entry IDs whose uploaded proof was reviewed and found unrelated}
                            {--apply : Back up and return the requests to gross-transfer pending}
                            {--target= : Required database name when applying changes}
                            {--proof-reviewed : Confirm the stored files were inspected and are not transfer proof}';

    protected $description = 'Reset unacknowledged Logistics payout records with invalid proof to the correct gross request amount';

    public function handle(): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        $plans = $this->plans();
        if ($plans === []) {
            $this->info('No eligible unacknowledged Logistics payout records were selected.');

            return self::SUCCESS;
        }

        $this->table(
            ['Entry', 'Parcel', 'Current receipt', 'Gross required', 'Status after reset'],
            array_map(fn (array $plan): array => [
                $plan['entry_id'],
                $plan['tracking_number'],
                FinancialRules::amount($plan['current_cents']),
                FinancialRules::amount($plan['expected_cents']),
                'PAYOUT_REQUESTED',
            ], $plans),
        );

        if (! $this->option('apply')) {
            $this->line('Dry run only. Apply with --apply --proof-reviewed --target='.$database.' after reviewing these entries.');

            return self::SUCCESS;
        }
        if (! $this->option('proof-reviewed')) {
            $this->error('Applying requires --proof-reviewed because the existing proof files were manually verified as unrelated. No data was changed.');

            return self::FAILURE;
        }
        if (! filled($this->option('target')) || (string) $this->option('target') !== $database) {
            $this->error('Applying changes requires --target='.$database.' to match the configured database. No data was changed.');

            return self::FAILURE;
        }

        $backupPath = $this->writeBackup($database, $plans);
        $count = DB::transaction(function () use ($plans): int {
            $count = 0;
            foreach ($plans as $plan) {
                $entry = FinancialLedgerEntry::query()->whereKey($plan['entry_id'])->lockForUpdate()->firstOrFail();
                $pool = LogisticsEarningPool::query()->with(['shipment', 'allocation'])->whereKey($plan['pool_id'])->lockForUpdate()->firstOrFail();
                if ($entry->status !== 'PAYOUT_PENDING_ACK'
                    || FinancialRules::cents($entry->amount) !== $plan['current_cents']
                    || $pool->status !== 'PAYOUT_PENDING_ACK'
                    || FinancialRules::cents($pool->paid_amount ?? 0) !== 0) {
                    throw new RuntimeException('A selected transfer changed after backup. No payout records were reset.');
                }

                $breakdown = $pool->payoutBreakdown();
                if ($breakdown['transfer_cents'] !== $plan['expected_cents']
                    || $breakdown['transfer_cents'] <= $plan['current_cents']) {
                    throw new RuntimeException('The gross amount changed or the receipt is not underpaid. No payout records were reset.');
                }

                $before = ['entry' => $entry->toArray(), 'pool' => $pool->toArray()];
                $metadata = (array) $entry->metadata;
                $history = (array) data_get($metadata, 'payout_attempt_history', []);
                $history[] = [
                    'status' => 'RESET_INVALID_PROOF_TO_GROSS_REQUEST',
                    'recorded_amount' => $entry->amount,
                    'payout_method' => $metadata['payout_method'] ?? null,
                    'payout_external_reference' => $metadata['payout_external_reference'] ?? null,
                    'payout_proof_path' => $metadata['payout_proof_path'] ?? null,
                    'payout_proof_name' => $metadata['payout_proof_name'] ?? null,
                    'attempted_at' => $metadata['paid_at'] ?? $entry->paid_at?->toIso8601String(),
                    'reset_at' => now()->toIso8601String(),
                    'reason' => 'The uploaded file was reviewed and is unrelated to the transfer. The recipient has not acknowledged this receipt; Admin must record the actual gross Logistics transfer with valid proof.',
                ];
                $deliveryTotal = FinancialRules::deliveryEarningsCents(
                    $pool->allocation?->shipping_fee,
                    $pool->allocation?->protection_amount ?? 0,
                );
                $metadata = array_merge($metadata, [
                    'route_role' => $breakdown['role'],
                    'amount_expected' => FinancialRules::amount($breakdown['transfer_cents']),
                    'gross_delivery_earnings' => FinancialRules::amount($deliveryTotal),
                    'logistics_net_share' => FinancialRules::amount($breakdown['net_share_cents']),
                    'rider_pass_through' => FinancialRules::amount($breakdown['rider_reserve_cents']),
                    'base_shipping_fee' => $pool->allocation?->shipping_fee,
                    'additional_care_amount' => $pool->allocation?->protection_amount ?? '0.00',
                    'amount_paid' => null,
                    'payout_method' => null,
                    'payout_external_reference' => null,
                    'payout_proof_path' => null,
                    'payout_proof_name' => null,
                    'is_simulated' => false,
                    'simulation_notice' => null,
                    'payout_attempt_history' => $history,
                    'confirmation_status' => 'PAYOUT_REQUESTED',
                    'paid_by_user_id' => null,
                    'paid_at' => null,
                    'acknowledged_by_user_id' => null,
                    'acknowledged_at' => null,
                    'receipt_notes' => null,
                ]);

                $entry->update([
                    'amount' => FinancialRules::amount($breakdown['transfer_cents']),
                    'status' => 'PAYOUT_REQUESTED',
                    'actor_user_id' => data_get($metadata, 'requested_by_user_id'),
                    'paid_at' => null,
                    'metadata' => $metadata,
                ]);
                $pool->update([
                    'status' => 'PAYOUT_REQUESTED',
                    'approved_amount' => null,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'paid_amount' => '0.00',
                    'paid_at' => null,
                    'settlement_reference' => null,
                ]);

                FinanceAuditEvent::query()->create([
                    'event_type' => 'LOGISTICS_PAYOUT_INVALID_PROOF_RESET',
                    'auditable_type' => FinancialLedgerEntry::class,
                    'auditable_id' => $entry->id,
                    'actor_user_id' => null,
                    'before_state' => $before,
                    'after_state' => [
                        'entry' => $entry->fresh()->toArray(),
                        'pool' => $pool->fresh()->toArray(),
                    ],
                    'notes' => 'Removed an unacknowledged receipt with unrelated proof and restored the gross transfer request. No money was marked Paid; Admin must upload actual transfer proof for the full amount.',
                ]);
                $count++;
            }

            return $count;
        }, 3);

        $this->info("Reset {$count} unacknowledged records to their verified gross amount. Backup saved to {$backupPath}");

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function plans(): array
    {
        $ids = collect((array) $this->argument('entries'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            throw new RuntimeException('Provide at least one Logistics payout ledger entry ID.');
        }

        $entries = FinancialLedgerEntry::query()->whereIn('id', $ids)->with('shipment')->get()->keyBy('id');
        if ($entries->count() !== $ids->count()) {
            throw new RuntimeException('One or more selected Logistics payout ledger entries were not found.');
        }

        $plans = [];
        foreach ($ids as $id) {
            $entry = $entries->get($id);
            if ($entry->entry_type !== 'LOGISTICS_PAYOUT' || $entry->status !== 'PAYOUT_PENDING_ACK') {
                throw new RuntimeException("Ledger entry {$id} is not an unacknowledged Logistics payout.");
            }
            $metadata = (array) $entry->metadata;
            $pool = LogisticsEarningPool::query()->with(['shipment', 'allocation'])->whereKey((int) data_get($metadata, 'logistics_pool_id'))->first();
            if (! $pool || $pool->status !== 'PAYOUT_PENDING_ACK' || FinancialRules::cents($pool->paid_amount ?? 0) !== 0) {
                throw new RuntimeException("Ledger entry {$id} has no matching unpaid Logistics pool.");
            }
            $proofPath = (string) data_get($metadata, 'payout_proof_path', '');
            if (! filled($proofPath) || ! Storage::disk('local')->exists($proofPath)) {
                throw new RuntimeException("Ledger entry {$id} has no saved proof file to review.");
            }
            $breakdown = $pool->payoutBreakdown();
            $currentCents = FinancialRules::cents($entry->amount);
            if ($breakdown['transfer_cents'] <= $currentCents) {
                throw new RuntimeException("Ledger entry {$id} is not below the expected gross transfer.");
            }

            $plans[] = [
                'entry_id' => (int) $entry->id,
                'pool_id' => (int) $pool->id,
                'shipment_id' => (int) $entry->shipment_id,
                'tracking_number' => (string) ($entry->shipment?->tracking_number ?? 'Parcel'),
                'current_cents' => $currentCents,
                'expected_cents' => $breakdown['transfer_cents'],
                'entry_before' => $entry->toArray(),
                'pool_before' => $pool->toArray(),
            ];
        }

        return $plans;
    }

    /** @param list<array<string,mixed>> $plans */
    private function writeBackup(string $database, array $plans): string
    {
        $directory = storage_path('app/private/finance-reconciliation');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the private finance backup directory. No data was changed.');
        }
        $path = $directory.DIRECTORY_SEPARATOR.'invalid-logistics-payout-reset-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        $snapshot = [
            'created_at' => now()->toIso8601String(),
            'database' => $database,
            'reason' => 'Unacknowledged Logistics payout proofs reviewed as unrelated; reset to full gross amount',
            'entries_and_pools' => $plans,
        ];
        $written = file_put_contents($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('Could not write the finance backup. No data was changed.');
        }

        return $path;
    }
}
