<?php

namespace App\Services\Finance;

use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\User;
use App\Support\FinancialRules;
use App\Support\OrderItemParcelAmount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReturnTransportLossService
{
    public const BUYER_FAULT = 'BUYER';
    public const SELLER_FAULT = 'SELLER';
    public const ENTRY_TYPE = 'RETURN_TRANSPORT_LOSS';
    public const SELLER_ENTRY_TYPE = 'RETURN_TRANSPORT_CHARGE';

    public function available(): bool
    {
        return Schema::hasTable('shipment_return_workflows')
            && Schema::hasTable('financial_ledger_entries')
            && Schema::hasColumns('financial_ledger_entries', [
                'shipment_id', 'seller_order_id', 'account_type', 'entry_type', 'direction',
                'amount', 'status', 'idempotency_key', 'actor_user_id', 'posted_at', 'metadata',
            ]);
    }

    /** @return Collection<int, ShipmentReturnWorkflow> */
    public function pendingReviews(int $limit = 50): Collection
    {
        if (! $this->available()) {
            return new Collection;
        }

        $relations = [
            'shipment.sellerOrder.sellerProfile',
            'shipment.sellerOrder.shipments',
        ];
        if (Schema::hasTable('order_items')) {
            $relations[] = 'shipment.sellerOrder.items';
        }
        if (Schema::hasColumn('shipments', 'order_item_id')) {
            $relations[] = 'shipment.orderItem';
        }

        $query = ShipmentReturnWorkflow::query()
            ->with($relations)
            ->where('status', ShipmentReturnWorkflow::SELLER_RECEIVED);

        if (Schema::hasColumn('shipment_return_workflows', 'return_disposition')) {
            $query->where('return_disposition', ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN);
        }
        if (Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')) {
            $query->whereNull('seller_return_refund_request_id');
        }

        return $query->latest('initiated_at')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->filter(fn (ShipmentReturnWorkflow $workflow): bool => ! $this->ledgerEntry($workflow))
            ->map(function (ShipmentReturnWorkflow $workflow): ShipmentReturnWorkflow {
                $workflow->setAttribute('return_transport_quote', $this->quote($workflow));

                return $workflow;
            })
            ->values();
    }

    /** @return array{available:bool,reason:?string,checkout_fee_cents:int,trip_count:int,total_cents:int} */
    public function quote(ShipmentReturnWorkflow $workflow): array
    {
        $relations = ['shipment.sellerOrder.shipments'];
        if (Schema::hasTable('order_items')) {
            $relations[] = 'shipment.sellerOrder.items';
        }
        if (Schema::hasColumn('shipments', 'order_item_id')) {
            $relations[] = 'shipment.orderItem';
        }
        $workflow->loadMissing($relations);

        $shipment = $workflow->shipment;
        $sellerOrder = $shipment?->sellerOrder;
        if (! $shipment || ! $sellerOrder) {
            return $this->unavailableQuote('The parcel is missing its checkout shipping record.');
        }

        $items = Schema::hasTable('order_items') ? $sellerOrder->items : collect();
        $item = Schema::hasColumn('shipments', 'order_item_id') ? $shipment->orderItem : null;
        if (! $item && $items->count() === 1 && $sellerOrder->shipments->count() === 1) {
            $item = $items->first();
        }

        if ($item) {
            $checkoutFeeCents = OrderItemParcelAmount::forItem($sellerOrder, $item)['shipping_fee_cents'];
        } elseif ($sellerOrder->shipments->count() === 1) {
            $checkoutFeeCents = FinancialRules::cents($sellerOrder->shipping_fee ?? 0);
        } else {
            return $this->unavailableQuote('The checkout shipping fee cannot be assigned to this parcel safely.');
        }

        $checkoutFeeCents = max(0, (int) $checkoutFeeCents);

        return [
            'available' => true,
            'reason' => null,
            'checkout_fee_cents' => $checkoutFeeCents,
            'trip_count' => 2,
            'total_cents' => $checkoutFeeCents * 2,
        ];
    }

    public function classify(ShipmentReturnWorkflow $workflow, User $actor, string $faultParty, string $notes): FinancialLedgerEntry
    {
        $this->assertAvailable();
        $this->assertAdmin($actor);
        $faultParty = strtoupper(trim($faultParty));
        if (! in_array($faultParty, [self::BUYER_FAULT, self::SELLER_FAULT], true)) {
            throw new RuntimeException('Choose whether the return was caused by Buyer fault or Seller fault.');
        }

        return DB::transaction(function () use ($workflow, $actor, $faultParty, $notes): FinancialLedgerEntry {
            $locked = ShipmentReturnWorkflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ShipmentReturnWorkflow::SELLER_RECEIVED) {
                throw new RuntimeException('Transport losses can be classified after the Seller confirms receipt of the parcel.');
            }
            if (Schema::hasColumn('shipment_return_workflows', 'return_disposition')
                && $locked->return_disposition !== ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN) {
                throw new RuntimeException('Transport loss accounting applies only to returns sent back to the Seller.');
            }

            $existing = $this->ledgerEntry($locked, true);
            if ($existing) {
                if (strtoupper((string) data_get($existing->metadata, 'fault_party')) !== $faultParty) {
                    throw new RuntimeException('This return already has a posted liability decision. Contact Finance for an audited correction.');
                }

                return $existing;
            }

            $quote = $this->quote($locked);
            if (! $quote['available']) {
                throw new RuntimeException((string) $quote['reason']);
            }
            if ($quote['total_cents'] <= 0) {
                throw new RuntimeException('The checkout shipping fee for this parcel is PHP 0.00, so there is no transport amount to post.');
            }

            $shipment = $locked->shipment()->lockForUpdate()->firstOrFail();
            $sellerOrder = $shipment->sellerOrder;
            if (! $sellerOrder) {
                throw new RuntimeException('The parcel is missing its Seller order.');
            }

            $entry = FinancialLedgerEntry::query()->create([
                'shipment_id' => $shipment->id,
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'account_type' => $faultParty === self::BUYER_FAULT ? 'PLATFORM_OPERATING_EXPENSE' : 'SELLER',
                'entry_type' => $faultParty === self::BUYER_FAULT ? self::ENTRY_TYPE : self::SELLER_ENTRY_TYPE,
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount($quote['total_cents']),
                'status' => 'POSTED',
                'idempotency_key' => 'return-transport-loss-workflow-'.$locked->id,
                'actor_user_id' => $actor->id,
                'posted_at' => now(),
                'metadata' => [
                    'source' => 'RETURN_TO_SELLER',
                    'return_workflow_id' => $locked->id,
                    'fault_party' => $faultParty,
                    'decision_notes' => trim($notes),
                    'checkout_shipping_fee' => FinancialRules::amount($quote['checkout_fee_cents']),
                    'trip_count' => $quote['trip_count'],
                    'amount_basis' => 'CHECKOUT_SHIPPING_FEE_X_2_TRIPS',
                    'seller_settlement_adjustments' => [],
                ],
            ]);

            if ($faultParty === self::SELLER_FAULT) {
                app(SellerSettlementService::class)->applyRefundDebtToUnpaidSettlements((int) $sellerOrder->seller_profile_id, $actor);
            }

            return $entry->fresh();
        }, attempts: 3);
    }

    private function ledgerEntry(ShipmentReturnWorkflow $workflow, bool $lock = false): ?FinancialLedgerEntry
    {
        $query = FinancialLedgerEntry::query()
            ->where('idempotency_key', 'return-transport-loss-workflow-'.$workflow->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @return array{available:false,reason:string,checkout_fee_cents:int,trip_count:int,total_cents:int} */
    private function unavailableQuote(string $reason): array
    {
        return ['available' => false, 'reason' => $reason, 'checkout_fee_cents' => 0, 'trip_count' => 2, 'total_cents' => 0];
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new RuntimeException('Return transport accounting is unavailable until the finance ledger schema is installed.');
        }
    }

    private function assertAdmin(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->isAccountType(User::TYPE_ADMIN)) {
            abort(403, 'Only an active Admin can classify and post return transport losses.');
        }
    }
}
