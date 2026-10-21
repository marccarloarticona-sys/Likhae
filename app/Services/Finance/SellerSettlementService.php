<?php

namespace App\Services\Finance;

use App\Models\Admin\CommissionTransaction;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\SellerSettlement;
use App\Models\User;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SellerSettlementService
{
    public function __construct(
        private readonly FinanceSchemaReadiness $schema,
        private readonly PayoutDestinationReadiness $destinations,
        private readonly SimulatedPayoutGateway $simulatedPayouts,
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
            if (! $this->destinations->readyFor('seller_profiles') || ! $this->destinations->hasDestination($actor->sellerProfile)) {
                throw new RuntimeException('Save your online payout details in Wallet before requesting a payout.');
            }
            if ($locked->payout_status === 'PAYOUT_REQUESTED') {
                return $locked;
            }
            if (! in_array($locked->payout_status, ['PENDING', 'FAILED'], true) || $locked->seller_payable === null || FinancialRules::cents($locked->seller_payable) <= 0) {
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
            if ($locked->payout_status === 'AUTHORIZED' && (int) $locked->authorized_by_user_id === (int) $actor->id) {
                return $locked;
            }
            $productReceipt = $this->productProceedsEntry($locked);
            if ($productReceipt && $productReceipt->status !== 'RECEIVED') {
                throw new RuntimeException('Admin must acknowledge the reconciled product proceeds before authorizing seller payout.');
            }
            if (! in_array($locked->payout_status, ['PENDING', 'PAYOUT_REQUESTED', 'FAILED'], true) || $locked->seller_payable === null) {
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
    ): SellerSettlement {
        $this->assertReady();
        $this->assertRole($actor, User::TYPE_ADMIN);

        return DB::transaction(function () use ($actor, $settlement, $amount, $payoutMethod, $externalReference, $notes): SellerSettlement {
            $locked = SellerSettlement::query()->with(['allocation.shipment', 'sellerOrder.order'])->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            $amountCents = FinancialRules::cents($amount);
            if (in_array($locked->payout_status, ['PAYOUT_PENDING_ACK', 'PAID'], true) && $locked->paid_at) {
                if (FinancialRules::cents($locked->payout_amount) === $amountCents
                    && ($externalReference === null || $externalReference === $locked->payout_external_reference)
                    && ($payoutMethod === null || $payoutMethod === $locked->payout_method)) {
                    return $locked;
                }
                throw new RuntimeException('A different payout is already recorded for this parcel.');
            }
            $productReceipt = $this->productProceedsEntry($locked);
            if ($productReceipt && $productReceipt->status !== 'RECEIVED') {
                throw new RuntimeException('Admin must acknowledge the parcel product proceeds before payout.');
            }
            if ($locked->payout_status !== 'AUTHORIZED' || $locked->seller_payable === null) {
                throw new RuntimeException('Authorize the seller settlement before recording payment.');
            }
            if ($amountCents !== FinancialRules::cents($locked->seller_payable)) {
                throw new RuntimeException('Payout amount must match the authorized seller payable amount.');
            }

            $simulation = $this->simulatedPayouts->simulate((string) $payoutMethod);
            if ($simulation) {
                $externalReference = $simulation['external_reference'];
                $notes = trim(implode("\n", array_filter([$notes, $simulation['notice']])));
            }

            $before = $locked->toArray();
            $reference = 'SET-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
            $updates = [
                'payout_status' => $productReceipt ? 'PAYOUT_PENDING_ACK' : 'PAID',
                'payout_amount' => FinancialRules::amount($amountCents),
                'outstanding_balance' => '0.00',
                'settlement_reference' => $reference,
                'paid_at' => now(),
                'notes' => $notes ?: $locked->notes,
            ];
            if ($this->schema->parcelSettlementReady()) {
                $updates += ['payout_method' => $payoutMethod, 'payout_external_reference' => $externalReference];
            }
            $locked->update($updates);
            $receipt = $this->payoutReceipt($locked, $actor);
            $this->audit($productReceipt ? 'SELLER_PAYOUT_SENT_AWAITING_ACKNOWLEDGMENT' : 'SELLER_SETTLEMENT_PAID', $locked, $actor->id, $before, $receipt, $notes);

            $sellerLedger = FinancialLedgerEntry::query()
                ->where('shipment_financial_allocation_id', $locked->shipment_financial_allocation_id)
                ->where('entry_type', 'PRODUCT_REVENUE')
                ->first();
            if ($sellerLedger) {
                $sellerLedger->update([
                    'status' => $productReceipt ? 'PAYOUT_PENDING_ACK' : 'PAID',
                    'actor_user_id' => $actor->id,
                    'paid_at' => now(),
                    'metadata' => array_merge((array) $sellerLedger->metadata, [
                        'payout_reference' => $reference,
                        'payout_amount' => FinancialRules::amount($amountCents),
                        'payout_method' => $payoutMethod,
                        'payout_external_reference' => $externalReference,
                        'is_simulated' => $simulation !== null,
                        'simulation_notice' => $simulation['notice'] ?? null,
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
            if (! SellerSettlement::query()->where('seller_order_id', $locked->seller_order_id)->where('payout_status', '<>', 'PAID')->exists()) {
                CommissionTransaction::query()->where('seller_order_id', $locked->seller_order_id)->where('status', 'PENDING')->update(['status' => 'SETTLED', 'settled_at' => now()]);
            }

            return $locked->refresh();
        });
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
            'is_simulated' => $this->simulatedPayouts->isSimulationReference($settlement->payout_external_reference),
            'simulation_notice' => $this->simulatedPayouts->isSimulationReference($settlement->payout_external_reference)
                ? SimulatedPayoutGateway::NOTICE
                : null,
            'payout_at' => $settlement->paid_at?->toIso8601String(),
            'confirmation_status' => $settlement->payout_status,
            'acknowledged_by_user_id' => $settlement->seller_acknowledged_by_user_id,
            'acknowledged_at' => $settlement->seller_acknowledged_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function audit(string $type, SellerSettlement $settlement, int $actorId, array $before, array $after, ?string $notes): void
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
