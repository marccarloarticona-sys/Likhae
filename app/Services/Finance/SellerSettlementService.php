<?php

namespace App\Services\Finance;

use App\Models\Admin\CommissionTransaction;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\CodCollection;
use App\Models\Finance\RiderRemittance;
use App\Models\Finance\SellerSettlement;
use App\Models\Logistics\Shipment;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SellerSettlementService
{
    public function __construct(
        private readonly FinanceSchemaReadiness $schema,
        private readonly PayoutDestinationReadiness $destinations,
    ) {}

    public function requestBySeller(User $actor, SellerSettlement $settlement): SellerSettlement
    {
        $this->assertReady();
        $this->assertRole($actor, User::TYPE_SELLER);

        return DB::transaction(function () use ($actor, $settlement): SellerSettlement {
            $actor->loadMissing('sellerProfile');
            $locked = SellerSettlement::query()->with('allocation.shipment')->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (! $actor->sellerProfile || (int) $locked->seller_profile_id !== (int) $actor->sellerProfile->id) {
                throw new RuntimeException('This parcel payout belongs to a different seller.');
            }
            $this->applyRefundDebtToUnpaidSettlements((int) $locked->seller_profile_id, $actor);
            $locked->refresh()->loadMissing('allocation.shipment');
            if (! $this->destinations->readyFor('seller_profiles') || ! $this->destinations->hasDestination($actor->sellerProfile)) {
                throw new RuntimeException('Save your GCash, Maya, or bank payout details in Account settings before requesting a payout.');
            }
            $this->promoteAvailableIfReceiptComplete($locked, $actor->id);
            $this->assertReceiptComplete($locked);
            if ($locked->payout_status === 'PAYOUT_REQUESTED') {
                return $locked;
            }
            if (! in_array($locked->payout_status, ['AVAILABLE', 'FAILED'], true) || $locked->seller_payable === null || FinancialRules::cents($locked->seller_payable) <= 0) {
                throw new RuntimeException('This parcel does not have an eligible seller payout to request.');
            }
            $productReceipt = $this->productProceedsEntry($locked);
            if ($productReceipt && $productReceipt->status !== 'RECEIVED') {
                throw new RuntimeException('Payout requests are available after Admin reconciles the parcel product proceeds.');
            }

            $before = $locked->toArray();
            $locked->update(['payout_status' => 'PAYOUT_REQUESTED']);
            $this->audit('SELLER_PAYOUT_REQUESTED', $locked, $actor->id, $before, $locked->toArray(), null);

            return $locked->refresh();
        });
    }

    public function authorize(User $actor, SellerSettlement $settlement, ?string $notes = null): SellerSettlement
    {
        $this->assertReady();
        $this->assertRole($actor, User::TYPE_ADMIN);

        return DB::transaction(function () use ($actor, $settlement, $notes): SellerSettlement {
            $locked = SellerSettlement::query()->with('allocation.shipment')->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $this->applyRefundDebtToUnpaidSettlements((int) $locked->seller_profile_id, $actor);
            $locked->refresh()->loadMissing('allocation.shipment');
            if ($locked->payout_status === 'AUTHORIZED' && (int) $locked->authorized_by_user_id === (int) $actor->id) {
                return $locked;
            }
            $this->promoteAvailableIfReceiptComplete($locked, $actor->id);
            $this->assertReceiptComplete($locked);
            $productReceipt = $this->productProceedsEntry($locked);
            if ($productReceipt && $productReceipt->status !== 'RECEIVED') {
                throw new RuntimeException('Admin must acknowledge the reconciled product proceeds before authorizing seller payout.');
            }
            if (! in_array($locked->payout_status, ['AVAILABLE', 'PAYOUT_REQUESTED', 'FAILED'], true) || $locked->seller_payable === null) {
                throw new RuntimeException('This seller settlement is not eligible for authorization. Resolve any discount-funding review first.');
            }
            $before = $locked->toArray();
            $updates = [
                'payout_status' => 'AUTHORIZED',
                'authorized_by_user_id' => $actor->id,
                'authorized_at' => now(),
                'notes' => $notes ?: $locked->notes,
            ];
            if ($this->schema->parcelSettlementReady()) {
                $updates += ['payout_failure_reason' => null, 'payout_failed_at' => null, 'payout_failed_by_user_id' => null];
            }
            $locked->update($updates);
            $this->audit('SELLER_SETTLEMENT_AUTHORIZED', $locked, $actor->id, $before, $locked->toArray(), $notes);

            return $locked->refresh();
        });
    }

    public function pay(
        User $actor,
        SellerSettlement $settlement,
        string|int|float $amount,
        ?string $payoutMethod = null,
        ?string $externalReference = null,
        ?string $notes = null,
        ?string $proofPath = null,
        ?string $proofName = null,
    ): SellerSettlement {
        $this->assertReady();
        $this->assertRole($actor, User::TYPE_ADMIN);
        if (! $this->schema->parcelSettlementReady()) {
            throw new RuntimeException('Verified seller payout receipts require the parcel finance receipt migration.');
        }
        if (! filled($externalReference)
            || str_starts_with(trim((string) $externalReference), 'SIM-')
            || ! filled($proofPath)
            || ! Storage::disk('local')->exists($proofPath)) {
            throw new RuntimeException('Enter the external transfer reference and upload proof of payment.');
        }

        return DB::transaction(function () use ($actor, $settlement, $amount, $payoutMethod, $externalReference, $notes, $proofPath, $proofName): SellerSettlement {
            $locked = SellerSettlement::query()->with(['allocation.shipment', 'sellerOrder.order', 'sellerProfile'])->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $this->applyRefundDebtToUnpaidSettlements((int) $locked->seller_profile_id, $actor);
            $locked->refresh()->loadMissing(['allocation.shipment', 'sellerOrder.order', 'sellerProfile']);
            if (! $this->destinations->readyFor('seller_profiles')
                || ! $locked->sellerProfile
                || ! $this->destinations->hasDestination($locked->sellerProfile)
                || $locked->sellerProfile->payout_method !== $payoutMethod) {
                throw new RuntimeException('The seller must save complete payout details before Admin can record a transfer.');
            }
            $amountCents = FinancialRules::cents($amount);
            if (in_array($locked->payout_status, ['PAYOUT_PENDING_ACK', 'PAID'], true) && $locked->paid_at) {
                if (FinancialRules::cents($locked->payout_amount) === $amountCents
                    && ($externalReference === null || $externalReference === $locked->payout_external_reference)
                    && ($payoutMethod === null || $payoutMethod === $locked->payout_method)) {
                    return $locked;
                }
                throw new RuntimeException('A different payout is already recorded for this parcel.');
            }
            $this->assertUnusedSellerTransferReference($locked, trim((string) $externalReference));
            $this->assertReceiptComplete($locked);
            $this->assertFullCodReceivedForPayout($locked);
            if ($locked->payout_status !== 'AUTHORIZED' || $locked->seller_payable === null) {
                throw new RuntimeException('Authorize the seller settlement before recording payment.');
            }
            if ($amountCents !== FinancialRules::cents($locked->seller_payable)) {
                throw new RuntimeException('Payout amount must match the authorized seller payable amount.');
            }

            $before = $locked->toArray();
            $reference = 'SET-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
            $updates = [
                'payout_status' => 'PAYOUT_PENDING_ACK',
                'payout_amount' => FinancialRules::amount($amountCents),
                'outstanding_balance' => '0.00',
                'settlement_reference' => $reference,
                'paid_at' => now(),
                'notes' => $notes ?: $locked->notes,
            ];
            if ($this->schema->parcelSettlementReady()) {
                $updates += [
                    'payout_method' => $payoutMethod,
                    'payout_external_reference' => trim($externalReference),
                    'payout_proof_path' => $proofPath,
                    'payout_proof_name' => $proofName,
                ];
            }
            $locked->update($updates);
            $receipt = $this->payoutReceipt($locked, $actor);
            $this->audit('SELLER_PAYOUT_SENT_AWAITING_ACKNOWLEDGMENT', $locked, $actor->id, $before, $receipt, $notes);

            $sellerLedger = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $locked->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_REVENUE')
                ->first();
            if ($sellerLedger) {
                $sellerLedger->update([
                    'status' => 'PAYOUT_PENDING_ACK',
                    'actor_user_id' => $actor->id,
                    'paid_at' => now(),
                    'metadata' => array_merge((array) $sellerLedger->metadata, [
                        'payout_reference' => $reference,
                        'payout_amount' => FinancialRules::amount($amountCents),
                        'payout_method' => $payoutMethod,
                        'payout_external_reference' => trim($externalReference),
                        'payout_proof_available' => true,
                    ]),
                ]);
            }

            return $locked->refresh();
        });
    }

    public function failPayout(User $actor, SellerSettlement $settlement, string $reason): SellerSettlement
    {
        $this->assertReady();
        if (! $this->schema->parcelSettlementReady()) {
            throw new RuntimeException('Payout failure receipts require the parcel finance receipt migration.');
        }
        $this->assertRole($actor, User::TYPE_ADMIN);
        if (trim($reason) === '') {
            throw new RuntimeException('A failure reason is required.');
        }

        return DB::transaction(function () use ($actor, $settlement, $reason): SellerSettlement {
            $locked = SellerSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->payout_status === 'FAILED'
                && (int) $locked->payout_failed_by_user_id === (int) $actor->id
                && $locked->payout_failure_reason === trim($reason)) {
                return $locked;
            }
            if (! in_array($locked->payout_status, ['AUTHORIZED', 'PAYOUT_PENDING_ACK'], true)) {
                throw new RuntimeException('Only an authorized or unacknowledged payout can be recorded as failed.');
            }
            $before = $locked->toArray();
            $locked->update([
                'payout_status' => 'FAILED',
                'outstanding_balance' => $locked->seller_payable,
                'paid_at' => null,
                'payout_failure_reason' => trim($reason),
                'payout_failed_at' => now(),
                'payout_failed_by_user_id' => $actor->id,
                'notes' => trim($reason),
            ]);
            $this->audit('SELLER_PAYOUT_FAILED', $locked, $actor->id, $before, $locked->toArray(), $reason);
            FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $locked->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_REVENUE')
                ->update(['status' => 'FAILED', 'paid_at' => null, 'actor_user_id' => $actor->id]);

            return $locked->refresh();
        });
    }

    public function acknowledgeBySeller(User $actor, SellerSettlement $settlement): SellerSettlement
    {
        $this->assertReady();
        if (! $this->schema->parcelSettlementReady()) {
            throw new RuntimeException('Seller payout acknowledgments require the parcel finance receipt migration.');
        }
        $this->assertRole($actor, User::TYPE_SELLER);

        return DB::transaction(function () use ($actor, $settlement): SellerSettlement {
            $actor->loadMissing('sellerProfile');
            $locked = SellerSettlement::query()->with('sellerOrder')->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (! $actor->sellerProfile || (int) $locked->seller_profile_id !== (int) $actor->sellerProfile->id) {
                throw new RuntimeException('This parcel payout belongs to a different seller.');
            }
            if (str_starts_with((string) $locked->payout_external_reference, 'SIM-')) {
                throw new RuntimeException('This legacy demo payout has no transfer proof and cannot be confirmed as received. Contact Admin for help.');
            }
            if (! filled($locked->payout_external_reference)
                || ! filled($locked->payout_proof_path)
                || ! Storage::disk('local')->exists((string) $locked->payout_proof_path)) {
                throw new RuntimeException('A saved actual transfer reference and proof are required before a seller payout can be confirmed as Paid.');
            }
            if ($locked->payout_status === 'PAID' && $locked->seller_acknowledged_at) {
                return $locked;
            }
            if ($locked->payout_status !== 'PAYOUT_PENDING_ACK' || ! $locked->paid_at) {
                throw new RuntimeException('Only a recorded payout awaiting seller acknowledgment can be acknowledged.');
            }
            $before = $locked->toArray();
            $locked->update([
                'payout_status' => 'PAID',
                'seller_acknowledged_by_user_id' => $actor->id,
                'seller_acknowledged_at' => now(),
            ]);
            $receipt = $this->payoutReceipt($locked, $actor) + [
                'confirmation_status' => 'ACKNOWLEDGED',
                'acknowledged_by_user_id' => $actor->id,
                'acknowledged_at' => $locked->seller_acknowledged_at?->toIso8601String(),
            ];
            $this->audit('SELLER_ACKNOWLEDGED_PAYOUT', $locked, $actor->id, $before, $receipt, null);
            FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $locked->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_REVENUE')
                ->update(['status' => 'PAID', 'actor_user_id' => $actor->id]);
            app(FinancialLedgerTransferService::class)->postPair(
                'seller-payout-'.$locked->id,
                'SELLER_PAYOUT_TRANSFER',
                FinancialRules::cents($locked->payout_amount),
                'SELLER_PAYABLE',
                'ADMIN_CASH',
                $actor,
                [
                    'seller_settlement_id' => $locked->id,
                    'seller_profile_id' => $locked->seller_profile_id,
                    'payout_reference' => $locked->settlement_reference,
                    'payout_external_reference' => $locked->payout_external_reference,
                    'payout_method' => $locked->payout_method,
                    'payout_proof_path' => $locked->payout_proof_path,
                    'paid_at' => $locked->paid_at?->toIso8601String(),
                    'confirmed_by_user_id' => $actor->id,
                    'confirmed_at' => $locked->seller_acknowledged_at?->toIso8601String(),
                ],
                $locked->shipment_financial_allocation_id,
                $locked->allocation?->shipment_id,
                $locked->seller_order_id,
                $locked->order_id,
            );
            if (! SellerSettlement::query()->where('seller_order_id', $locked->seller_order_id)->where('payout_status', '<>', 'PAID')->exists()) {
                CommissionTransaction::query()->where('seller_order_id', $locked->seller_order_id)->where('status', 'PENDING')->update(['status' => 'SETTLED', 'settled_at' => now()]);
            }

            return $locked->refresh();
        });
    }

    /** Release seller proceeds only after the parent order is fully completed. */
    public function releaseForShipment(Shipment $shipment, ?User $actor = null): int
    {
        if (! $this->schema->step7Ready() || ! $this->shipmentOrderIsCompleted($shipment)) {
            return 0;
        }

        return DB::transaction(function () use ($shipment, $actor): int {
            $sellerProfileId = $shipment->sellerOrder()->value('seller_profile_id');
            if ($sellerProfileId) {
                $this->applyRefundDebtToUnpaidSettlements((int) $sellerProfileId, $actor);
            }
            $settlements = SellerSettlement::query()
                ->with('allocation.shipment')
                ->whereHas('allocation', fn ($query) => $query->where('shipment_id', $shipment->id))
                ->lockForUpdate()
                ->get();
            $changed = 0;

            foreach ($settlements as $settlement) {
                if (! in_array($settlement->payout_status, ['ON_HOLD', 'PENDING'], true)) {
                    continue;
                }

                $before = $settlement->toArray();
                $settlement->update(['payout_status' => 'AVAILABLE']);
                $this->sellerLedger($settlement)?->update(['status' => 'AVAILABLE', 'actor_user_id' => $actor?->id]);
                $this->audit('SELLER_PROCEEDS_RELEASED', $settlement, $actor?->id, $before, $settlement->fresh()->toArray(), 'Parent order receipt is complete.');
                $changed++;
            }

            return $changed;
        }, attempts: 3);
    }

    /** Apply posted seller refund debits to unpaid settlements, recording each allocation idempotently. */
    public function applyRefundDebtToUnpaidSettlements(int $sellerProfileId, ?User $actor = null): int
    {
        if (! $this->schema->step7Ready()) {
            return 0;
        }

        return DB::transaction(function () use ($sellerProfileId, $actor): int {
            $refundEntries = FinancialLedgerEntry::query()
                ->where('account_type', 'SELLER')
                ->where('entry_type', 'REFUND_SELLER_REVERSAL')
                ->where('direction', 'DEBIT')
                ->where('status', 'POSTED')
                ->whereHas('sellerOrder', fn ($query) => $query->where('seller_profile_id', $sellerProfileId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($refundEntries->isEmpty()) {
                return 0;
            }

            $settlements = SellerSettlement::query()
                ->where('seller_profile_id', $sellerProfileId)
                ->whereNotIn('payout_status', ['PAID', 'PAYOUT_PENDING_ACK'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $changedCents = 0;

            foreach ($refundEntries as $refundEntry) {
                $metadata = (array) $refundEntry->metadata;
                $adjustments = (array) ($metadata['seller_settlement_adjustments'] ?? []);
                $remainingCents = max(0, FinancialRules::cents($refundEntry->amount) - array_sum(array_map('intval', $adjustments)));
                if ($remainingCents <= 0) {
                    continue;
                }

                foreach ($settlements as $settlement) {
                    if ($remainingCents <= 0) {
                        break;
                    }
                    $payableCents = FinancialRules::cents($settlement->seller_payable ?? 0);
                    if ($payableCents <= 0) {
                        continue;
                    }

                    $deductionCents = min($remainingCents, $payableCents);
                    $before = $settlement->toArray();
                    $newPayable = FinancialRules::amount($payableCents - $deductionCents);
                    $settlement->update([
                        'seller_payable' => $newPayable,
                        'outstanding_balance' => $newPayable,
                    ]);
                    $adjustments[(string) $settlement->id] = (int) ($adjustments[(string) $settlement->id] ?? 0) + $deductionCents;
                    $metadata['seller_settlement_adjustments'] = $adjustments;
                    $metadata['unapplied_seller_refund_amount'] = FinancialRules::amount(max(0, FinancialRules::cents($refundEntry->amount) - array_sum(array_map('intval', $adjustments))));
                    $refundEntry->update(['metadata' => $metadata]);
                    $this->audit('SELLER_REFUND_SETTLEMENT_ADJUSTED', $settlement, $actor?->id, $before, $settlement->fresh()->toArray(), 'Applied seller refund reversal of PHP '.FinancialRules::amount($deductionCents).'.');
                    $remainingCents -= $deductionCents;
                    $changedCents += $deductionCents;
                }
            }

            return $changedCents;
        }, attempts: 3);
    }

    public function unappliedSellerRefundDebtCents(int $sellerProfileId): int
    {
        return FinancialLedgerEntry::query()
            ->where('account_type', 'SELLER')
            ->where('entry_type', 'REFUND_SELLER_REVERSAL')
            ->where('direction', 'DEBIT')
            ->where('status', 'POSTED')
            ->whereHas('sellerOrder', fn ($query) => $query->where('seller_profile_id', $sellerProfileId))
            ->get(['amount', 'metadata'])
            ->sum(fn (FinancialLedgerEntry $entry): int => max(
                0,
                FinancialRules::cents($entry->amount)
                    - array_sum(array_map('intval', (array) data_get($entry->metadata, 'seller_settlement_adjustments', []))),
            ));
    }

    private function assertReady(): void
    {
        if (! $this->schema->step7Ready()) {
            throw new RuntimeException('Seller settlement schema is not installed. Apply the additive Step 7 migration first.');
        }
    }

    private function assertRole(User $actor, string $role): void
    {
        if (! $actor->isActive() || ! $actor->isAccountType($role)) {
            throw new RuntimeException('Only an active '.$role.' account may perform this seller settlement transition.');
        }
    }

    private function productProceedsEntry(SellerSettlement $settlement): ?FinancialLedgerEntry
    {
        if (! $settlement->shipment_financial_allocation_id) {
            return null;
        }

        return FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $settlement->shipment_financial_allocation_id)
            ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')
            ->first();
    }

    private function assertFullCodReceivedForPayout(SellerSettlement $settlement): void
    {
        $shipment = $settlement->allocation?->shipment;
        $collection = $shipment ? CodCollection::query()->where('shipment_id', $shipment->id)->lockForUpdate()->first() : null;
        $remittance = $collection?->remittanceItem?->remittance;
        $receipt = $settlement->shipment_financial_allocation_id
            ? FinancialLedgerEntry::query()->where('shipment_financial_allocation_id', $settlement->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_PROCEEDS_REMITTANCE')->lockForUpdate()->first()
            : null;
        $destinationId = $shipment?->destination_logistics_center_id ?: $shipment?->logistics_center_id;
        if (! $collection || $collection->status !== CodCollection::REMITTED
            || $collection->collected_amount === null || ! $destinationId
            || (int) $collection->logistics_center_id !== (int) $destinationId
            || ! $remittance || $remittance->status !== RiderRemittance::RECONCILED
            || (int) $remittance->logistics_center_id !== (int) $destinationId
            || $remittance->verified_amount === null || $receipt?->status !== 'RECEIVED'
            || ! filled(data_get($receipt->metadata, 'acknowledged_at'))
            || (int) data_get($receipt->metadata, 'sender.logistics_center_id') !== (int) $destinationId) {
            throw new RuntimeException('A verified full COD handoff from Destination Logistics to Admin is required before seller payout.');
        }
        $expected = FinancialRules::cents($collection->expected_amount);
        if (FinancialRules::cents($collection->collected_amount) !== $expected
            || FinancialRules::cents($remittance->expected_amount) !== $expected
            || FinancialRules::cents($remittance->verified_amount) !== $expected
            || FinancialRules::cents($receipt->amount) !== $expected
            || FinancialRules::cents(data_get($receipt->metadata, 'amount_received', -1)) !== $expected) {
            throw new RuntimeException('The verified Admin receipt must equal the full parcel COD before seller payout.');
        }
    }

    /** @return array<string, mixed> */
    private function payoutReceipt(SellerSettlement $settlement, User $actor): array
    {
        $settlement->loadMissing('allocation.shipment', 'sellerOrder.order', 'sellerProfile');
        $shipment = $settlement->allocation?->shipment;

        return [
            'receipt_reference' => $settlement->settlement_reference,
            'parcel_id' => $shipment?->id,
            'parcel_reference' => $shipment?->tracking_number,
            'seller_order_id' => $settlement->seller_order_id,
            'order_id' => $settlement->order_id,
            'order_reference' => $settlement->sellerOrder?->order?->order_number,
            'seller_profile_id' => $settlement->seller_profile_id,
            'currency' => $settlement->allocation?->currency ?: 'PHP',
            'product_amount' => $settlement->allocation?->product_amount,
            'platform_fee' => $settlement->platform_commission,
            'seller_amount' => $settlement->payout_amount,
            'amount_expected' => $settlement->seller_payable,
            'amount_paid' => $settlement->payout_amount,
            'sender' => ['user_id' => $actor->id, 'role' => 'ADMIN'],
            'recipient' => ['role' => 'SELLER', 'seller_profile_id' => $settlement->seller_profile_id, 'user_id' => $settlement->sellerProfile?->user_id],
            'payout_method' => $settlement->payout_method,
            'payout_reference' => $settlement->payout_external_reference,
            'payout_proof_available' => filled($settlement->payout_proof_path),
            'payout_at' => $settlement->paid_at?->toIso8601String(),
            'confirmation_status' => $settlement->payout_status,
            'acknowledged_by_user_id' => $settlement->seller_acknowledged_by_user_id,
            'acknowledged_at' => $settlement->seller_acknowledged_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function promoteAvailableIfReceiptComplete(SellerSettlement $settlement, int $actorId): void
    {
        if (! in_array($settlement->payout_status, ['ON_HOLD', 'PENDING'], true)) {
            return;
        }

        $shipment = $settlement->allocation?->shipment;
        if (! $shipment || ! $this->shipmentOrderIsCompleted($shipment)) {
            return;
        }

        $before = $settlement->toArray();
        $settlement->update(['payout_status' => 'AVAILABLE']);
        $this->sellerLedger($settlement)?->update(['status' => 'AVAILABLE', 'actor_user_id' => $actorId]);
        $this->audit('SELLER_PROCEEDS_RELEASED', $settlement, $actorId, $before, $settlement->fresh()->toArray(), 'Completed parent order proceeds were synchronized to Available.');
    }

    private function assertReceiptComplete(SellerSettlement $settlement): void
    {
        $shipment = $settlement->allocation?->shipment;
        if ($shipment && $this->shipmentOrderIsCompleted($shipment)) {
            return;
        }
        if (! $shipment) {
            $settlement->loadMissing('sellerOrder.order');
            if ($settlement->sellerOrder?->order?->status === 'COMPLETED') {
                return;
            }
        }

        throw new RuntimeException('Seller proceeds remain On Hold until the Buyer confirms Order Received and the order status is Completed.');
    }

    private function shipmentOrderIsCompleted(Shipment $shipment): bool
    {
        $shipment->loadMissing('sellerOrder.order');

        return $shipment->sellerOrder?->order?->status === 'COMPLETED';
    }

    private function sellerLedger(SellerSettlement $settlement): ?FinancialLedgerEntry
    {
        return FinancialLedgerEntry::query()
            ->where('shipment_financial_allocation_id', $settlement->shipment_financial_allocation_id)
            ->where('entry_type', 'PRODUCT_REVENUE')
            ->first();
    }

    private function assertUnusedSellerTransferReference(SellerSettlement $settlement, string $reference): void
    {
        $usedBySettlement = SellerSettlement::query()
            ->where('payout_external_reference', $reference)
            ->whereKeyNot($settlement->id)
            ->exists();
        $usedByLedger = FinancialLedgerEntry::query()
            ->where('metadata->payout_external_reference', $reference)
            ->exists();
        if ($usedBySettlement || $usedByLedger) {
            throw new RuntimeException('This transfer reference is already recorded on another payout. Use a unique transaction reference.');
        }
    }

    private function audit(string $type, SellerSettlement $settlement, ?int $actorId, array $before, array $after, ?string $notes): void
    {
        FinanceAuditEvent::query()->create([
            'event_type' => $type,
            'auditable_type' => SellerSettlement::class,
            'auditable_id' => $settlement->id,
            'actor_user_id' => $actorId,
            'before_state' => $before,
            'after_state' => $after,
            'notes' => $notes,
        ]);
    }
}
