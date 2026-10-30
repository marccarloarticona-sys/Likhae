<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancialLedgerEntry;
use App\Models\User;
use App\Support\FinancialRules;
use RuntimeException;

/** Posts idempotent counterpart entries to the existing finance ledger. */
final class FinancialLedgerTransferService
{
    /**
     * @param array<string, mixed> $context
     * @return array{debit:FinancialLedgerEntry,credit:FinancialLedgerEntry}
     */
    public function postPair(
        string $key,
        string $entryType,
        int $amountCents,
        string $debitAccount,
        string $creditAccount,
        ?User $actor,
        array $context = [],
        ?int $allocationId = null,
        ?int $shipmentId = null,
        ?int $sellerOrderId = null,
        ?int $orderId = null,
    ): array {
        if ($amountCents < 0) {
            throw new RuntimeException('A ledger transfer cannot have a negative amount.');
        }

        $pairReference = 'TX-'.$key;
        $base = [
            'shipment_financial_allocation_id' => $allocationId,
            'shipment_id' => $shipmentId,
            'seller_order_id' => $sellerOrderId,
            'order_id' => $orderId,
            'entry_type' => $entryType,
            'amount' => FinancialRules::amount($amountCents),
            'status' => 'POSTED',
            'actor_user_id' => $actor?->id,
            'posted_at' => now(),
            'metadata' => ['transfer_pair_reference' => $pairReference] + $context,
        ];

        return [
            'debit' => $this->entry($key.'-debit', $debitAccount, 'DEBIT', $base),
            'credit' => $this->entry($key.'-credit', $creditAccount, 'CREDIT', $base),
        ];
    }

    /**
     * Adds the missing side when the source receipt is already the recipient
     * side of a verified transfer.
     *
     * @param array<string, mixed> $context
     */
    public function postCounterpart(
        FinancialLedgerEntry $source,
        string $accountType,
        string $direction,
        string $entryType,
        ?User $actor,
        array $context = [],
    ): FinancialLedgerEntry {
        if (! in_array($direction, ['DEBIT', 'CREDIT'], true)) {
            throw new RuntimeException('A financial counterpart must be a debit or credit.');
        }

        $source->refresh();
        $metadata = (array) $source->metadata;
        $pairReference = (string) ($metadata['transfer_pair_reference'] ?? 'TX-'.$source->id);

        return $this->entry('finance-counterpart-'.$source->id, $accountType, $direction, [
            'shipment_financial_allocation_id' => $source->shipment_financial_allocation_id,
            'shipment_id' => $source->shipment_id,
            'seller_order_id' => $source->seller_order_id,
            'order_id' => $source->order_id,
            'entry_type' => $entryType,
            'amount' => $source->amount,
            'status' => 'POSTED',
            'actor_user_id' => $actor?->id,
            'posted_at' => now(),
            'metadata' => [
                'transfer_pair_reference' => $pairReference,
                'counterpart_of_ledger_entry_id' => $source->id,
                'source_idempotency_key' => $source->idempotency_key,
            ] + $context,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function entry(string $key, string $accountType, string $direction, array $attributes): FinancialLedgerEntry
    {
        if (strlen($key) > 180) {
            throw new RuntimeException('The ledger transfer reference is too long.');
        }
        $entry = FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => $key],
            $attributes + ['account_type' => $accountType, 'direction' => $direction, 'idempotency_key' => $key],
        );

        if ($entry->account_type !== $accountType
            || $entry->direction !== $direction
            || FinancialRules::cents($entry->amount) !== FinancialRules::cents($attributes['amount'])) {
            throw new RuntimeException('A ledger idempotency reference already exists with different financial details.');
        }

        return $entry;
    }
}
