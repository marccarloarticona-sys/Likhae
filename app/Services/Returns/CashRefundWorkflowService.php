<?php

namespace App\Services\Returns;

use App\Models\Admin\CommissionTransaction;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Returns\CashRefundObligation;
use App\Models\Returns\ReturnCustodyEvent;
use App\Models\Returns\ReturnInspection;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\ReturnRefundWorkflowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CashRefundWorkflowService
{
    public function markCashReady(SellerReturnRefundRequest $sellerRequest, User $actor): CashRefundObligation
    {
        $this->assertReady();

        return DB::transaction(function () use ($sellerRequest, $actor): CashRefundObligation {
            $locked = SellerReturnRefundRequest::query()->whereKey($sellerRequest->id)->lockForUpdate()->with(['request', 'items'])->firstOrFail();
            if (! in_array($locked->status, [SellerReturnRefundRequest::STATUS_APPROVED, SellerReturnRefundRequest::STATUS_ADMIN_REFUND_REQUIRED], true)) {
                throw new RuntimeException('Only an approved return can create a cash refund obligation.');
            }
            if (! self::isNewCashCase($locked->request?->refund_method)) {
                throw new RuntimeException('The cash refund workflow is only available for new COD cash cases. Historical online refunds remain on the legacy payout path.');
            }
            $hasReceipt = ReturnCustodyEvent::query()->where('seller_return_refund_request_id', $locked->id)->where('event_type', 'SELLER_RECEIVED')->exists();
            $itemIds = $locked->items->pluck('id')->map(fn ($id): int => (int) $id)->values();
            $inspectedCount = $itemIds->isEmpty() ? 0 : ReturnInspection::query()
                ->where('seller_return_refund_request_id', $locked->id)
                ->where('inspection_status', 'COMPLETED')
                ->whereIn('return_refund_request_item_id', $itemIds)
                ->distinct()
                ->count('return_refund_request_item_id');
            if (! $hasReceipt || $itemIds->isEmpty() || $inspectedCount !== $itemIds->count()) {
                throw new RuntimeException('Record seller/facility receipt and complete inspection for every returned item before making cash ready.');
            }

            $request = $locked->request;
            $obligation = CashRefundObligation::query()->firstOrCreate(
                ['seller_return_refund_request_id' => $locked->id],
                [
                    'return_refund_request_id' => $locked->return_refund_request_id,
                    'buyer_user_id' => $request->buyer_user_id,
                    'order_id' => $request->order_id,
                    'seller_order_id' => $locked->seller_order_id,
                    'approved_amount' => $locked->approved_amount,
                    'product_refund_amount' => $locked->approved_amount,
                    'shipping_refund_amount' => 0,
                    'protection_refund_amount' => 0,
                    'shipping_refund_policy' => 'PENDING_OWNER_POLICY',
                    'protection_refund_policy' => 'PENDING_OWNER_POLICY',
                    'state' => CashRefundObligation::APPROVED,
                    'idempotency_key' => 'cash-refund-'.$locked->id,
                    'approved_by_user_id' => $locked->reviewed_by_user_id ?: $actor->id,
                    'approved_at' => $locked->reviewed_at ?: now(),
                ],
            );
            if ($obligation->state === CashRefundObligation::COMPLETED || $obligation->state === CashRefundObligation::CASH_PAID) {
                throw new RuntimeException('This cash refund has already been completed.');
            }
            if ($obligation->state === CashRefundObligation::CASH_READY) {
                return $obligation->refresh();
            }
            $obligation->update(['state' => CashRefundObligation::CASH_READY, 'cash_ready_by_user_id' => $actor->id, 'cash_ready_at' => now()]);
            app(ReturnRefundWorkflowService::class)->recordEvent($request, 'CASH_REFUND_READY', $actor, $locked, $locked->status, $locked->status, 'Approved cash refund obligation is ready; physical payout remains pending authorized payer policy.', ['obligation_id' => $obligation->id]);
            $this->recordReversalEntries($obligation, $locked, $actor);

            return $obligation->refresh();
        });
    }

    public function markCashPaid(CashRefundObligation $obligation, User $actor, string $paymentReference): CashRefundObligation
    {
        $this->assertReady();
        $allowed = array_filter((array) config('likhae.cash_refund_authorized_account_types', []));
        if ($actor->status !== User::STATUS_ACTIVE || $allowed === [] || ! in_array($actor->account_type, $allowed, true)) {
            throw new RuntimeException('The authorized physical cash-refund payer policy is not configured for this account.');
        }
        $reference = trim($paymentReference);
        if ($reference === '') {
            throw new RuntimeException('A cash payment reference is required.');
        }

        return DB::transaction(function () use ($obligation, $actor, $reference): CashRefundObligation {
            $locked = CashRefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== CashRefundObligation::CASH_READY) {
                throw new RuntimeException('Cash can only be marked paid from CASH_READY.');
            }
            $locked->update([
                'state' => CashRefundObligation::CASH_PAID,
                'paid_by_user_id' => $actor->id,
                'paid_at' => now(),
                'payment_reference' => $reference,
                'payment_reference_hash' => hash('sha256', strtolower($reference)),
            ]);
            $sellerRequest = $locked->sellerRequest()->with('request')->first();
            if ($sellerRequest) {
                app(\App\Services\ReturnRefundWorkflowService::class)->recordEvent($sellerRequest->request, 'CASH_REFUND_PAID', $actor, $sellerRequest, $sellerRequest->status, $sellerRequest->status, 'Authorized cash payer recorded the physical cash payment. Buyer receipt confirmation is still pending.', ['obligation_id' => $locked->id]);
            }

            return $locked->refresh();
        });
    }

    public function acknowledgeReceipt(CashRefundObligation $obligation, User $buyer): CashRefundObligation
    {
        $this->assertReady();

        return DB::transaction(function () use ($obligation, $buyer): CashRefundObligation {
            $locked = CashRefundObligation::query()->whereKey($obligation->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->buyer_user_id !== (int) $buyer->id) {
                throw new RuntimeException('Only the refund buyer may acknowledge cash receipt.');
            }
            if ($locked->state !== CashRefundObligation::CASH_PAID) {
                throw new RuntimeException('A cash refund must be paid before the buyer can acknowledge receipt.');
            }
            $locked->update(['state' => CashRefundObligation::COMPLETED, 'buyer_acknowledged_by_user_id' => $buyer->id, 'buyer_acknowledged_at' => now()]);

            $sellerRequest = $locked->sellerRequest()->with('request')->first();
            if ($sellerRequest && $sellerRequest->status !== SellerReturnRefundRequest::STATUS_CONFIRMED) {
                $fromStatus = $sellerRequest->status;
                $sellerRequest->update(['status' => SellerReturnRefundRequest::STATUS_CONFIRMED]);
                app(ReturnRefundWorkflowService::class)->recordEvent($sellerRequest->request, 'BUYER_CONFIRMED_CASH_RECEIPT', $buyer, $sellerRequest, $fromStatus, SellerReturnRefundRequest::STATUS_CONFIRMED, 'Buyer acknowledged cash refund receipt.', ['obligation_id' => $locked->id]);
            }

            return $locked->refresh();
        });
    }

    public static function isNewCashCase(?string $refundMethod): bool
    {
        return strtoupper(trim((string) $refundMethod)) === 'CASH';
    }

    private function assertReady(): void
    {
        if (! Schema::hasTable('cash_refund_obligations')) {
            throw new RuntimeException('Cash refund obligation tables are pending additive migration approval.');
        }
    }

    private function recordReversalEntries(CashRefundObligation $obligation, SellerReturnRefundRequest $sellerRequest, User $actor): void
    {
        if (! Schema::hasTable('financial_ledger_entries')) {
            return;
        }
        $sellerOrder = $sellerRequest->sellerOrder()->with(['commissionTransaction', 'shipments'])->first();
        $allocation = $sellerOrder
            ? ShipmentFinancialAllocation::query()->where('seller_order_id', $sellerOrder->id)->first()
            : null;
        $allocationShipmentId = $allocation?->shipment_id ?? $sellerOrder?->shipments?->first()?->id;
        $amount = (float) $obligation->product_refund_amount;
        $commission = $sellerOrder?->commissionTransaction;
        $base = max(0.01, (float) ($sellerOrder?->item_subtotal ?? $amount));
        $commissionReversal = $commission ? round(min((float) $commission->commission_amount, $amount / $base * (float) $commission->commission_amount), 2) : 0;
        $entries = [
            ['account_type' => 'SELLER', 'entry_type' => 'REFUND_SELLER_REVERSAL', 'amount' => $amount, 'key' => 'cash-refund-seller-reversal-'.$obligation->id],
            ['account_type' => 'PLATFORM', 'entry_type' => 'REFUND_COMMISSION_REVERSAL', 'amount' => $commissionReversal, 'key' => 'cash-refund-commission-reversal-'.$obligation->id],
        ];
        foreach ($entries as $entry) {
            if ($entry['amount'] <= 0) {
                continue;
            }
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => $entry['key']],
                [
                    'shipment_financial_allocation_id' => $allocation?->id,
                    'shipment_id' => $allocationShipmentId,
                    'seller_order_id' => $sellerRequest->seller_order_id,
                    'order_id' => $sellerRequest->request?->order_id,
                    'account_type' => $entry['account_type'],
                    'entry_type' => $entry['entry_type'],
                    'direction' => 'DEBIT',
                    'amount' => $entry['amount'],
                    'status' => 'POSTED',
                    'actor_user_id' => $actor->id,
                    'posted_at' => now(),
                    'metadata' => ['cash_refund_obligation_id' => $obligation->id, 'shipping_and_protection_policy' => 'PENDING_OWNER_POLICY'],
                ],
            );
        }
    }
}
