<?php

namespace App\Services\Finance;

use App\Models\Finance\CodCollection;
use App\Models\Finance\FinanceAuditEvent;
use App\Models\Finance\FinancialLedgerEntry;
use App\Models\Logistics\Shipment;
use App\Models\Rider\RiderAssignment;
use App\Models\User;
use App\Support\FinancialRules;
use App\Support\OrderItemParcelAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class CodCollectionService
{
    public function __construct(private readonly FinanceSchemaReadiness $schema) {}

    public function initializeForShipment(Shipment $shipment): ?CodCollection
    {
        if (! $this->schema->step6Ready()) {
            return null;
        }

        $shipment->loadMissing('sellerOrder.order', 'sellerOrder.items', 'orderItem');
        $sellerOrder = $shipment->sellerOrder;
        if (! $sellerOrder?->order) {
            throw new RuntimeException('A COD collection requires an order and seller-order reference.');
        }

        $expectedAmount = $shipment->orderItem
            ? OrderItemParcelAmount::forItem($sellerOrder, $shipment->orderItem)['total']
            : (float) $sellerOrder->grand_total;

        $collection = CodCollection::query()->firstOrCreate(
            ['shipment_id' => $shipment->id],
            [
                'seller_order_id' => $sellerOrder->id,
                'order_id' => $sellerOrder->order_id,
                'logistics_center_id' => $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id,
                'expected_amount' => number_format($expectedAmount, 2, '.', ''),
                'status' => CodCollection::PENDING,
            ],
        );

        if ($collection->status === CodCollection::PENDING
            && FinancialRules::cents($collection->expected_amount) !== FinancialRules::cents($expectedAmount)) {
            $collection->update(['expected_amount' => number_format($expectedAmount, 2, '.', '')]);
        }

        return $collection->refresh();
    }

    public function attachDeliveryRider(Shipment $shipment, RiderAssignment $assignment): ?CodCollection
    {
        if (! $this->schema->step6Ready()) {
            return null;
        }
        if ($assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY) {
            throw new RuntimeException('Only the final-mile delivery rider may collect COD.');
        }
        $assignment->loadMissing('riderProfile');
        $shipment->loadMissing('sellerOrder.order');

        return DB::transaction(function () use ($shipment, $assignment): ?CodCollection {
            $collection = $this->initializeForShipment($shipment);
            if (! $collection) {
                return null;
            }
            $collection = CodCollection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $riderCenterId = $assignment->riderProfile?->logistics_center_id;
            $destinationCenterId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
            if ($destinationCenterId && (int) $riderCenterId !== (int) $destinationCenterId) {
                throw new RuntimeException('The final-mile rider must belong to the destination logistics center.');
            }
            if ($collection->rider_profile_id && (int) $collection->rider_profile_id !== (int) $assignment->rider_profile_id && $collection->status !== CodCollection::PENDING) {
                throw new RuntimeException('This shipment already has an assigned COD-collecting rider.');
            }
            $collection->update([
                'rider_profile_id' => $assignment->rider_profile_id,
                'logistics_center_id' => $destinationCenterId ?: $riderCenterId,
            ]);

            return $collection->refresh();
        });
    }

    /** @param array<string, mixed> $payload */
    public function recordSuccessfulCollection(Shipment $shipment, RiderAssignment $assignment, User $actor, array $payload = []): ?CodCollection
    {
        if (! $this->schema->step6Ready()) {
            return null;
        }
        if ($assignment->assignment_type !== RiderAssignment::TYPE_DELIVERY) {
            throw new RuntimeException('Only the assigned final-mile delivery rider may collect COD.');
        }
        if ($shipment->current_status !== 'OUT_FOR_DELIVERY') {
            throw new RuntimeException('COD may only be collected during a successful final-mile delivery.');
        }

        return DB::transaction(function () use ($shipment, $assignment, $actor, $payload): ?CodCollection {
            $collection = $this->initializeForShipment($shipment);
            if (! $collection) {
                return null;
            }
            $collection = CodCollection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            if (! in_array($collection->status, [CodCollection::PENDING], true)) {
                throw new RuntimeException('COD collection has already been recorded and cannot be duplicated.');
            }
            if ((int) $collection->rider_profile_id !== (int) $assignment->rider_profile_id) {
                throw new RuntimeException('Only the assigned final-mile rider may record this COD collection.');
            }
            $assignment->loadMissing('riderProfile');
            $destinationCenterId = $shipment->destination_logistics_center_id ?: $shipment->logistics_center_id;
            if ($destinationCenterId && (int) $assignment->riderProfile?->logistics_center_id !== (int) $destinationCenterId) {
                throw new RuntimeException('The rider does not own the destination logistics center for this collection.');
            }

            // The expected amount is always read from the server-side snapshot.
            $expectedCents = FinancialRules::cents($collection->expected_amount);
            $actualCents = array_key_exists('collected_amount', $payload) && $payload['collected_amount'] !== null
                ? FinancialRules::cents($payload['collected_amount'])
                : $expectedCents;
            $status = $actualCents === $expectedCents ? CodCollection::COLLECTED : CodCollection::DISCREPANCY;
            $reference = trim((string) ($payload['collection_reference'] ?? ''));
            if ($reference === '') {
                $reference = $this->uniqueReference('COD');
            }

            $before = $collection->only(['status', 'expected_amount', 'collected_amount', 'rider_profile_id']);
            $collection->update([
                'collected_amount' => FinancialRules::amount($actualCents),
                'status' => $status,
                'collection_reference' => $reference,
                'collected_at' => now(),
                'recorded_by_user_id' => $actor->id,
                'notes' => $payload['notes'] ?? null,
            ]);
            if ($this->schema->step7Ready()) {
                $allocation = $shipment->financialAllocation()->first();
                FinancialLedgerEntry::query()->firstOrCreate(
                    ['idempotency_key' => 'cod-collected-'.$collection->id],
                    [
                        'shipment_financial_allocation_id' => $allocation?->id,
                        'shipment_id' => $shipment->id,
                        'seller_order_id' => $collection->seller_order_id,
                        'order_id' => $collection->order_id,
                        'account_type' => 'CASH_CONTROL',
                        'entry_type' => 'COD_COLLECTED',
                        'direction' => 'CREDIT',
                        'amount' => FinancialRules::amount($actualCents),
                        'status' => $status === CodCollection::COLLECTED ? 'POSTED' : 'REVIEW_REQUIRED',
                        'actor_user_id' => $actor->id,
                        'posted_at' => now(),
                        'metadata' => ['collection_status' => $status],
                    ],
                );
            }
            FinanceAuditEvent::query()->create([
                'event_type' => 'COD_COLLECTED',
                'auditable_type' => CodCollection::class,
                'auditable_id' => $collection->id,
                'actor_user_id' => $actor->id,
                'before_state' => $before,
                'after_state' => $collection->only(['status', 'expected_amount', 'collected_amount', 'collection_reference']),
                'notes' => $status === CodCollection::DISCREPANCY ? 'Collection amount differs from server-calculated expected COD.' : 'Final-mile COD collection recorded.',
            ]);

            return $collection->refresh();
        });
    }

    private function uniqueReference(string $prefix): string
    {
        do {
            $reference = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (CodCollection::query()->where('collection_reference', $reference)->exists());

        return $reference;
    }
}
