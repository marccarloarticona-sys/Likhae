<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\SellerSettlement;
use App\Support\FinancialRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SellerSettlementService
{
    public function __construct(private readonly FinanceSchemaReadiness $schema) {}

    public function authorize($actor, SellerSettlement $settlement, ?string $notes = null): SellerSettlement
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $settlement, $notes): SellerSettlement {
            $locked = SellerSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->payout_status !== 'PENDING' || $locked->seller_payable === null) {
                throw new RuntimeException('This seller settlement is not eligible for authorization. Resolve any discount-funding review first.');
            }
            $locked->update(['payout_status' => 'AUTHORIZED', 'authorized_by_user_id' => $actor->id, 'authorized_at' => now(), 'notes' => $notes ?: $locked->notes]);
            $this->audit('SELLER_SETTLEMENT_AUTHORIZED', $locked, $actor->id, $notes);

            return $locked->refresh();
        });
    }

    public function pay($actor, SellerSettlement $settlement, string|int|float $amount, ?string $notes = null): SellerSettlement
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $settlement, $amount, $notes): SellerSettlement {
            $locked = SellerSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->payout_status === 'PAID' || $locked->paid_at) {
                throw new RuntimeException('Duplicate seller payout is blocked because this settlement is already paid.');
            }
            if ($locked->payout_status !== 'AUTHORIZED' || $locked->seller_payable === null) {
                throw new RuntimeException('Authorize the seller settlement before recording payment.');
            }
            if (FinancialRules::cents($amount) !== FinancialRules::cents($locked->seller_payable)) {
                throw new RuntimeException('Payout amount must match the authorized seller payable amount.');
            }
            $reference = 'SET-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
            $locked->update([
                'payout_status' => 'PAID',
                'payout_amount' => FinancialRules::amount(FinancialRules::cents($amount)),
                'outstanding_balance' => '0.00',
                'settlement_reference' => $reference,
                'paid_at' => now(),
                'notes' => $notes ?: $locked->notes,
            ]);
            $this->audit('SELLER_SETTLEMENT_PAID', $locked, $actor->id, $notes);

            return $locked->refresh();
        });
    }

    private function assertReady(): void
    {
        if (! $this->schema->step7Ready()) {
            throw new RuntimeException('Seller settlement schema is not installed. Apply the additive Step 7 migration first.');
        }
    }

    private function audit(string $type, SellerSettlement $settlement, int $actorId, ?string $notes): void
    {
        FinanceAuditEvent::query()->create([
            'event_type' => $type,
            'auditable_type' => SellerSettlement::class,
            'auditable_id' => $settlement->id,
            'actor_user_id' => $actorId,
            'after_state' => $settlement->toArray(),
            'notes' => $notes,
        ]);
    }
}
