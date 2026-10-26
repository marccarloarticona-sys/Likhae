<?php

namespace App\Services\Finance;

use App\Models\Buyer\OrderItem;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Seller\SellerOrder;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Tracks a Seller's optional voucher discount allowance in the existing ledger. */
class SellerVoucherBudgetService
{
    private const RESERVED = 'SELLER_VOUCHER_DISCOUNT_RESERVED';
    private const RELEASED = 'SELLER_VOUCHER_DISCOUNT_RELEASED';
    private const CONSUMED = 'SELLER_VOUCHER_DISCOUNT_RESERVATION_CONSUMED';
    private const SPENT = 'SELLER_VOUCHER_DISCOUNT_SPENT';
    private const REVERSAL = 'SELLER_VOUCHER_DISCOUNT_REVERSAL';

    public function summary(Voucher $voucher, bool $lockLedgerRows = false): array
    {
        $entries = $this->ledgerReady()
            ? FinancialLedgerEntry::query()
                ->where('voucher_id', $voucher->id)
                ->where('account_type', 'SELLER_PROMOTIONAL_ALLOWANCE')
                ->whereIn('entry_type', [self::RESERVED, self::RELEASED, self::CONSUMED, self::SPENT, self::REVERSAL])
                ->whereIn('status', ['RESERVED', 'POSTED'])
                ->when($lockLedgerRows, fn ($query) => $query->lockForUpdate())
                ->get(['entry_type', 'amount'])
            : collect();

        $sum = fn (string $type): int => $entries
            ->where('entry_type', $type)
            ->sum(fn (FinancialLedgerEntry $entry): int => FinancialRules::cents($entry->amount));
        $reserved = max(0, $sum(self::RESERVED) - $sum(self::RELEASED) - $sum(self::CONSUMED));
        $spent = max(0, $sum(self::SPENT) - $sum(self::REVERSAL));
        $allocated = $voucher->seller_discount_budget_total === null
            ? null
            : FinancialRules::cents($voucher->seller_discount_budget_total);
        $available = $allocated === null ? null : $allocated - $reserved - $spent;

        return [
            'allocated_cents' => $allocated,
            'reserved_cents' => $reserved,
            'spent_cents' => $spent,
            'available_cents' => $available,
            'reconciliation_mismatch' => $available !== null && $available < 0,
        ];
    }

    public function assertAvailable(Voucher $voucher, string|int|float $amount, bool $lockLedgerRows = false, string $errorKey = 'seller_voucher_codes'): void
    {
        if ($voucher->seller_discount_budget_total === null) {
            return;
        }
        if (! $this->ledgerReady()) {
            throw ValidationException::withMessages([
                $errorKey => 'This Seller voucher budget is unavailable until the financial ledger migration is installed.',
            ]);
        }

        $summary = $this->summary($voucher, $lockLedgerRows);
        if ($summary['reconciliation_mismatch'] || $summary['available_cents'] < FinancialRules::cents($amount)) {
            throw ValidationException::withMessages([
                $errorKey => 'This Seller voucher has insufficient remaining discount allowance. Continue checkout without it or choose another voucher.',
            ]);
        }
    }

