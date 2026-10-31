<?php

namespace App\Services\Finance;

use App\Models\Admin\AuditLog;
use App\Models\Finance\CodCollection;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Services\AccountNotificationService;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OverdueRiderCodRemittanceWarningService
{
    private const GRACE_HOURS = 24;

    public function __construct(private readonly FinanceSchemaReadiness $schema) {}

    public function issueDueWarnings(): int
    {
        if (! $this->schema->step6Ready()
            || ! Schema::hasTable('audit_logs')
            || ! Schema::hasTable('notifications')) {
            return 0;
        }

        $cutoff = now()->subHours(self::GRACE_HOURS);
        $warningCount = 0;

        CodCollection::query()
            ->select(['id', 'rider_profile_id'])
            ->whereNotNull('rider_profile_id')
            ->whereNotNull('collected_at')
            ->whereNotNull('collected_amount')
            ->where('collected_at', '<=', $cutoff)
            ->whereIn('status', [CodCollection::COLLECTED, CodCollection::DISCREPANCY])
            ->whereDoesntHave('remittanceItem')
            ->orderBy('id')
            ->chunkById(100, function ($collections) use ($cutoff, &$warningCount): void {
                foreach ($collections as $collection) {
                    if ($this->warnCollection((int) $collection->id, (int) $collection->rider_profile_id, $cutoff)) {
                        $warningCount++;
                    }
                }
            });

        return $warningCount;
    }

    private function warnCollection(int $collectionId, int $riderId, mixed $cutoff): bool
    {
        return DB::transaction(function () use ($collectionId, $riderId, $cutoff): bool {
            // Match RemittanceService's rider-then-collection lock order so a
            // simultaneous handoff and overdue scan cannot deadlock.
            $rider = RiderProfile::query()->whereKey($riderId)->lockForUpdate()->first();
            if (! $rider) {
                return false;
            }

            $collection = CodCollection::query()->whereKey($collectionId)->lockForUpdate()->first();
            if (! $collection
                || (int) $collection->rider_profile_id !== $riderId
                || ! in_array($collection->status, [CodCollection::COLLECTED, CodCollection::DISCREPANCY], true)
                || ! $collection->collected_at
                || $collection->collected_at->gt($cutoff)
                || $collection->remittanceItem()->exists()) {
                return false;
            }

            $user = User::query()->whereKey($rider->user_id)->lockForUpdate()->first();
            if (! $user || ! $user->isAccountType(User::TYPE_RIDER)) {
                return false;
            }

            $alreadyWarned = AuditLog::query()
                ->where('event', 'rider.warned')
                ->where('auditable_type', RiderProfile::class)
                ->where('auditable_id', $rider->id)
                ->get(['new_values'])
                ->contains(fn (AuditLog $log): bool => (int) data_get($log->new_values, 'cod_collection_id') === $collectionId);
            if ($alreadyWarned) {
                return false;
            }

            $oldStatus = $rider->status;
            if ($rider->status === 'ACTIVE' && $user->status === User::STATUS_ACTIVE) {
                $rider->update(['status' => 'WARNED']);
            }

            $collection->loadMissing('shipment');
            $trackingNumber = $collection->shipment?->tracking_number ?: 'parcel #'.$collection->shipment_id;
            $overdueAt = $collection->collected_at->copy()->addHours(self::GRACE_HOURS);
            $expectedAmount = FinancialRules::amount(FinancialRules::cents($collection->expected_amount));
            $collectedAmount = FinancialRules::amount(FinancialRules::cents($collection->collected_amount));
            $reason = 'COD for '.$trackingNumber.' was not remitted within 24 hours after it was collected.';

            AuditLog::query()->create([
                'actor_user_id' => null,
                'event' => 'rider.warned',
                'auditable_type' => RiderProfile::class,
                'auditable_id' => $rider->id,
                'old_values' => ['status' => $oldStatus],
                'new_values' => [
                    'status' => $rider->status,
                    'reason' => $reason,
                    'automatic' => true,
                    'warning_type' => 'COD_REMITTANCE_OVERDUE',
                    'cod_collection_id' => $collection->id,
                    'shipment_id' => $collection->shipment_id,
                    'tracking_number' => $trackingNumber,
                    'collection_reference' => $collection->collection_reference,
                    'collected_at' => $collection->collected_at->toIso8601String(),
                    'warning_due_at' => $overdueAt->toIso8601String(),
                    'cod_expected_amount' => $expectedAmount,
                    'buyer_collected_amount' => $collectedAmount,
                    'corrective_action' => 'Remit the full COD due, cover any buyer collection shortfall, and have Logistics verify the cash received.',
                    'actor_label' => 'LIKHAE automatic 24-hour COD rule',
                ],
                'created_at' => now(),
            ]);

            if ($user->status === User::STATUS_ACTIVE) {
                app(AccountNotificationService::class)->riderCodRemittanceOverdue($collection, $expectedAmount);
            }

            return true;
        }, 3);
    }
}
