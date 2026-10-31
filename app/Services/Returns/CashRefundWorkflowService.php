<?php

namespace App\Services\Returns;

use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\PlatformSetting;
use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Returns\CashRefundObligation;
use App\Models\Returns\ReturnCustodyEvent;
use App\Models\Returns\ReturnInspection;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use App\Services\ReturnRefundWorkflowService;
use App\Services\Finance\PlatformCampaignBudgetService;
use App\Services\Finance\SellerSettlementService;
use App\Support\FinancialRules;
use App\Support\OrderItemParcelAmount;
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
            if (in_array($locked->state, [CashRefundObligation::CASH_PAID, CashRefundObligation::COMPLETED], true)
                && hash_equals((string) $locked->payment_reference_hash, hash('sha256', strtolower($reference)))) {
                return $locked;
            }
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
            $sellerRequest = $locked->sellerRequest()->with(['request', 'items.orderItem'])->first();
            if ($sellerRequest) {
                app(\App\Services\ReturnRefundWorkflowService::class)->recordEvent($sellerRequest->request, 'CASH_REFUND_PAID', $actor, $sellerRequest, $sellerRequest->status, $sellerRequest->status, 'Authorized cash payer recorded the physical cash payment. Buyer receipt confirmation is still pending.', ['obligation_id' => $locked->id]);
                $this->recordReversalEntries($locked, $sellerRequest, $actor);
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
        $sellerOrder = $sellerRequest->sellerOrder()->with(['commissionTransaction', 'shipments', 'items'])->first();
        $allocation = $sellerOrder
            ? ShipmentFinancialAllocation::query()->where('seller_order_id', $sellerOrder->id)->first()
            : null;
        $allocationShipmentId = $allocation?->shipment_id ?? $sellerOrder?->shipments?->first()?->id;
        $refundItems = $sellerRequest->items()->with('orderItem')->orderBy('id')->get();
        $commission = $sellerOrder?->commissionTransaction;
        $sourceAware = (int) ($sellerOrder?->funding_snapshot_version ?? 0) === 1
            && $refundItems->isNotEmpty()
            && $refundItems->every(fn ($requestItem): bool => (int) ($requestItem->orderItem?->funding_snapshot_version ?? 0) === 1);
        $sellerReversalCents = FinancialRules::cents($obligation->product_refund_amount ?? $obligation->approved_amount);
        $commissionReversalCents = 0;
        $commissionRate = (float) ($commission?->commission_rate
            ?? $sellerOrder?->commission_rate_snapshot
            ?? PlatformSetting::commissionRate());
        if ($sourceAware && $sellerOrder) {
            $sellerReversalCents = 0;
            foreach ($refundItems as $requestItem) {
                $orderItem = $requestItem->orderItem;
                if (! $orderItem) {
                    continue;
                }
                $offsetQuantity = (int) DB::table('return_refund_request_items as prior_items')
                    ->join('seller_return_refund_requests as prior_requests', 'prior_requests.id', '=', 'prior_items.seller_return_refund_request_id')
                    ->where('prior_items.order_item_id', $orderItem->id)
                    ->where('prior_items.id', '<', $requestItem->id)
                    ->where('prior_requests.status', '<>', SellerReturnRefundRequest::STATUS_REJECTED)
                    ->sum('prior_items.quantity');
                $quantityTotal = max(1, (int) $orderItem->quantity);
                $quantity = min(max(0, (int) $requestItem->quantity), max(0, $quantityTotal - $offsetQuantity));
                $sellerGrossCents = max(0,
                    FinancialRules::cents($orderItem->line_total)
                    - FinancialRules::cents($orderItem->seller_voucher_discount ?? 0),
                );
                $commissionTotalCents = FinancialRules::commissionCents(
                    FinancialRules::cents($orderItem->original_unit_price) * $quantityTotal,
                    $commissionRate,
                );
                $sellerGrossRefundCents = OrderItemParcelAmount::refundSliceCents(
                    $sellerGrossCents,
                    $quantityTotal,
                    $offsetQuantity,
                    $quantity,
                );
                $commissionItemRefundCents = OrderItemParcelAmount::refundSliceCents(
                    $commissionTotalCents,
                    $quantityTotal,
                    $offsetQuantity,
                    $quantity,
                );
                $sellerReversalCents += max(0, $sellerGrossRefundCents - $commissionItemRefundCents);
                $commissionReversalCents += $commissionItemRefundCents;
            }
        } elseif ($commission) {
            // Historical combined discounts do not identify the Seller-funded
            // base reliably, so preserve the existing proportional adjustment.
            $amount = $sellerReversalCents / 100;
            $base = max(0.01, (float) ($sellerOrder?->item_subtotal ?? $amount));
            $commissionReversalCents = FinancialRules::cents(min(
                (float) $commission->commission_amount,
                $amount / $base * (float) $commission->commission_amount,
            ));
        }
        $entries = [
            ['account_type' => 'SELLER', 'entry_type' => 'REFUND_SELLER_REVERSAL', 'amount' => FinancialRules::amount($sellerReversalCents), 'key' => 'cash-refund-seller-reversal-'.$obligation->id],
            ['account_type' => 'PLATFORM', 'entry_type' => 'REFUND_COMMISSION_REVERSAL', 'amount' => FinancialRules::amount($commissionReversalCents), 'key' => 'cash-refund-commission-reversal-'.$obligation->id],
        ];
        foreach ($entries as $entry) {
            if (FinancialRules::cents($entry['amount']) <= 0) {
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
                    'metadata' => [
                        'cash_refund_obligation_id' => $obligation->id,
                        'funding_snapshot_version' => $sourceAware ? 1 : null,
                        'shipping_and_protection_policy' => 'PENDING_OWNER_POLICY',
                    ],
                ],
            );
        }

        if (FinancialRules::cents($entries[0]['amount']) > 0 && $sellerRequest->seller_profile_id) {
            app(SellerSettlementService::class)->applyRefundDebtToUnpaidSettlements((int) $sellerRequest->seller_profile_id, $actor);
        }

        $this->recordDeliveryFeeReversals($obligation, $sellerRequest, $sellerOrder, $allocation, $allocationShipmentId, $actor);

        $budgetService = app(PlatformCampaignBudgetService::class);
        foreach ($refundItems as $requestItem) {
            $orderItem = $requestItem->orderItem;
            if (! $orderItem || (int) $orderItem->funding_snapshot_version !== 1) {
                continue;
            }
            $quantity = min(max(0, (int) $requestItem->quantity), max(1, (int) $orderItem->quantity));
            $quantityTotal = max(1, (int) $orderItem->quantity);
            $offsetQuantity = (int) DB::table('return_refund_request_items as prior_items')
                ->join('seller_return_refund_requests as prior_requests', 'prior_requests.id', '=', 'prior_items.seller_return_refund_request_id')
                ->where('prior_items.order_item_id', $orderItem->id)
                ->where('prior_items.id', '<', $requestItem->id)
                ->where('prior_requests.status', '<>', SellerReturnRefundRequest::STATUS_REJECTED)
                ->sum('prior_items.quantity');
            // Shipping subsidies follow the separate shipping refund policy;
            // this product return reverses only the product-voucher share.
            $subsidyCents = FinancialRules::cents($orderItem->platform_voucher_discount ?? 0);
            $refundShareCents = OrderItemParcelAmount::refundSliceCents($subsidyCents, $quantityTotal, $offsetQuantity, $quantity);
            $budgetService->reverseForOrderItem(
                $orderItem,
                FinancialRules::amount($refundShareCents),
                'cash-refund-'.$obligation->id.'-request-item-'.$requestItem->id,
                $actor,
                'PRODUCT_VOUCHER',
            );
            $sellerVoucherCents = FinancialRules::cents($orderItem->seller_voucher_discount ?? 0);
            $sellerVoucherRefundCents = OrderItemParcelAmount::refundSliceCents(
                $sellerVoucherCents,
                $quantityTotal,
                $offsetQuantity,
                $quantity,
            );
            app(\App\Services\Finance\SellerVoucherBudgetService::class)->reverseForOrderItem(
                $orderItem,
                FinancialRules::amount($sellerVoucherRefundCents),
                'cash-refund-'.$obligation->id.'-request-item-'.$requestItem->id,
                $actor,
            );
        }
        if (strtoupper((string) ($obligation->shipping_refund_policy ?? '')) === 'REFUNDABLE'
            && FinancialRules::cents($obligation->shipping_refund_amount ?? 0) > 0
            && $sellerOrder) {
            $shippingPaidCents = max(0,
                FinancialRules::cents($sellerOrder->shipping_fee ?? 0)
                - FinancialRules::cents($sellerOrder->shipping_voucher_discount ?? 0),
            );
            $shippingSubsidyWeights = $sellerOrder->items
                ->map(fn ($item): int => max(0, FinancialRules::cents($item->platform_shipping_voucher_discount ?? 0)))
                ->all();
            $shippingSubsidyTotal = array_sum($shippingSubsidyWeights);
            if ($shippingPaidCents > 0 && $shippingSubsidyTotal > 0) {
                $shippingRefundCents = min($shippingPaidCents, FinancialRules::cents($obligation->shipping_refund_amount));
                $shippingSubsidyReversalCents = min(
                    $shippingSubsidyTotal,
                    intdiv(($shippingSubsidyTotal * $shippingRefundCents) + intdiv($shippingPaidCents, 2), $shippingPaidCents),
                );
                $shippingShares = FinancialRules::allocateCents($shippingSubsidyReversalCents, $shippingSubsidyWeights);
                foreach ($sellerOrder->items->values() as $index => $orderItem) {
                    if (($shippingShares[$index] ?? 0) <= 0) {
                        continue;
                    }
                    $budgetService->reverseForOrderItem(
                        $orderItem,
                        FinancialRules::amount($shippingShares[$index]),
                        'cash-refund-'.$obligation->id.'-shipping-item-'.$orderItem->id,
                        $actor,
                        'SHIPPING_VOUCHER',
                    );
                }
            }
        }
    }

    private function recordDeliveryFeeReversals(
        CashRefundObligation $obligation,
        SellerReturnRefundRequest $sellerRequest,
        $sellerOrder,
        ?ShipmentFinancialAllocation $allocation,
        ?int $shipmentId,
        User $actor,
    ): void {
        $componentRefunds = [
            'shipping' => [
                'amount' => $obligation->shipping_refund_amount ?? 0,
                'policy' => strtoupper((string) ($obligation->shipping_refund_policy ?? 'PENDING_OWNER_POLICY')),
                'eligible_amount' => $allocation?->shipping_fee ?? 0,
            ],
            'additional_care' => [
                'amount' => $obligation->protection_refund_amount ?? 0,
                'policy' => strtoupper((string) ($obligation->protection_refund_policy ?? 'PENDING_OWNER_POLICY')),
                'eligible_amount' => $allocation?->protection_amount ?? 0,
            ],
        ];
        $refunds = [];
        foreach ($componentRefunds as $component => $data) {
            $amountCents = max(0, FinancialRules::cents($data['amount']));
            if ($amountCents === 0) {
                continue;
            }
            if ($data['policy'] !== 'REFUNDABLE' || ! $allocation) {
                $this->recordDeliveryRefundReview($obligation, $sellerRequest, $shipmentId, $component, $amountCents, $data['policy'], $actor);
                continue;
            }
            if ($amountCents > FinancialRules::cents($data['eligible_amount'])) {
                $this->recordDeliveryRefundReview($obligation, $sellerRequest, $shipmentId, $component, $amountCents, 'EXCEEDS_ORIGINAL_COMPONENT', $actor);
                continue;
            }
            $refunds[$component] = $amountCents;
        }

        if ($refunds === []) {
            return;
        }

        // Seller-order level refund records do not identify one parcel when a
        // seller order has multiple allocations. Preserve that case for review.
        $allocationCount = ShipmentFinancialAllocation::query()->where('seller_order_id', $sellerRequest->seller_order_id)->count();
        if ($allocationCount !== 1 || ! $allocation) {
            foreach ($refunds as $component => $amountCents) {
                $this->recordDeliveryRefundReview($obligation, $sellerRequest, $shipmentId, $component, $amountCents, 'AMBIGUOUS_MULTI_SHIPMENT_RECIPIENT', $actor);
            }

            return;
        }

        $totalRefundCents = array_sum($refunds);
        $originalDeliveryCents = FinancialRules::cents($allocation->shipping_fee)
            + FinancialRules::cents($allocation->protection_amount ?? 0);
        if ($totalRefundCents > $originalDeliveryCents) {
            foreach ($refunds as $component => $amountCents) {
                $this->recordDeliveryRefundReview($obligation, $sellerRequest, $shipmentId, $component, $amountCents, 'EXCEEDS_ORIGINAL_DELIVERY_CHARGE', $actor);
            }

            return;
        }

        $share = FinancialRules::shippingAllocationCents($totalRefundCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE);
        $logisticsPool = LogisticsEarningPool::query()->where('shipment_financial_allocation_id', $allocation->id)->lockForUpdate()->first();
        $riderPool = RiderEarningPool::query()->where('shipment_financial_allocation_id', $allocation->id)->lockForUpdate()->first();
        $logisticsCanAdjust = $logisticsPool
            && in_array($logisticsPool->status, ['PENDING', 'APPROVED'], true)
            && FinancialRules::cents($logisticsPool->paid_amount ?? 0) === 0
            && FinancialRules::cents($logisticsPool->total_amount) >= $share['logistics_cents'];
        $riderParticipants = $riderPool?->participants()->orderBy('id')->lockForUpdate()->get() ?? collect();
        $riderCanAdjust = $riderPool
            && $riderParticipants->every(fn (RiderEarningPoolParticipant $participant): bool => $participant->status === 'PENDING')
            && FinancialRules::cents($riderPool->paid_amount ?? 0) === 0
            && FinancialRules::cents($riderPool->total_amount) >= $share['rider_cents'];

        if ($logisticsCanAdjust) {
            $logisticsPool->total_amount = FinancialRules::amount(FinancialRules::cents($logisticsPool->total_amount) - $share['logistics_cents']);
            if ($logisticsPool->approved_amount !== null) {
                $logisticsPool->approved_amount = FinancialRules::amount(max(0, FinancialRules::cents($logisticsPool->approved_amount) - $share['logistics_cents']));
            }
            $logisticsPool->save();
        }

        if ($riderCanAdjust) {
            $riderPool->total_amount = FinancialRules::amount(FinancialRules::cents($riderPool->total_amount) - $share['rider_cents']);
            $participantBalances = $riderParticipants->map(fn (RiderEarningPoolParticipant $participant): int => FinancialRules::cents($participant->share_amount ?? 0))->all();
            $participantTotal = array_sum($participantBalances);
            $participantRefund = min($share['rider_cents'], $participantTotal);
            if ($participantRefund > 0 && $participantTotal > 0) {
                $participantRefundShares = FinancialRules::allocateCents($participantRefund, $participantBalances);
                foreach ($riderParticipants as $index => $participant) {
                    if ($participantRefundShares[$index] > 0) {
                        $participant->share_amount = FinancialRules::amount(max(0, $participantBalances[$index] - $participantRefundShares[$index]));
                        $participant->save();
                    }
                }
            }
            $unresolvedRefund = $share['rider_cents'] - $participantRefund;
            if ($unresolvedRefund > 0) {
                $riderPool->unresolved_share_amount = FinancialRules::amount(max(0, FinancialRules::cents($riderPool->unresolved_share_amount ?? 0) - $unresolvedRefund));
            }
            $riderPool->save();
        }

        $adjustmentStatus = $logisticsCanAdjust && $riderCanAdjust ? 'POSTED' : 'ADJUSTMENT_REQUIRED';
        foreach ($refunds as $component => $amountCents) {
            $componentShare = FinancialRules::shippingAllocationCents($amountCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE);
            foreach ([
                ['account' => 'LOGISTICS', 'amount' => $componentShare['logistics_cents']],
                ['account' => 'RIDER', 'amount' => $componentShare['rider_cents']],
            ] as $recipient) {
                if ($recipient['amount'] <= 0) {
                    continue;
                }
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'cash-refund-delivery-'.$recipient['account'].'-'.$component.'-'.$obligation->id],
                    [
                        'shipment_financial_allocation_id' => $allocation->id,
                        'shipment_id' => $allocation->shipment_id,
                        'seller_order_id' => $sellerRequest->seller_order_id,
                        'order_id' => $sellerRequest->request?->order_id,
                        'account_type' => $recipient['account'],
                        'entry_type' => $recipient['account'] === 'LOGISTICS' ? 'REFUND_LOGISTICS_REVERSAL' : 'REFUND_RIDER_REVERSAL',
                        'direction' => 'DEBIT',
                        'amount' => FinancialRules::amount($recipient['amount']),
                        'status' => $recipient['account'] === 'LOGISTICS' ? ($logisticsCanAdjust ? 'POSTED' : 'ADJUSTMENT_REQUIRED') : ($riderCanAdjust ? 'POSTED' : 'ADJUSTMENT_REQUIRED'),
                        'actor_user_id' => $actor->id,
                        'posted_at' => now(),
                        'metadata' => [
                            'cash_refund_obligation_id' => $obligation->id,
                            'component' => $component,
                            'component_refund_amount' => FinancialRules::amount($amountCents),
                            'refund_policy' => 'REFUNDABLE',
                            'recipient_adjustment_status' => $adjustmentStatus,
                        ],
                    ],
                );
            }
        }
    }

    private function recordDeliveryRefundReview(
        CashRefundObligation $obligation,
        SellerReturnRefundRequest $sellerRequest,
        ?int $shipmentId,
        string $component,
        int $amountCents,
        string $reason,
        User $actor,
    ): void {
        FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => 'cash-refund-delivery-review-'.$component.'-'.$obligation->id],
            [
                'shipment_id' => $shipmentId,
                'seller_order_id' => $sellerRequest->seller_order_id,
                'order_id' => $sellerRequest->request?->order_id,
                'account_type' => 'FINANCE_REVIEW',
                'entry_type' => 'REFUND_DELIVERY_ALLOCATION_REVIEW',
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount($amountCents),
                'status' => 'REVIEW_REQUIRED',
                'actor_user_id' => $actor->id,
                'posted_at' => now(),
                'metadata' => [
                    'cash_refund_obligation_id' => $obligation->id,
                    'component' => $component,
                    'reason' => $reason,
                ],
            ],
        );
    }
}
