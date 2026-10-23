<?php

namespace App\Services\Finance;

use App\Models\Admin\CommissionTransaction;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\SellerSettlement;
use App\Models\Finance\ShipmentFinancialAllocation;
use App\Models\Finance\CodCollection;
use App\Models\Logistics\Shipment;
use App\Support\FinancialRules;
use App\Support\OrderItemParcelAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class FinancialAllocationService
{
    public function __construct(
        private readonly FinanceSchemaReadiness $schema,
        private readonly CodCollectionService $collections,
    ) {}

    public function initializeForShipment(Shipment $shipment): ?ShipmentFinancialAllocation
    {
        if (! $this->schema->step7Ready() || ! $this->schema->originalPriceSnapshotReady()) {
            return null;
        }

        if (! $this->schema->parcelSettlementReady()) {
            return $this->initializeLegacyForShipment($shipment);
        }

        if (! in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true)) {
            return null;
        }

        $shipment->loadMissing(
            'sellerOrder.items',
            'sellerOrder.order',
            'sellerOrder.shipments.riderAssignments',
            'orderItem',
            'codCollection',
            'destinationLogisticsCenter',
            'logisticsCenter',
        );
        $sellerOrder = $shipment->sellerOrder;
        $item = $shipment->orderItem;
        if (! $item && $sellerOrder?->items?->count() === 1 && $sellerOrder->shipments->count() === 1) {
            $item = $sellerOrder->items->first();
        }
        if (! $sellerOrder?->order || ! $item || (int) $item->seller_order_id !== (int) $sellerOrder->id || $item->original_unit_price === null) {
            return null;
        }

        $collection = $shipment->codCollection;
        if (! $collection || ! in_array($collection->status, [CodCollection::COLLECTED, CodCollection::REMITTED], true)) {
            // A delivery with a cash discrepancy or no collection proof may
            // complete operationally, but it cannot create a payable snapshot.
            return null;
        }

        $allocation = DB::transaction(function () use ($shipment, $sellerOrder, $item, $collection): ShipmentFinancialAllocation {
            $allocation = ShipmentFinancialAllocation::query()->firstOrCreate(
                ['shipment_id' => $shipment->id],
                ['seller_order_id' => $sellerOrder->id, 'order_id' => $sellerOrder->order_id]
                    + $this->parcelAttributes($shipment, $sellerOrder, $item),
            );
            $this->provisionParcelSettlementRows($allocation, $shipment, $sellerOrder, $collection);

            return $allocation->refresh();
        });

        $pool = $allocation->riderPool()->with('participants')->first();
        if ($pool) {
            $this->allocateCompletedRiderLegsForParcel($pool, $shipment, $sellerOrder);
        }

        return $allocation;
    }

    private function initializeLegacyForShipment(Shipment $shipment): ?ShipmentFinancialAllocation
    {
        if (! $this->schema->step7Ready() || ! $this->schema->originalPriceSnapshotReady()) {
            return null;
        }
        if (! in_array($shipment->current_status, ['DELIVERED', 'COMPLETED'], true)) {
            // New-order allocation is intentionally deferred until delivery
            // is valid. Historical/manual callers must not create payable
            // financial rows for an undelivered shipment.
            return null;
        }

        $shipment->loadMissing('sellerOrder.items', 'sellerOrder.shipments.riderAssignments', 'sellerOrder.order', 'destinationLogisticsCenter', 'logisticsCenter');
        $sellerOrder = $shipment->sellerOrder;
        $items = $sellerOrder?->items ?? collect();
        if (! $sellerOrder?->order || $items->isEmpty() || $items->contains(fn ($item): bool => $item->original_unit_price === null)) {
            // Historical rows without a trustworthy checkout snapshot remain manual review.
            return null;
        }

        if ($sellerOrder->shipments->isNotEmpty()
            && $sellerOrder->shipments->contains(fn (Shipment $parcel): bool => ! in_array($parcel->current_status, ['DELIVERED', 'COMPLETED'], true))) {
            // The order-level financial allocation is finalized once, after
            // every item parcel has been delivered and its COD is recorded.
            return null;
        }

        $allocation = DB::transaction(function () use ($shipment, $sellerOrder, $items): ShipmentFinancialAllocation {
            $allocation = ShipmentFinancialAllocation::query()->firstOrCreate(
                ['seller_order_id' => $sellerOrder->id],
                ['shipment_id' => $shipment->id] + $this->attributes($shipment, $sellerOrder, $items),
            );
            $this->provisionSettlementRows($allocation, $shipment, $sellerOrder);

            return $allocation->refresh();
        });

        $pool = $allocation->riderPool()->with('participants')->first();
        if ($pool) {
            $this->allocateCompletedRiderLegs($pool, $sellerOrder);
        }

        return $allocation;
    }

    public function syncRiderParticipant(Shipment $shipment, int $riderProfileId, string $role, ?int $assignmentId = null): void
    {
        if (! $this->schema->step7Ready() || ! Schema::hasTable('rider_earning_pool_participants')) {
            return;
        }
        $pool = $shipment->riderEarningPool()->first();
        if (! $pool) {
            return;
        }
        $pool->participants()->firstOrCreate(
            ['rider_profile_id' => $riderProfileId, 'participant_role' => strtoupper($role)],
            ['rider_assignment_id' => $assignmentId, 'status' => 'PENDING'],
        );
    }

    /** @return array<string, string|null> */
    private function attributes(Shipment $shipment, $sellerOrder, $items): array
    {
        $rateVersion = null;
        if (Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            $sellerOrder->loadMissing('shippingRateVersion');
            $rateVersion = $sellerOrder->shippingRateVersion;
        }
        $riderPoolPercentage = $rateVersion?->rider_pool_percentage ?? ShippingRatePolicyService::DEFAULT_RIDER_PERCENTAGE;
        $combinedDiscount = FinancialRules::amount(
            FinancialRules::cents($sellerOrder->voucher_discount)
            + FinancialRules::cents($sellerOrder->reward_points_discount ?? 0),
        );
        $calculation = FinancialRules::allocation(
            $items->map(fn ($item): array => [
                'original_unit_price' => $item->original_unit_price,
                'charged_unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
            ])->all(),
            $combinedDiscount,
            $sellerOrder->shipping_fee,
            (string) $riderPoolPercentage,
            $sellerOrder->shipping_voucher_discount ?? 0,
        );

        $attributes = [
            'seller_order_id' => $sellerOrder->id,
            'order_id' => $sellerOrder->order_id,
            'currency' => 'PHP',
            'original_product_amount' => $calculation['original_product_amount'],
            'product_amount' => $calculation['product_amount'],
            'discount_amount' => $calculation['discount_amount'],
            'shipping_fee' => $calculation['shipping_fee'],
            'platform_commission' => $calculation['platform_commission'],
            'seller_payable' => $calculation['seller_payable'],
            'logistics_allocation' => $calculation['logistics_allocation'],
            'rider_allocation' => $calculation['rider_allocation'],
            'protection_amount' => round((float) ($sellerOrder->protection_total ?? $items->sum(fn ($item) => (float) ($item->protection_fee ?? 0))), 2),
            'protection_account_status' => ((float) ($sellerOrder->protection_total ?? $items->sum(fn ($item) => (float) ($item->protection_fee ?? 0))) > 0) ? 'PENDING_UNALLOCATED' : 'NOT_APPLICABLE',
            'discount_funding_status' => $calculation['discount_funding_unresolved'] ? 'UNRESOLVED' : 'NOT_APPLICABLE',
            'status' => $calculation['discount_funding_unresolved'] ? 'REVIEW_REQUIRED' : 'PENDING',
            'calculated_at' => now(),
        ];
        if (! Schema::hasColumn('shipment_financial_allocations', 'protection_amount')) {
            unset($attributes['protection_amount'], $attributes['protection_account_status']);
        }

        return $attributes;
    }

    /** @return array<string, string|null> */
    private function parcelAttributes(Shipment $shipment, $sellerOrder, $item): array
    {
        $parcelAmounts = OrderItemParcelAmount::forItem($sellerOrder, $item);
        $riderPoolPercentage = FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE;
        if (Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            $sellerOrder->loadMissing('shippingRateVersion');
            $riderPoolPercentage = $sellerOrder->shippingRateVersion?->rider_pool_percentage
                ?? FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE;
        }
        $calculation = FinancialRules::allocation(
            [[
                'original_unit_price' => $item->original_unit_price,
                'charged_unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
            ]],
            $parcelAmounts['discount_amount'],
            $parcelAmounts['shipping_fee'],
            $riderPoolPercentage,
            $parcelAmounts['shipping_discount'],
        );

        $attributes = [
            'seller_order_id' => $sellerOrder->id,
            'order_id' => $sellerOrder->order_id,
            'currency' => 'PHP',
            'original_product_amount' => $calculation['original_product_amount'],
            'product_amount' => $calculation['product_amount'],
            'discount_amount' => $calculation['discount_amount'],
            'shipping_fee' => $calculation['shipping_fee'],
            'platform_commission' => $calculation['platform_commission'],
            'seller_payable' => $calculation['seller_payable'],
            'logistics_allocation' => $calculation['logistics_allocation'],
            'rider_allocation' => $calculation['rider_allocation'],
            'protection_amount' => number_format((float) $parcelAmounts['protection_amount'], 2, '.', ''),
            'protection_account_status' => FinancialRules::cents($parcelAmounts['protection_amount']) > 0
                ? 'PENDING_UNALLOCATED'
                : 'NOT_APPLICABLE',
            'discount_funding_status' => $calculation['discount_funding_unresolved'] ? 'UNRESOLVED' : 'NOT_APPLICABLE',
            'status' => $calculation['discount_funding_unresolved'] ? 'REVIEW_REQUIRED' : 'PENDING',
            'calculated_at' => now(),
        ];
        if (! Schema::hasColumn('shipment_financial_allocations', 'protection_amount')) {
            unset($attributes['protection_amount'], $attributes['protection_account_status']);
        }

        return $attributes;
    }

    private function allocateCompletedRiderLegs(RiderEarningPool $pool, $sellerOrder): void
    {
        $rateVersion = null;
        if (Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            $sellerOrder->loadMissing('shippingRateVersion');
            $rateVersion = $sellerOrder->shippingRateVersion;
        }

        $completedRidersByRole = ['PICKUP' => [], 'HUB_TO_HUB' => [], 'DELIVERY' => []];
        $representativeAssignments = [];
        foreach ($sellerOrder->shipments->flatMap->riderAssignments as $assignment) {
            if ($assignment->status !== 'COMPLETED' || ! $assignment->rider_profile_id) {
                continue;
            }

            // The current assignment model has PICKUP and DELIVERY only.
            // Its configured future Hub weight is excluded from the active
            // denominator until an authoritative completed Hub leg exists.
            $role = match ($assignment->assignment_type) {
                'PICKUP' => 'PICKUP',
                'DELIVERY' => 'DELIVERY',
                default => null,
            };
            if ($role === null) {
                continue;
            }

            $riderId = (int) $assignment->rider_profile_id;
            $completedRidersByRole[$role][] = $riderId;
            $representativeAssignments[$role][$riderId] ??= (int) $assignment->id;
        }

        $distribution = FinancialRules::riderLegDistribution(
            FinancialRules::cents((string) $pool->total_amount),
            $completedRidersByRole,
            [
                'PICKUP' => (int) ($rateVersion?->pickup_rider_weight ?? ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT),
                'HUB_TO_HUB' => (int) ($rateVersion?->hub_to_hub_rider_weight ?? ShippingRatePolicyService::DEFAULT_HUB_WEIGHT),
                'DELIVERY' => (int) ($rateVersion?->last_mile_rider_weight ?? ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT),
            ],
        );

        foreach ($distribution['participants'] as $participantData) {
            $assignmentId = $representativeAssignments[$participantData['participant_role']][$participantData['rider_profile_id']] ?? null;
            $participant = $pool->participants()->firstOrNew([
                'rider_profile_id' => $participantData['rider_profile_id'],
                'participant_role' => $participantData['participant_role'],
            ]);
            if (! $participant->exists
                || ($participant->share_amount === null && $participant->status === 'PENDING')) {
                $participant->forceFill([
                    'rider_assignment_id' => $assignmentId,
                    'share_amount' => FinancialRules::amount($participantData['share_cents']),
                    'status' => 'PENDING',
                ])->save();
            }
        }

        $pool->forceFill([
            'unresolved_share_amount' => FinancialRules::amount($distribution['unresolved_cents']),
        ])->save();
    }

    private function allocateCompletedRiderLegsForParcel(RiderEarningPool $pool, Shipment $shipment, $sellerOrder): void
    {
        $shipment->loadMissing('riderAssignments');
        $rateVersion = null;
        if (Schema::hasColumn('seller_orders', 'shipping_rate_version_id')) {
            $sellerOrder->loadMissing('shippingRateVersion');
            $rateVersion = $sellerOrder->shippingRateVersion;
        }
        $completedRidersByRole = ['PICKUP' => [], 'HUB_TO_HUB' => [], 'DELIVERY' => []];
        $representativeAssignments = [];

        foreach ($shipment->riderAssignments as $assignment) {
            if ($assignment->status !== 'COMPLETED' || ! $assignment->rider_profile_id) {
                continue;
            }
            $role = match ($assignment->assignment_type) {
                'PICKUP' => 'PICKUP',
                'DELIVERY' => 'DELIVERY',
                default => null,
            };
            if ($role === null) {
                continue;
            }
            $riderId = (int) $assignment->rider_profile_id;
            $completedRidersByRole[$role][] = $riderId;
            $representativeAssignments[$role][$riderId] ??= (int) $assignment->id;
        }

        $distribution = FinancialRules::riderLegDistribution(
            FinancialRules::cents((string) $pool->total_amount),
            $completedRidersByRole,
            [
                'PICKUP' => (int) ($rateVersion?->pickup_rider_weight ?? ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT),
                'HUB_TO_HUB' => (int) ($rateVersion?->hub_to_hub_rider_weight ?? ShippingRatePolicyService::DEFAULT_HUB_WEIGHT),
                'DELIVERY' => (int) ($rateVersion?->last_mile_rider_weight ?? ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT),
            ],
        );

        foreach ($distribution['participants'] as $participantData) {
            $assignmentId = $representativeAssignments[$participantData['participant_role']][$participantData['rider_profile_id']] ?? null;
            $participant = $pool->participants()->firstOrNew([
                'rider_profile_id' => $participantData['rider_profile_id'],
                'participant_role' => $participantData['participant_role'],
            ]);
            if (! $participant->exists || ($participant->share_amount === null && $participant->status === 'PENDING')) {
                $participant->forceFill([
                    'rider_assignment_id' => $assignmentId,
                    'share_amount' => FinancialRules::amount($participantData['share_cents']),
                    'status' => 'PENDING',
                ])->save();
            }
        }

        $pool->forceFill(['unresolved_share_amount' => FinancialRules::amount($distribution['unresolved_cents'])])->save();
    }

    private function provisionParcelSettlementRows(
        ShipmentFinancialAllocation $allocation,
        Shipment $shipment,
        $sellerOrder,
        CodCollection $collection,
    ): void {
        $centerId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
        if (! $centerId) {
            throw new RuntimeException('A logistics center is required before creating a parcel earning pool.');
        }

        $sellerPayableForReview = $allocation->seller_payable === null
            ? (float) $allocation->product_amount
            : (float) $allocation->seller_payable;
        SellerSettlement::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'seller_profile_id' => $sellerOrder->seller_profile_id,
                'original_product_amount' => $allocation->original_product_amount,
                'discount_amount' => $allocation->discount_amount,
                'platform_commission' => $allocation->platform_commission,
                'seller_payable' => $allocation->seller_payable,
                'payout_amount' => 0,
                'outstanding_balance' => $sellerPayableForReview,
                'payout_status' => $allocation->seller_payable === null ? 'REVIEW_REQUIRED' : 'PENDING',
            ],
        );

        $commissionTotals = ShipmentFinancialAllocation::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->get(['original_product_amount', 'platform_commission']);
        $commissionTransaction = CommissionTransaction::query()->firstOrNew(['seller_order_id' => $sellerOrder->id]);
        if (! $commissionTransaction->exists || $commissionTransaction->status === 'PENDING') {
            $commissionTransaction->fill([
                'commission_rate' => FinancialRules::COMMISSION_RATE,
                'commissionable_amount' => FinancialRules::amount($commissionTotals->sum(fn ($row): int => FinancialRules::cents($row->original_product_amount))),
                'commission_amount' => FinancialRules::amount($commissionTotals->sum(fn ($row): int => FinancialRules::cents($row->platform_commission))),
                'status' => $commissionTransaction->status ?: 'PENDING',
                'calculated_at' => $allocation->calculated_at ?: now(),
            ])->save();
        }

        LogisticsEarningPool::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'shipment_id' => $shipment->id,
                'logistics_center_id' => $centerId,
                'total_amount' => $allocation->logistics_allocation,
                'unresolved_share_amount' => $allocation->logistics_allocation,
                'status' => 'PENDING',
            ],
        );
        RiderEarningPool::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'shipment_id' => $shipment->id,
                'total_amount' => $allocation->rider_allocation,
                'unresolved_share_amount' => $allocation->rider_allocation,
                'status' => 'PENDING',
            ],
        );

        $reviewStatus = $allocation->seller_payable === null ? 'REVIEW_REQUIRED' : 'PENDING';
        $entries = [
            ['account_type' => 'SELLER', 'entry_type' => 'PRODUCT_REVENUE', 'direction' => 'CREDIT', 'amount' => $allocation->seller_payable ?? 0, 'key' => 'seller-product-'.$allocation->id],
            ['account_type' => 'PLATFORM', 'entry_type' => 'PLATFORM_COMMISSION', 'direction' => 'CREDIT', 'amount' => $allocation->platform_commission, 'key' => 'platform-commission-'.$allocation->id],
            ['account_type' => 'LOGISTICS', 'entry_type' => 'LOGISTICS_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->logistics_allocation, 'key' => 'logistics-allocation-'.$allocation->id],
            ['account_type' => 'RIDER', 'entry_type' => 'RIDER_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->rider_allocation, 'key' => 'rider-allocation-'.$allocation->id],
            ['account_type' => 'CASH_CONTROL', 'entry_type' => 'COD_EXPECTED', 'direction' => 'DEBIT', 'amount' => $collection->expected_amount, 'key' => 'cod-expected-'.$allocation->id],
        ];
        $protectionAmount = FinancialRules::cents($allocation->protection_amount ?? 0);
        if ($protectionAmount > 0) {
            $entries[] = ['account_type' => 'PROTECTION_PENDING', 'entry_type' => 'PROTECTION_UNALLOCATED', 'direction' => 'CREDIT', 'amount' => FinancialRules::amount($protectionAmount), 'key' => 'protection-unallocated-'.$allocation->id];
        }
        foreach ($entries as $entry) {
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => $entry['key']],
                [
                    'shipment_financial_allocation_id' => $allocation->id,
                    'shipment_id' => $shipment->id,
                    'seller_order_id' => $sellerOrder->id,
                    'order_id' => $sellerOrder->order_id,
                    'account_type' => $entry['account_type'],
                    'entry_type' => $entry['entry_type'],
                    'direction' => $entry['direction'],
                    'amount' => $entry['amount'],
                    'status' => $reviewStatus,
                    'posted_at' => now(),
                ],
            );
        }

        FinancialLedgerEntry::query()
            ->where('shipment_id', $shipment->id)
            ->where('entry_type', 'COD_COLLECTED')
            ->whereNull('shipment_financial_allocation_id')
            ->update(['shipment_financial_allocation_id' => $allocation->id]);

        FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => 'product-proceeds-remittance-'.$allocation->id],
            [
                'shipment_financial_allocation_id' => $allocation->id,
                'shipment_id' => $shipment->id,
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'PRODUCT_PROCEEDS_REMITTANCE',
                'direction' => 'DEBIT',
                'amount' => FinancialRules::amount(
                    FinancialRules::cents($allocation->product_amount)
                    + FinancialRules::cents($allocation->logistics_allocation),
                ),
                'status' => 'PENDING',
                'posted_at' => now(),
                'metadata' => [
                    'receipt_reference' => null,
                    'currency' => $allocation->currency ?: 'PHP',
                    'amount_expected' => FinancialRules::amount(
                        FinancialRules::cents($allocation->product_amount)
                        + FinancialRules::cents($allocation->logistics_allocation),
                    ),
                    'product_amount' => $allocation->product_amount,
                    'logistics_shipping_share' => $allocation->logistics_allocation,
                    'rider_shipping_share' => $allocation->rider_allocation,
                    'amount_submitted' => null,
                    'amount_received' => null,
                    'discrepancy_amount' => null,
                    'sender' => ['role' => 'LOGISTICS', 'logistics_center_id' => $centerId],
                    'recipient' => ['role' => 'ADMIN'],
                ],
            ],
        );

        FinanceAuditEvent::query()->firstOrCreate(
            [
                'event_type' => 'PARCEL_ALLOCATION_CREATED',
                'auditable_type' => ShipmentFinancialAllocation::class,
                'auditable_id' => $allocation->id,
            ],
            [
                'after_state' => $allocation->toArray(),
                'notes' => 'Parcel allocation created from the immutable item price, parcel shipping, discount, COD, and protection snapshots.',
            ],
        );
    }

    private function provisionSettlementRows(ShipmentFinancialAllocation $allocation, Shipment $shipment, $sellerOrder): void
    {
        $centerId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
        if (! $centerId) {
            throw new RuntimeException('A logistics center is required before creating a logistics earning pool.');
        }

        // Keep an amount visible while a discount-funding decision is pending,
        // but leave seller_payable null and block authorization/payout.
        $sellerPayable = $allocation->seller_payable === null
            ? (float) $allocation->product_amount
            : (float) $allocation->seller_payable;
        SellerSettlement::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'seller_profile_id' => $sellerOrder->seller_profile_id,
                'original_product_amount' => $allocation->original_product_amount,
                'discount_amount' => $allocation->discount_amount,
                'platform_commission' => $allocation->platform_commission,
                'seller_payable' => $allocation->seller_payable,
                'payout_amount' => 0,
                'outstanding_balance' => $sellerPayable,
                'payout_status' => $allocation->seller_payable === null ? 'REVIEW_REQUIRED' : 'PENDING',
            ],
        );
        CommissionTransaction::query()->firstOrCreate(
            ['seller_order_id' => $sellerOrder->id],
            [
                'commission_rate' => FinancialRules::COMMISSION_RATE,
                'commissionable_amount' => $allocation->original_product_amount,
                'commission_amount' => $allocation->platform_commission,
                'status' => 'PENDING',
                'calculated_at' => $allocation->calculated_at ?: now(),
            ],
        );
        LogisticsEarningPool::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'shipment_id' => $shipment->id,
                'logistics_center_id' => $centerId,
                'total_amount' => $allocation->logistics_allocation,
                'unresolved_share_amount' => $allocation->logistics_allocation,
                'status' => 'PENDING',
            ],
        );
        RiderEarningPool::query()->firstOrCreate(
            ['shipment_financial_allocation_id' => $allocation->id],
            [
                'shipment_id' => $shipment->id,
                'total_amount' => $allocation->rider_allocation,
                'unresolved_share_amount' => $allocation->rider_allocation,
                'status' => 'PENDING',
            ],
        );

        // The seller ledger represents the seller's distributable payable,
        // not the gross product amount. Commission is posted separately, so
        // using product_amount here would double-count the commission and make
        // the basic allocation appear to exceed the COD collected amount.
        $entries = [
            ['account_type' => 'SELLER', 'entry_type' => 'PRODUCT_REVENUE', 'direction' => 'CREDIT', 'amount' => $allocation->seller_payable ?? 0, 'key' => 'seller-product-'.$allocation->id],
            ['account_type' => 'PLATFORM', 'entry_type' => 'PLATFORM_COMMISSION', 'direction' => 'CREDIT', 'amount' => $allocation->platform_commission, 'key' => 'platform-commission-'.$allocation->id],
            ['account_type' => 'LOGISTICS', 'entry_type' => 'LOGISTICS_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->logistics_allocation, 'key' => 'logistics-allocation-'.$allocation->id],
            ['account_type' => 'RIDER', 'entry_type' => 'RIDER_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->rider_allocation, 'key' => 'rider-allocation-'.$allocation->id],
            ['account_type' => 'CASH_CONTROL', 'entry_type' => 'COD_EXPECTED', 'direction' => 'DEBIT', 'amount' => $sellerOrder->grand_total, 'key' => 'cod-expected-'.$allocation->id],
        ];
        $protectionAmount = (float) ($allocation->protection_amount ?? 0);
        if ($protectionAmount > 0) {
            $entries[] = ['account_type' => 'PROTECTION_PENDING', 'entry_type' => 'PROTECTION_UNALLOCATED', 'direction' => 'CREDIT', 'amount' => $protectionAmount, 'key' => 'protection-unallocated-'.$allocation->id];
        }
        foreach ($entries as $entry) {
            FinancialLedgerEntry::query()->firstOrCreate(
                ['idempotency_key' => $entry['key']],
                [
                    'shipment_financial_allocation_id' => $allocation->id,
                    'shipment_id' => $shipment->id,
                    'seller_order_id' => $sellerOrder->id,
                    'order_id' => $sellerOrder->order_id,
                    'account_type' => $entry['account_type'],
                    'entry_type' => $entry['entry_type'],
                    'direction' => $entry['direction'],
                    'amount' => $entry['amount'],
                    'status' => 'PENDING',
                    'posted_at' => now(),
                ],
            );
        }
        // COD is recorded before the delivery transaction creates the Step 7
        // allocation. Link that existing immutable entry once the allocation
        // is available without creating a second collection entry.
        FinancialLedgerEntry::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->where('entry_type', 'COD_COLLECTED')
            ->whereNull('shipment_financial_allocation_id')
            ->update(['shipment_financial_allocation_id' => $allocation->id]);
        FinanceAuditEvent::query()->firstOrCreate(
            ['event_type' => 'ALLOCATION_CREATED', 'auditable_type' => ShipmentFinancialAllocation::class, 'auditable_id' => $allocation->id],
            ['after_state' => $allocation->toArray(), 'notes' => 'Step 7 allocation created from immutable checkout snapshots.'],
        );
    }
}
