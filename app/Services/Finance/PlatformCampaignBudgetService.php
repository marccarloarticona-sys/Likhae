<?php

namespace App\Services\Finance;

use App\Models\Buyer\OrderItem;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PlatformCampaignBudgetService
{
    public function summary(Voucher $voucher, bool $lockLedgerRows = false): array
    {
        $entryQuery = $this->ledgerReady()
            ? FinancialLedgerEntry::query()
            ->where('voucher_id', $voucher->id)
            ->whereIn('entry_type', [
                'CAMPAIGN_SUBSIDY_RESERVED',
                'CAMPAIGN_SUBSIDY_RELEASED',
                'CAMPAIGN_SUBSIDY_RESERVATION_CONSUMED',
                'PLATFORM_PROMOTIONAL_EXPENSE',
                'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL',
            ])
            ->whereIn('status', ['RESERVED', 'POSTED'])
            : null;
        if ($entryQuery && $lockLedgerRows) {
            $entryQuery->lockForUpdate();
        }
        $entries = $entryQuery
            ? $entryQuery->get(['entry_type', 'amount'])
            : collect();

        $sum = fn (string $type): int => $entries
            ->where('entry_type', $type)
            ->sum(fn (FinancialLedgerEntry $entry): int => FinancialRules::cents($entry->amount));
        $reserved = max(0,
            $sum('CAMPAIGN_SUBSIDY_RESERVED')
            - $sum('CAMPAIGN_SUBSIDY_RELEASED')
            - $sum('CAMPAIGN_SUBSIDY_RESERVATION_CONSUMED'),
        );
        $spent = max(0, $sum('PLATFORM_PROMOTIONAL_EXPENSE') - $sum('PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL'));
        $allocated = $voucher->campaign_budget_total === null ? null : FinancialRules::cents($voucher->campaign_budget_total);
        $available = $allocated === null ? null : $allocated - $reserved - $spent;

        return [
            'allocated_cents' => $allocated,
            'reserved_cents' => $reserved,
            'spent_cents' => $spent,
            'available_cents' => $available,
            'usage_percent' => $allocated && $allocated > 0
                ? min(100, (($reserved + $spent) / $allocated) * 100)
                : 0.0,
            'reconciliation_mismatch' => $available !== null && $available < 0,
            'status' => $this->status($voucher, $available, $allocated),
        ];
    }

    public function assertAvailable(Voucher $voucher, string|int|float $amount, bool $lockLedgerRows = false): void
    {
        $field = strtoupper((string) $voucher->benefit_scope) === 'SHIPPING'
            ? 'shipping_voucher_code'
            : 'platform_voucher_code';
        if (! Schema::hasColumn('vouchers', 'campaign_budget_total') || ! $this->ledgerReady()) {
            throw ValidationException::withMessages([
                $field => 'LIKHAE campaign funding is not ready yet. Please continue checkout without this voucher.',
            ]);
        }

        $amountCents = FinancialRules::cents($amount);
        $summary = $this->summary($voucher, $lockLedgerRows);
        if ($summary['allocated_cents'] === null) {
            throw ValidationException::withMessages([
                $field => 'This LIKHAE campaign needs an Admin budget allocation before it can be used.',
            ]);
        }
        if ($summary['reconciliation_mismatch'] || $summary['available_cents'] < $amountCents) {
            throw ValidationException::withMessages([
                $field => 'This LIKHAE campaign has insufficient available budget. You can continue checkout without this voucher.',
            ]);
        }
    }

    public function setAllocation(Voucher $voucher, string|int|float $amount, string $reason, User $actor): Voucher
    {
        $this->assertAdmin($actor);
        if (! Schema::hasColumn('vouchers', 'campaign_budget_total') || ! $this->ledgerReady()) {
            throw new RuntimeException('Campaign budget fields require the additive finance migration.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('A reason is required for campaign budget changes.');
        }

        return DB::transaction(function () use ($voucher, $amount, $reason, $actor): Voucher {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->seller_profile_id !== null || $locked->platform_code_key === null) {
                throw new RuntimeException('Only platform campaigns can have a campaign budget.');
            }
            $newCents = FinancialRules::cents($amount);
            if ($newCents < 0) {
                throw new RuntimeException('Campaign budget cannot be negative.');
            }
            $summary = $this->summary($locked, lockLedgerRows: true);
            $committedCents = $summary['reserved_cents'] + $summary['spent_cents'];
            if ($newCents < $committedCents) {
                throw new RuntimeException('The allocation cannot be reduced below net spent plus active reservations.');
            }
            $oldCents = $locked->campaign_budget_total === null
                ? null
                : FinancialRules::cents($locked->campaign_budget_total);
            if ($oldCents === $newCents) {
                return $locked;
            }

            $locked->forceFill(['campaign_budget_total' => FinancialRules::amount($newCents)])->save();
            $idempotencyKey = 'campaign-budget-allocation-'.Str::uuid();
            FinancialLedgerEntry::query()->create([
                'voucher_id' => $locked->id,
                'account_type' => 'CAMPAIGN_BUDGET',
                'entry_type' => 'CAMPAIGN_BUDGET_ALLOCATION',
                'direction' => $oldCents === null || $newCents >= $oldCents ? 'CREDIT' : 'DEBIT',
                'amount' => FinancialRules::amount(abs($newCents - ($oldCents ?? 0))),
                'status' => 'POSTED',
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actor->id,
                'posted_at' => now(),
                'metadata' => [
                    'previous_allocation' => $oldCents === null ? null : FinancialRules::amount($oldCents),
                    'new_allocation' => FinancialRules::amount($newCents),
                    'change_amount' => FinancialRules::amount($newCents - ($oldCents ?? 0)),
                    'reason' => trim($reason),
                    'recorded_by_user_id' => $actor->id,
                ],
            ]);

            return $locked->refresh();
        }, attempts: 3);
    }

    public function reserve(Voucher $voucher, OrderItem $item, string|int|float $amount, User $actor, string $source): ?FinancialLedgerEntry
    {
        $cents = FinancialRules::cents($amount);
        if ($cents <= 0) {
            return null;
        }
        $idempotencyKey = 'campaign-reserve-item-'.$item->id.'-voucher-'.$voucher->id;
        $existing = FinancialLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing) {
            if (FinancialRules::cents($existing->amount) !== $cents) {
                throw new RuntimeException('A different campaign reservation is already recorded for this order item.');
            }

            return $existing;
        }
        $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
        $existing = FinancialLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing) {
            if (FinancialRules::cents($existing->amount) !== $cents) {
                throw new RuntimeException('A different campaign reservation is already recorded for this order item.');
            }

            return $existing;
        }
        if ($locked->seller_profile_id !== null || $locked->campaign_budget_total === null) {
            throw ValidationException::withMessages([
                (strtoupper((string) $locked->benefit_scope) === 'SHIPPING' ? 'shipping_voucher_code' : 'platform_voucher_code') => 'This LIKHAE campaign needs an Admin budget allocation before it can be used.',
            ]);
        }
        $this->assertAvailable($locked, FinancialRules::amount($cents), lockLedgerRows: true);

        return FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'voucher_id' => $locked->id,
                'order_item_id' => $item->id,
                'seller_order_id' => $item->seller_order_id,
                'order_id' => $item->sellerOrder?->order_id,
                'account_type' => 'CAMPAIGN_BUDGET',
                'entry_type' => 'CAMPAIGN_SUBSIDY_RESERVED',
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount($cents),
                'status' => 'RESERVED',
                'actor_user_id' => $actor->id,
                'posted_at' => now(),
                'metadata' => ['source' => $source, 'order_item_id' => $item->id],
            ],
        );
    }

    public function consumeForOrderItem(OrderItem $item, ?User $actor = null): void
    {
        if (! Schema::hasColumn('financial_ledger_entries', 'order_item_id')) {
            return;
        }
        $reservations = FinancialLedgerEntry::query()
            ->where('order_item_id', $item->id)
            ->where('entry_type', 'CAMPAIGN_SUBSIDY_RESERVED')
            ->where('status', 'RESERVED')
            ->orderBy('id')
            ->get();

        foreach ($reservations as $reservation) {
            if (FinancialLedgerEntry::query()->where('idempotency_key', 'campaign-consumed-reserve-'.$reservation->id)->exists()
                || FinancialLedgerEntry::query()->where('idempotency_key', 'campaign-released-reserve-'.$reservation->id)->exists()) {
                continue;
            }
            $metadata = [
                'reservation_entry_id' => $reservation->id,
                'order_item_id' => $item->id,
                'source' => data_get($reservation->metadata, 'source'),
            ];
            FinancialLedgerEntry::query()->create([
                'voucher_id' => $reservation->voucher_id,
                'order_item_id' => $item->id,
                'seller_order_id' => $item->seller_order_id,
                'order_id' => $reservation->order_id,
                'account_type' => 'CAMPAIGN_BUDGET',
                'entry_type' => 'CAMPAIGN_SUBSIDY_RESERVATION_CONSUMED',
                'direction' => 'CREDIT',
                'amount' => $reservation->amount,
                'status' => 'POSTED',
                'idempotency_key' => 'campaign-consumed-reserve-'.$reservation->id,
                'actor_user_id' => $actor?->id,
                'posted_at' => now(),
                'metadata' => $metadata,
            ]);
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => 'campaign-expense-reserve-'.$reservation->id],
                [
                    'voucher_id' => $reservation->voucher_id,
                    'order_item_id' => $item->id,
                    'seller_order_id' => $item->seller_order_id,
                    'order_id' => $reservation->order_id,
                    'account_type' => 'PLATFORM_PROMOTIONAL_EXPENSE',
                    'entry_type' => 'PLATFORM_PROMOTIONAL_EXPENSE',
                    'direction' => 'DEBIT',
                    'amount' => $reservation->amount,
                    'status' => 'POSTED',
                    'actor_user_id' => $actor?->id,
                    'posted_at' => now(),
                    'metadata' => $metadata,
                ],
            );
        }
    }

    public function recordRewardPointsExpense(OrderItem $item, ?User $actor = null): void
    {
        $amountCents = FinancialRules::cents($item->reward_points_discount ?? 0);
        if ($amountCents <= 0 || ! $this->ledgerReady()) {
            return;
        }
        FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => 'reward-points-expense-order-item-'.$item->id],
            [
                'order_item_id' => $item->id,
                'seller_order_id' => $item->seller_order_id,
                'order_id' => $item->sellerOrder?->order_id,
                'account_type' => 'PLATFORM_REWARD_EXPENSE',
                'entry_type' => 'REWARD_POINTS_REDEMPTION_EXPENSE',
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount($amountCents),
                'status' => 'POSTED',
                'actor_user_id' => $actor?->id,
                'posted_at' => now(),
                'metadata' => ['source' => 'REWARD_POINTS', 'order_item_id' => $item->id],
            ],
        );
    }

    public function releaseForSellerOrder(SellerOrder $sellerOrder, ?User $actor = null): void
    {
        $itemIds = $sellerOrder->items()->pluck('id');
        if ($itemIds->isEmpty() || ! Schema::hasColumn('financial_ledger_entries', 'order_item_id')) {
            return;
        }
        $reservations = FinancialLedgerEntry::query()
            ->whereIn('order_item_id', $itemIds)
            ->where('entry_type', 'CAMPAIGN_SUBSIDY_RESERVED')
            ->where('status', 'RESERVED')
            ->orderBy('id')
            ->get();
        foreach ($reservations as $reservation) {
            if (FinancialLedgerEntry::query()->where('idempotency_key', 'campaign-consumed-reserve-'.$reservation->id)->exists()
                || FinancialLedgerEntry::query()->where('idempotency_key', 'campaign-released-reserve-'.$reservation->id)->exists()) {
                continue;
            }
            FinancialLedgerEntry::query()->create([
                'voucher_id' => $reservation->voucher_id,
                'order_item_id' => $reservation->order_item_id,
                'seller_order_id' => $reservation->seller_order_id,
                'order_id' => $reservation->order_id,
                'account_type' => 'CAMPAIGN_BUDGET',
                'entry_type' => 'CAMPAIGN_SUBSIDY_RELEASED',
                'direction' => 'CREDIT',
                'amount' => $reservation->amount,
                'status' => 'POSTED',
                'idempotency_key' => 'campaign-released-reserve-'.$reservation->id,
                'actor_user_id' => $actor?->id,
                'posted_at' => now(),
                'metadata' => ['reservation_entry_id' => $reservation->id, 'reason' => 'SELLER_ORDER_CANCELLED'],
            ]);
        }
    }

    public function reverseForOrderItem(
        OrderItem $item,
        string|int|float $amount,
        string $reference,
        ?User $actor = null,
        ?string $source = null,
    ): void
    {
        $remainingCents = FinancialRules::cents($amount);
        if ($remainingCents <= 0) {
            return;
        }
        $expenses = FinancialLedgerEntry::query()
            ->where('order_item_id', $item->id)
            ->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE')
            ->where('status', 'POSTED')
            ->when($source !== null, fn ($query) => $query->where('metadata->source', $source))
            ->orderBy('id')
            ->get();
        foreach ($expenses as $expense) {
            if ($remainingCents <= 0) {
                break;
            }
            $priorReversed = FinancialLedgerEntry::query()
                ->where('voucher_id', $expense->voucher_id)
                ->where('order_item_id', $item->id)
                ->where('entry_type', 'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL')
                ->get(['amount'])
                ->sum(fn (FinancialLedgerEntry $row): int => FinancialRules::cents($row->amount));
            $reversible = max(0, FinancialRules::cents($expense->amount) - $priorReversed);
            $slice = min($reversible, $remainingCents);
            if ($slice <= 0) {
                continue;
            }
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => 'campaign-expense-reversal-'.$reference.'-'.$expense->id],
                [
                    'voucher_id' => $expense->voucher_id,
                    'order_item_id' => $item->id,
                    'seller_order_id' => $item->seller_order_id,
                    'order_id' => $expense->order_id,
                    'account_type' => 'PLATFORM_PROMOTIONAL_EXPENSE',
                    'entry_type' => 'PLATFORM_PROMOTIONAL_EXPENSE_REVERSAL',
                    'direction' => 'CREDIT',
                    'amount' => FinancialRules::amount($slice),
                    'status' => 'POSTED',
                    'actor_user_id' => $actor?->id,
                    'posted_at' => now(),
                    'metadata' => [
                        'expense_entry_id' => $expense->id,
                        'refund_reference' => $reference,
                        'source' => data_get($expense->metadata, 'source'),
                    ],
                ],
            );
            $remainingCents -= $slice;
        }
    }

    public function history(Voucher $voucher, int $limit = 30): Collection
    {
        if (! $this->ledgerReady()) {
            return collect();
        }

        return FinancialLedgerEntry::query()
            ->where('voucher_id', $voucher->id)
            ->where('entry_type', 'like', 'CAMPAIGN_%')
            ->orWhere(fn ($query) => $query->where('voucher_id', $voucher->id)->where('entry_type', 'like', 'PLATFORM_PROMOTIONAL_EXPENSE%'))
            ->with('actor:id,first_name,last_name')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function selectable(Voucher $voucher): bool
    {
        if (! Schema::hasColumn('vouchers', 'campaign_budget_total') || ! $this->ledgerReady()) {
            return false;
        }
        $summary = $this->summary($voucher);

        return $summary['allocated_cents'] !== null
            && $summary['available_cents'] !== null
            && $summary['available_cents'] > 0
            && ! $summary['reconciliation_mismatch'];
    }

    private function status(Voucher $voucher, ?int $availableCents, ?int $allocatedCents): string
    {
        if (! $voucher->is_active) {
            return 'Paused';
        }
        if ($voucher->ends_at?->isPast()) {
            return 'Expired';
        }
        if ($availableCents === null) {
            return 'Pending Budget Setup';
        }
        if ($availableCents <= 0) {
            return 'Exhausted';
        }

        return $allocatedCents !== null && $allocatedCents > 0 && ($availableCents / $allocatedCents) <= 0.1
            ? 'Low Budget'
            : 'Available';
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== User::STATUS_ACTIVE || ! $actor->isAccountType(User::TYPE_ADMIN)) {
            throw new RuntimeException('Only an active Admin can update campaign budget allocations.');
        }
    }

    private function ledgerReady(): bool
    {
        return Schema::hasTable('financial_ledger_entries')
            && Schema::hasColumn('financial_ledger_entries', 'voucher_id')
            && Schema::hasColumn('financial_ledger_entries', 'order_item_id');
    }
}