    public function reserve(Voucher $voucher, OrderItem $item, string|int|float $amount, User $actor): ?FinancialLedgerEntry
    {
        $cents = FinancialRules::cents($amount);
        if ($cents <= 0 || $voucher->seller_discount_budget_total === null) {
            return null;
        }

        return DB::transaction(function () use ($voucher, $item, $cents, $actor): FinancialLedgerEntry {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($locked->seller_profile_id === null || $locked->platform_code_key !== null) {
                throw new RuntimeException('Seller voucher allowance can only be used by a Seller-funded voucher.');
            }
            $key = 'seller-voucher-reserve-item-'.$item->id.'-voucher-'.$locked->id;
            $existing = FinancialLedgerEntry::query()->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if (FinancialRules::cents($existing->amount) !== $cents) {
                    throw new RuntimeException('A different Seller voucher reservation already exists for this order item.');
                }

                return $existing;
            }
            $this->assertAvailable($locked, FinancialRules::amount($cents), lockLedgerRows: true);

            return FinancialLedgerEntry::query()->create([
                'voucher_id' => $locked->id,
                'order_item_id' => $item->id,
                'seller_order_id' => $item->seller_order_id,
                'order_id' => $item->sellerOrder?->order_id,
                'account_type' => 'SELLER_PROMOTIONAL_ALLOWANCE',
                'entry_type' => self::RESERVED,
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount($cents),
                'status' => 'RESERVED',
                'idempotency_key' => $key,
                'actor_user_id' => $actor->id,
                'posted_at' => now(),
                'metadata' => ['source' => 'SELLER_VOUCHER', 'order_item_id' => $item->id],
            ]);
        }, attempts: 3);
    }

    public function consumeForOrderItem(OrderItem $item, ?User $actor = null): void
    {
        if (! $this->ledgerReady()) {
            return;
        }
        DB::transaction(function () use ($item, $actor): void {
            $reservations = FinancialLedgerEntry::query()
                ->where('order_item_id', $item->id)
                ->where('account_type', 'SELLER_PROMOTIONAL_ALLOWANCE')
                ->where('entry_type', self::RESERVED)
                ->where('status', 'RESERVED')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                if ($this->hasCloseEntry($reservation, 'consumed') || $this->hasCloseEntry($reservation, 'released')) {
                    continue;
                }
                $base = [
                    'voucher_id' => $reservation->voucher_id,
                    'order_item_id' => $item->id,
                    'seller_order_id' => $item->seller_order_id,
                    'order_id' => $reservation->order_id,
                    'account_type' => 'SELLER_PROMOTIONAL_ALLOWANCE',
                    'direction' => 'CREDIT',
                    'amount' => $reservation->amount,
                    'status' => 'POSTED',
                    'actor_user_id' => $actor?->id,
                    'posted_at' => now(),
                ];
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'seller-voucher-consumed-reserve-'.$reservation->id],
                    $base + [
                        'entry_type' => self::CONSUMED,
                        'metadata' => ['reservation_entry_id' => $reservation->id],
                    ],
                );
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'seller-voucher-spent-reserve-'.$reservation->id],
                    $base + [
                        'entry_type' => self::SPENT,
                        'metadata' => ['reservation_entry_id' => $reservation->id, 'source' => 'SELLER_VOUCHER'],
                    ],
                );
            }
        }, attempts: 3);
    }

    public function releaseForSellerOrder(SellerOrder $sellerOrder, ?User $actor = null): void
    {
        if (! $this->ledgerReady()) {
            return;
        }
        DB::transaction(function () use ($sellerOrder, $actor): void {
            $reservations = FinancialLedgerEntry::query()
                ->where('seller_order_id', $sellerOrder->id)
                ->where('account_type', 'SELLER_PROMOTIONAL_ALLOWANCE')
                ->where('entry_type', self::RESERVED)
                ->where('status', 'RESERVED')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($reservations as $reservation) {
                if ($this->hasCloseEntry($reservation, 'consumed') || $this->hasCloseEntry($reservation, 'released')) {
                    continue;
                }
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'seller-voucher-released-reserve-'.$reservation->id],
                    [
                        'voucher_id' => $reservation->voucher_id,
                        'order_item_id' => $reservation->order_item_id,
                        'seller_order_id' => $sellerOrder->id,
                        'order_id' => $reservation->order_id,
                        'account_type' => 'SELLER_PROMOTIONAL_ALLOWANCE',
                        'entry_type' => self::RELEASED,
                        'direction' => 'CREDIT',
                        'amount' => $reservation->amount,
                        'status' => 'POSTED',
                        'actor_user_id' => $actor?->id,
                        'posted_at' => now(),
                        'metadata' => ['reservation_entry_id' => $reservation->id, 'reason' => 'SELLER_ORDER_CANCELLED'],
                    ],
                );
            }
        }, attempts: 3);
    }

    public function reverseForOrderItem(OrderItem $item, string|int|float $amount, string $reference, ?User $actor = null): void
    {
        $remaining = FinancialRules::cents($amount);
        if ($remaining <= 0 || ! $this->ledgerReady()) {
            return;
        }
        DB::transaction(function () use ($item, $reference, $actor, $remaining): void {
            $left = $remaining;
            $spentEntries = FinancialLedgerEntry::query()
                ->where('order_item_id', $item->id)
                ->where('account_type', 'SELLER_PROMOTIONAL_ALLOWANCE')
                ->where('entry_type', self::SPENT)
                ->where('status', 'POSTED')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($spentEntries as $spent) {
                if ($left <= 0) {
                    break;
                }
                $alreadyReversed = FinancialLedgerEntry::query()
                    ->where('voucher_id', $spent->voucher_id)
                    ->where('order_item_id', $item->id)
                    ->where('entry_type', self::REVERSAL)
                    ->sum('amount');
                $reversible = max(0, FinancialRules::cents($spent->amount) - FinancialRules::cents($alreadyReversed));
                $slice = min($reversible, $left);
                if ($slice <= 0) {
                    continue;
                }
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'seller-voucher-reversal-'.$reference.'-'.$spent->id],
                    [
                        'voucher_id' => $spent->voucher_id,
                        'order_item_id' => $item->id,
                        'seller_order_id' => $item->seller_order_id,
                        'order_id' => $spent->order_id,
                        'account_type' => 'SELLER_PROMOTIONAL_ALLOWANCE',
                        'entry_type' => self::REVERSAL,
                        'direction' => 'CREDIT',
                        'amount' => FinancialRules::amount($slice),
                        'status' => 'POSTED',
                        'actor_user_id' => $actor?->id,
                        'posted_at' => now(),
                        'metadata' => ['spent_entry_id' => $spent->id, 'refund_reference' => $reference],
                    ],
                );
                $left -= $slice;
            }
        }, attempts: 3);
    }

    private function hasCloseEntry(FinancialLedgerEntry $reservation, string $type): bool
    {
        $key = $type === 'consumed'
            ? 'seller-voucher-consumed-reserve-'.$reservation->id
            : 'seller-voucher-released-reserve-'.$reservation->id;

        return FinancialLedgerEntry::query()->where('idempotency_key', $key)->exists();
    }

    private function ledgerReady(): bool
    {
        return Schema::hasTable('financial_ledger_entries')
            && Schema::hasColumn('financial_ledger_entries', 'voucher_id')
            && Schema::hasColumn('financial_ledger_entries', 'order_item_id')
            && Schema::hasColumn('financial_ledger_entries', 'idempotency_key');
    }
}
