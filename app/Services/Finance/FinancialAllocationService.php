<?php

namespace App\Services\Finance;

use App\Models\Admin\CommissionTransaction;
use App\Models\Admin\PlatformSetting;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\LogisticsEarningPool;
use App\Models\Finance\RiderEarningPool;
use App\Models\Finance\RiderEarningPoolParticipant;
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

        if ($this->isInterHubShipment($shipment)
            && (! $this->schema->parcelSettlementReady() || ! $this->schema->multiHubLogisticsPoolsReady())) {
            // Keep the parcel out of payable finance until the additive
            // allocation-per-center index migration is installed.
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
        if (! $collection || ! in_array($collection->status, [CodCollection::COLLECTED, CodCollection::REMITTED, CodCollection::REMITTED_RIDER_TOPUP], true)) {
            // An uncovered buyer collection discrepancy or missing collection
            // proof cannot create a payable snapshot.
            return null;
        }

        $allocation = DB::transaction(function () use ($shipment, $sellerOrder, $item, $collection): ShipmentFinancialAllocation {
            $allocation = ShipmentFinancialAllocation::query()->firstOrCreate(
                ['shipment_id' => $shipment->id],
                ['seller_order_id' => $sellerOrder->id, 'order_id' => $sellerOrder->order_id]
                    + $this->parcelAttributes($shipment, $sellerOrder, $item),
            );
            if (! $allocation->wasRecentlyCreated) {
                $savedCenters = $allocation->logisticsPools()->pluck('logistics_center_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                $destination = (int) ($shipment->destination_logistics_center_id ?: $shipment->logistics_center_id ?: 0);
                $routeCenters = $this->isInterHubShipment($shipment)
                    ? [(int) $shipment->origin_logistics_center_id, $destination]
                    : [$destination];
                sort($routeCenters);
                if ($savedCenters !== [] && $savedCenters !== $routeCenters) {
                    // Route changes cannot retroactively create or move historical shares.
                    return $allocation;
                }
            }
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

        if ($sellerOrder->shipments->count() > 1) {
            // The legacy schema has one allocation and one recipient pair per
            // seller order. It cannot safely associate charges with separate
            // parcel recipients, so keep these records for manual review
            // until per-parcel finance is enabled.
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
        $riderPoolPercentage = FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE;
        $combinedDiscount = FinancialRules::amount(
            FinancialRules::cents($sellerOrder->voucher_discount)
            + FinancialRules::cents($sellerOrder->reward_points_discount ?? 0),
        );
        $itemProtectionCents = $items->sum(fn ($item): int => max(0, FinancialRules::cents($item->protection_fee ?? 0)));
        $protectionCents = $sellerOrder->protection_total !== null
            ? max(0, FinancialRules::cents($sellerOrder->protection_total))
            : $itemProtectionCents;
        $protectionAmount = FinancialRules::amount($protectionCents);
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
            $this->fundingSnapshotForSellerOrder($sellerOrder, $items),
            $this->commissionRateFor($sellerOrder),
            $protectionAmount,
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
            'protection_amount' => $protectionAmount,
            'protection_account_status' => $protectionCents > 0 ? 'ALLOCATED_TO_EARNINGS' : 'NOT_APPLICABLE',
            'discount_funding_status' => $calculation['discount_funding_unresolved'] ? 'UNRESOLVED' : 'NOT_APPLICABLE',
            'status' => $calculation['discount_funding_unresolved'] ? 'REVIEW_REQUIRED' : 'PENDING',
            'calculated_at' => now(),
        ];
        if (! Schema::hasColumn('shipment_financial_allocations', 'protection_amount')) {
            unset($attributes['protection_amount'], $attributes['protection_account_status']);
        }

        return $attributes;
    }

    private function fundingSnapshotForSellerOrder($sellerOrder, $items): ?array
    {
        if ((int) ($sellerOrder->funding_snapshot_version ?? 0) !== 1
            || $items->isEmpty()
            || $items->contains(fn ($item): bool => (int) ($item->funding_snapshot_version ?? 0) !== 1)) {
            return null;
        }

        return [
            'seller_voucher_discount' => $sellerOrder->seller_voucher_discount ?? 0,
            'platform_voucher_discount' => $sellerOrder->platform_voucher_discount ?? 0,
            'seller_promotion_discount' => FinancialRules::amount($items->sum(fn ($item): int => FinancialRules::cents($item->promotion_discount_amount ?? 0))),
            'reward_points_discount' => $sellerOrder->reward_points_discount ?? 0,
        ];
    }

    private function fundingSnapshotForItem($sellerOrder, $item): ?array
    {
        if ((int) ($sellerOrder->funding_snapshot_version ?? 0) !== 1
            || (int) ($item->funding_snapshot_version ?? 0) !== 1) {
            return null;
        }

        return [
            'seller_voucher_discount' => $item->seller_voucher_discount ?? 0,
            'platform_voucher_discount' => $item->platform_voucher_discount ?? 0,
            'seller_promotion_discount' => $item->promotion_discount_amount ?? 0,
            'reward_points_discount' => $item->reward_points_discount ?? 0,
        ];
    }

    /** @return array<string, string|null> */
    private function parcelAttributes(Shipment $shipment, $sellerOrder, $item): array
    {
        $parcelAmounts = OrderItemParcelAmount::forItem($sellerOrder, $item);
        $riderPoolPercentage = FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE;
        $calculation = FinancialRules::allocation(
            [[
                'original_unit_price' => $item->original_unit_price,
                'charged_unit_price' => $item->unit_price,
                'quantity' => $item->quantity,
            ]],
            $parcelAmounts['discount_amount'],
            FinancialRules::amount($parcelAmounts['shipping_fee_cents']),
            $riderPoolPercentage,
            FinancialRules::amount($parcelAmounts['shipping_discount_cents']),
            $this->fundingSnapshotForItem($sellerOrder, $item),
            $this->commissionRateFor($sellerOrder),
            FinancialRules::amount($parcelAmounts['protection_amount_cents']),
        );
        $protectionCents = $parcelAmounts['protection_amount_cents'];

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
            'protection_amount' => FinancialRules::amount($protectionCents),
            'protection_account_status' => $protectionCents > 0
                ? 'ALLOCATED_TO_EARNINGS'
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

        $weights = [
            'PICKUP' => (int) ($rateVersion?->pickup_rider_weight ?? ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT),
            'HUB_TO_HUB' => (int) ($rateVersion?->hub_to_hub_rider_weight ?? ShippingRatePolicyService::DEFAULT_HUB_WEIGHT),
            'DELIVERY' => (int) ($rateVersion?->last_mile_rider_weight ?? ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT),
        ];
        $this->syncRiderPoolParticipants($pool, $completedRidersByRole, $representativeAssignments, $weights);
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

        $weights = [
            'PICKUP' => (int) ($rateVersion?->pickup_rider_weight ?? ShippingRatePolicyService::DEFAULT_PICKUP_WEIGHT),
            'HUB_TO_HUB' => (int) ($rateVersion?->hub_to_hub_rider_weight ?? ShippingRatePolicyService::DEFAULT_HUB_WEIGHT),
            'DELIVERY' => (int) ($rateVersion?->last_mile_rider_weight ?? ShippingRatePolicyService::DEFAULT_LAST_MILE_WEIGHT),
        ];
        $this->syncRiderPoolParticipants($pool, $completedRidersByRole, $representativeAssignments, $weights);
    }

    /**
     * Keep the saved pool balanced while refreshing unpaid allocations to the
     * current fixed shipping-fee shares. Submitted or paid payout records are
     * immutable here and are handled only by the explicit reconciliation.
     *
     * @param array{PICKUP:list<int>,HUB_TO_HUB:list<int>,DELIVERY:list<int>} $completedRidersByRole
     * @param array<string,array<int,int>> $representativeAssignments
     * @param array{PICKUP:int,HUB_TO_HUB:int,DELIVERY:int} $weights
     */
    private function syncRiderPoolParticipants(
        RiderEarningPool $pool,
        array $completedRidersByRole,
        array $representativeAssignments,
        array $weights,
    ): void {
        DB::transaction(function () use ($pool, $completedRidersByRole, $representativeAssignments, $weights): void {
            $lockedPool = RiderEarningPool::query()->whereKey($pool->id)->lockForUpdate()->firstOrFail();
            $lockedPool->loadMissing('allocation');
            $shippingCents = FinancialRules::cents($lockedPool->allocation?->shipping_fee ?? 0);
            $careCents = max(0, FinancialRules::cents($lockedPool->allocation?->protection_amount ?? 0));

            if ($lockedPool->allocation) {
                $distribution = FinancialRules::riderPoolRoleDistribution(
                    FinancialRules::shippingAllocationCents($shippingCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE)['rider_cents'],
                    FinancialRules::shippingAllocationCents($careCents, FinancialRules::DEFAULT_RIDER_POOL_PERCENTAGE)['rider_cents'],
                    $completedRidersByRole,
                    $weights,
                );
            } else {
                // Keep old pool rows operable when they predate the allocation
                // snapshot, using their saved unresolved balance and weights.
                $distribution = FinancialRules::riderLegDistribution(
                    FinancialRules::cents((string) ($lockedPool->unresolved_share_amount ?? $lockedPool->total_amount)),
                    $completedRidersByRole,
                    $weights,
                );
            }

            $targetByKey = [];
            foreach ($distribution['participants'] as $share) {
                $key = $share['participant_role'].':'.$share['rider_profile_id'];
                $targetByKey[$key] = $share;
            }

            $participants = $lockedPool->participants()->lockForUpdate()->get();
            $existingByKey = [];
            foreach ($participants as $participant) {
                $key = $participant->participant_role.':'.$participant->rider_profile_id;
                $existingByKey[$key] = $participant;
                if ($participant->status !== 'PENDING') {
                    continue;
                }
                $target = $targetByKey[$key] ?? null;
                $targetCents = (int) ($target['share_cents'] ?? 0);
                $assignmentId = $target
                    ? ($representativeAssignments[$participant->participant_role][$participant->rider_profile_id] ?? null)
                    : null;
                if (FinancialRules::cents($participant->share_amount ?? 0) !== $targetCents
                    || (int) ($participant->rider_assignment_id ?? 0) !== (int) ($assignmentId ?? 0)) {
                    $before = [
                        'share_amount' => $participant->share_amount,
                        'participant_role' => $participant->participant_role,
                        'rider_assignment_id' => $participant->rider_assignment_id,
                        'status' => $participant->status,
                    ];
                    $participant->forceFill([
                        'rider_assignment_id' => $assignmentId,
                        'share_amount' => FinancialRules::amount($targetCents),
                    ])->save();
                    FinanceAuditEvent::query()->create([
                        'event_type' => 'RIDER_SHIPPING_ALLOCATION_RECONCILED',
                        'auditable_type' => RiderEarningPoolParticipant::class,
                        'auditable_id' => $participant->id,
                        'actor_user_id' => null,
                        'before_state' => $before,
                        'after_state' => [
                            'share_amount' => FinancialRules::amount($targetCents),
                            'participant_role' => $participant->participant_role,
                            'rider_assignment_id' => $assignmentId,
                            'status' => $participant->status,
                        ],
                        'notes' => 'Unpaid Rider share recalculated using the fixed 20% pickup / 40% delivery shipping-fee split. Additional Care follows the existing saved allocation weights.',
                    ]);
                }
            }

            foreach ($targetByKey as $key => $share) {
                if (isset($existingByKey[$key])) {
                    continue;
                }
                $role = $share['participant_role'];
                $riderId = $share['rider_profile_id'];
                $lockedPool->participants()->create([
                    'rider_profile_id' => $riderId,
                    'rider_assignment_id' => $representativeAssignments[$role][$riderId] ?? null,
                    'participant_role' => $role,
                    'share_amount' => FinancialRules::amount($share['share_cents']),
                    'status' => 'PENDING',
                ]);
            }

            $allocatedCents = $lockedPool->participants()->get()->sum(
                fn (RiderEarningPoolParticipant $participant): int => FinancialRules::cents($participant->share_amount ?? 0),
            );
            $poolTotalCents = FinancialRules::cents($lockedPool->total_amount);
            $unresolvedCents = max(0, $poolTotalCents - $allocatedCents);
            $lockedPool->forceFill(['unresolved_share_amount' => FinancialRules::amount($unresolvedCents)])->save();
        }, 3);
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
                'payout_status' => $allocation->seller_payable === null ? 'REVIEW_REQUIRED' : 'ON_HOLD',
            ],
        );

        $commissionTotals = ShipmentFinancialAllocation::query()
            ->where('seller_order_id', $sellerOrder->id)
            ->get(['original_product_amount', 'platform_commission']);
        $commissionTransaction = CommissionTransaction::query()->firstOrNew(['seller_order_id' => $sellerOrder->id]);
        if (! $commissionTransaction->exists || $commissionTransaction->status === 'PENDING') {
            $commissionTransaction->fill([
                'commission_rate' => $this->commissionRateFor($sellerOrder),
                'commissionable_amount' => FinancialRules::amount($commissionTotals->sum(fn ($row): int => FinancialRules::cents($row->original_product_amount))),
                'commission_amount' => FinancialRules::amount($commissionTotals->sum(fn ($row): int => FinancialRules::cents($row->platform_commission))),
                'status' => $commissionTransaction->status ?: 'PENDING',
                'calculated_at' => $allocation->calculated_at ?: now(),
            ])->save();
        }

        $logisticsShares = $this->logisticsSharesForShipment($shipment, $allocation);
        foreach ($logisticsShares as $share) {
            $pool = LogisticsEarningPool::query()->firstOrCreate(
                [
                    'shipment_financial_allocation_id' => $allocation->id,
                    'logistics_center_id' => $share['center_id'],
                ],
                [
                    'shipment_id' => $shipment->id,
                    'total_amount' => FinancialRules::amount($share['net_share_cents']),
                    'unresolved_share_amount' => FinancialRules::amount($share['net_share_cents']),
                    'status' => 'PENDING',
                ],
            );
            $share['pool_id'] = $pool->id;
        }
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
            ['account_type' => 'RIDER', 'entry_type' => 'RIDER_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->rider_allocation, 'key' => 'rider-allocation-'.$allocation->id, 'metadata' => $this->deliveryEarningsLedgerMetadata($allocation, 'RIDER')],
            ['account_type' => 'CASH_CONTROL', 'entry_type' => 'COD_EXPECTED', 'direction' => 'DEBIT', 'amount' => $collection->expected_amount, 'key' => 'cod-expected-'.$allocation->id],
        ];
        foreach ($logisticsShares as $share) {
            $entries[] = [
                'account_type' => 'LOGISTICS',
                'entry_type' => 'LOGISTICS_ALLOCATION',
                'direction' => 'CREDIT',
                'amount' => FinancialRules::amount($share['net_share_cents']),
                'key' => $this->logisticsAllocationKey($allocation, $share['center_id'], count($logisticsShares) > 1),
                'metadata' => $this->deliveryEarningsLedgerMetadata($allocation, 'LOGISTICS') + [
                    'logistics_center_id' => $share['center_id'],
                    'route_role' => $share['role'],
                    'rider_reserve_amount' => FinancialRules::amount($share['rider_reserve_cents']),
                    'transfer_amount' => FinancialRules::amount($share['transfer_cents']),
                ],
            ];
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
                    'status' => $entry['entry_type'] === 'PRODUCT_REVENUE' && $reviewStatus === 'PENDING' ? 'ON_HOLD' : $reviewStatus,
                    'posted_at' => now(),
                    'metadata' => $entry['metadata'] ?? null,
                ],
            );
        }

        FinancialLedgerEntry::query()
            ->where('shipment_id', $shipment->id)
            ->where('entry_type', 'COD_COLLECTED')
            ->whereNull('shipment_financial_allocation_id')
            ->update(['shipment_financial_allocation_id' => $allocation->id]);

        $fullCodCents = FinancialRules::cents($collection->status === CodCollection::REMITTED_RIDER_TOPUP
            ? $collection->expected_amount
            : ($collection->collected_amount ?? $collection->expected_amount));
        $fullCodAmount = FinancialRules::amount($fullCodCents);
        $proceedsEntry = FinancialLedgerEntry::query()->firstOrCreate(
            ['idempotency_key' => 'product-proceeds-remittance-'.$allocation->id],
            [
                'shipment_financial_allocation_id' => $allocation->id,
                'shipment_id' => $shipment->id,
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'account_type' => 'LOGISTICS',
                'entry_type' => 'PRODUCT_PROCEEDS_REMITTANCE',
                'direction' => 'DEBIT',
                'amount' => $fullCodAmount,
                'status' => 'PENDING',
                'posted_at' => now(),
                'metadata' => [
                    'receipt_reference' => null,
                    'currency' => $allocation->currency ?: 'PHP',
                    'amount_expected' => $fullCodAmount,
                    'remittance_scope' => 'FULL_COD',
                    'product_amount' => $allocation->product_amount,
                    'shipping_fee' => $allocation->shipping_fee,
                    'additional_care_amount' => $allocation->protection_amount ?? '0.00',
                    'logistics_shipping_share' => $allocation->logistics_allocation,
                    'rider_shipping_share' => $allocation->rider_allocation,
                    'rider_shipping_share_included' => true,
                    'amount_submitted' => null,
                    'amount_received' => null,
                    'discrepancy_amount' => null,
                    'sender' => ['role' => 'LOGISTICS', 'logistics_center_id' => $centerId],
                    'recipient' => ['role' => 'ADMIN'],
                ],
            ],
        );

        $proceedsMetadata = (array) $proceedsEntry->metadata;
        if ($proceedsEntry->status === 'PENDING'
            && ($proceedsMetadata['amount_submitted'] ?? null) === null
            && (FinancialRules::cents($proceedsEntry->amount) !== $fullCodCents
                || ($proceedsMetadata['remittance_scope'] ?? null) !== 'FULL_COD'
                || ($proceedsMetadata['rider_shipping_share_included'] ?? false) !== true)) {
            $before = ['amount' => $proceedsEntry->amount, 'metadata' => $proceedsMetadata];
            $proceedsMetadata = array_merge($proceedsMetadata, [
                'amount_expected' => $fullCodAmount,
                'remittance_scope' => 'FULL_COD',
                'product_amount' => $allocation->product_amount,
                'shipping_fee' => $allocation->shipping_fee,
                'additional_care_amount' => $allocation->protection_amount ?? '0.00',
                'logistics_shipping_share' => $allocation->logistics_allocation,
                'rider_shipping_share' => $allocation->rider_allocation,
                'rider_shipping_share_included' => true,
            ]);
            $proceedsEntry->update(['amount' => $fullCodAmount, 'metadata' => $proceedsMetadata]);
            FinanceAuditEvent::query()->create([
                'event_type' => 'PENDING_COD_REMITTANCE_EXPECTATION_UPDATED',
                'auditable_type' => FinancialLedgerEntry::class,
                'auditable_id' => $proceedsEntry->id,
                'before_state' => $before,
                'after_state' => ['amount' => $fullCodAmount, 'metadata' => $proceedsMetadata],
                'notes' => 'Updated an unsubmitted Logistics-to-Admin remittance to the full collected COD amount; no delivery earnings were deducted.',
            ]);
        }

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
                'payout_status' => $allocation->seller_payable === null ? 'REVIEW_REQUIRED' : 'ON_HOLD',
            ],
        );
        CommissionTransaction::query()->firstOrCreate(
            ['seller_order_id' => $sellerOrder->id],
            [
                'commission_rate' => $this->commissionRateFor($sellerOrder),
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
            ['account_type' => 'LOGISTICS', 'entry_type' => 'LOGISTICS_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->logistics_allocation, 'key' => 'logistics-allocation-'.$allocation->id, 'metadata' => $this->deliveryEarningsLedgerMetadata($allocation, 'LOGISTICS')],
            ['account_type' => 'RIDER', 'entry_type' => 'RIDER_ALLOCATION', 'direction' => 'CREDIT', 'amount' => $allocation->rider_allocation, 'key' => 'rider-allocation-'.$allocation->id, 'metadata' => $this->deliveryEarningsLedgerMetadata($allocation, 'RIDER')],
            ['account_type' => 'CASH_CONTROL', 'entry_type' => 'COD_EXPECTED', 'direction' => 'DEBIT', 'amount' => $sellerOrder->grand_total, 'key' => 'cod-expected-'.$allocation->id],
        ];
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
                    'status' => $entry['entry_type'] === 'PRODUCT_REVENUE' ? 'ON_HOLD' : 'PENDING',
                    'posted_at' => now(),
                    'metadata' => $entry['metadata'] ?? null,
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

    /** @return array{earning_recipient:string,base_shipping_fee:string,additional_care_amount:string,combined_delivery_earnings:string} */
    private function deliveryEarningsLedgerMetadata(ShipmentFinancialAllocation $allocation, string $recipient): array
    {
        $baseShippingCents = FinancialRules::cents($allocation->shipping_fee);
        $additionalCareCents = FinancialRules::cents($allocation->protection_amount ?? 0);

        return [
            'earning_recipient' => $recipient,
            'base_shipping_fee' => FinancialRules::amount($baseShippingCents),
            'additional_care_amount' => FinancialRules::amount($additionalCareCents),
            'combined_delivery_earnings' => FinancialRules::amount(FinancialRules::deliveryEarningsCents(
                $allocation->shipping_fee,
                $allocation->protection_amount ?? 0,
            )),
        ];
    }

    /** @return list<array{center_id:int,role:string,net_share_cents:int,transfer_cents:int,rider_reserve_cents:int}> */
    private function logisticsSharesForShipment(Shipment $shipment, ShipmentFinancialAllocation $allocation): array
    {
        $destinationId = (int) ($shipment->destination_logistics_center_id ?: $shipment->logistics_center_id ?: 0);
        if (! $destinationId) {
            throw new RuntimeException('A logistics center is required before creating a parcel earning pool.');
        }

        if (! $this->isInterHubShipment($shipment)) {
            $breakdown = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $destinationId);

            return [[
                'center_id' => $destinationId,
                'role' => $breakdown['role'],
                'net_share_cents' => $breakdown['net_share_cents'],
                'transfer_cents' => $breakdown['transfer_cents'],
                'rider_reserve_cents' => $breakdown['rider_reserve_cents'],
            ]];
        }

        if (! $this->schema->multiHubLogisticsPoolsReady()) {
            throw new RuntimeException('Inter-Hub wallet allocation is unavailable until its additive finance migration is installed.');
        }

        $originId = (int) $shipment->origin_logistics_center_id;
        $origin = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $originId);
        $destination = DeliveryEarningsBreakdown::forLogisticsCenter($shipment, $allocation, $destinationId);

        return [
            ['center_id' => $originId, 'role' => $origin['role'], 'net_share_cents' => $origin['net_share_cents'], 'transfer_cents' => $origin['transfer_cents'], 'rider_reserve_cents' => $origin['rider_reserve_cents']],
            ['center_id' => $destinationId, 'role' => $destination['role'], 'net_share_cents' => $destination['net_share_cents'], 'transfer_cents' => $destination['transfer_cents'], 'rider_reserve_cents' => $destination['rider_reserve_cents']],
        ];
    }

    private function isInterHubShipment(Shipment $shipment): bool
    {
        return filled($shipment->origin_logistics_center_id)
            && filled($shipment->destination_logistics_center_id)
            && (int) $shipment->origin_logistics_center_id !== (int) $shipment->destination_logistics_center_id;
    }

    private function logisticsAllocationKey(ShipmentFinancialAllocation $allocation, int $centerId, bool $multiCenter): string
    {
        return $multiCenter
            ? 'logistics-allocation-'.$allocation->id.'-'.$centerId
            : 'logistics-allocation-'.$allocation->id;
    }

    private function commissionRateFor($sellerOrder): float
    {
        if ((int) ($sellerOrder->funding_snapshot_version ?? 0) === 1
            && $sellerOrder->commission_rate_snapshot !== null) {
            return min(1.0, max(0.0, (float) $sellerOrder->commission_rate_snapshot));
        }

        return PlatformSetting::commissionRate();
    }
}
