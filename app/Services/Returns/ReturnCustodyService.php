<?php

namespace App\Services\Returns;

use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Buyer\OrderItem;
use App\Models\Returns\ReturnInspection;
use App\Models\Returns\ReturnCustodyEvent;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ReturnCustodyService
{
    public function recordCustodyEvent(SellerReturnRefundRequest $sellerRequest, User $actor, string $eventType, array $data = []): ReturnCustodyEvent
    {
        $this->assertReady('return_custody_events');
        $sellerRequest->loadMissing('request');
        $eventType = strtoupper(trim($eventType));
        if (! in_array($eventType, [
            'BUYER_HANDOFF',
            'LOCAL_RIDER_RECEIVED',
            'LOGISTICS_RECEIVED',
            'TRANSFER_RELEASED',
            'TRANSFER_RECEIVED',
            'SELLER_RECEIVED',
            'INSPECTION_COMPLETED',
            'BUYER_CASH_RECEIPT',
        ], true)) {
            throw new RuntimeException('The return custody event type is not an approved workflow event.');
        }

        $events = ReturnCustodyEvent::query()->where('seller_return_refund_request_id', $sellerRequest->id);
        if ($eventType === 'SELLER_RECEIVED' && ! (clone $events)->whereIn('event_type', ['LOGISTICS_RECEIVED', 'TRANSFER_RECEIVED'])->exists()) {
            throw new RuntimeException('Seller receipt is blocked until the return has a recorded logistics or transfer receipt.');
        }
        if ($eventType === 'INSPECTION_COMPLETED' && ! (clone $events)->where('event_type', 'SELLER_RECEIVED')->exists()) {
            throw new RuntimeException('Inspection completion is blocked until seller/facility receipt is recorded.');
        }

        return ReturnCustodyEvent::create([
            'return_refund_request_id' => $sellerRequest->return_refund_request_id,
            'seller_return_refund_request_id' => $sellerRequest->id,
            'event_type' => $eventType,
            'from_party' => $data['from_party'] ?? null,
            'to_party' => $data['to_party'] ?? null,
            'shipment_id' => $data['shipment_id'] ?? null,
            'rider_assignment_id' => $data['rider_assignment_id'] ?? null,
            'transfer_reference' => $data['transfer_reference'] ?? null,
            'location_label' => $data['location_label'] ?? null,
            'actor_user_id' => $actor->id,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    public function recordInspection(SellerReturnRefundRequest $sellerRequest, ReturnRefundRequestItem $item, User $inspector, array $data): ReturnInspection
    {
        $this->assertReady('return_inspections');
        $this->assertDisposition((string) ($data['inventory_disposition'] ?? ''));
        return DB::transaction(function () use ($sellerRequest, $item, $inspector, $data): ReturnInspection {
            $lockedItem = ReturnRefundRequestItem::query()
                ->whereKey($item->id)
                ->where('seller_return_refund_request_id', $sellerRequest->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedItem) {
                throw new RuntimeException('The inspected item is not part of this seller return request.');
            }
            if (! ReturnCustodyEvent::query()
                ->where('seller_return_refund_request_id', $sellerRequest->id)
                ->where('event_type', 'SELLER_RECEIVED')
                ->exists()) {
                throw new RuntimeException('Inspection is blocked until seller/facility receipt is recorded.');
            }

            $inspection = ReturnInspection::query()->create([
                'seller_return_refund_request_id' => $sellerRequest->id,
                'return_refund_request_item_id' => $lockedItem->id,
                'inspector_user_id' => $inspector->id,
                'inspection_status' => 'COMPLETED',
                'received_condition' => $data['received_condition'] ?? null,
                'completeness_status' => $data['completeness_status'] ?? null,
                'damage_notes' => $data['damage_notes'] ?? null,
                'missing_accessories' => $data['missing_accessories'] ?? null,
                'identifier_result' => $data['identifier_result'] ?? null,
                'evidence_metadata' => $data['evidence_metadata'] ?? null,
                'inventory_disposition' => $data['inventory_disposition'],
                'received_at' => $data['received_at'] ?? now(),
                'inspected_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            if ($data['inventory_disposition'] !== ReturnInspection::RESTOCKABLE
                || ReturnInspection::query()
                    ->where('return_refund_request_item_id', $lockedItem->id)
                    ->whereNotNull('restocked_at')
                    ->exists()) {
                return $inspection;
            }

            $orderItem = OrderItem::query()->whereKey($lockedItem->order_item_id)->lockForUpdate()->first();
            if (! $orderItem || ! $orderItem->product_variant_id || (int) $lockedItem->quantity > (int) $orderItem->quantity) {
                throw new RuntimeException('This returned item is not linked to a restockable product variant.');
            }
            $variant = ProductVariant::query()->whereKey($orderItem->product_variant_id)->lockForUpdate()->first();
            if (! $variant) {
                throw new RuntimeException('The returned product variant is unavailable, so inventory cannot be restored safely.');
            }

            $variant->increment('stock', (int) $lockedItem->quantity);
            $campaignMatches = false;
            if (Schema::hasColumn('order_items', 'flash_sale_campaign_key')
                && Schema::hasColumn('products', 'flash_sale_campaign_key')) {
                $product = Product::query()->whereKey($variant->product_id)->lockForUpdate()->first();
                $campaignMatches = $product
                    && filled($orderItem->flash_sale_campaign_key)
                    && hash_equals((string) $product->flash_sale_campaign_key, (string) $orderItem->flash_sale_campaign_key);
            }
            if ($campaignMatches
                && Schema::hasColumn('order_items', 'flash_sale_quantity')
                && Schema::hasColumn('product_variants', 'flash_sale_sold')) {
                $saleUnits = min((int) $lockedItem->quantity, (int) $orderItem->flash_sale_quantity);
                if ($saleUnits > 0) {
                    $variant->decrement('flash_sale_sold', min($saleUnits, (int) $variant->flash_sale_sold));
                }
            }

            $inspection->forceFill([
                'restocked_at' => now(),
                'restocked_by_user_id' => $inspector->id,
            ])->save();

            return $inspection->refresh();
        }, attempts: 3);
    }

    private function assertReady(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException('Return custody and inspection tables are pending additive migration approval.');
        }
    }

    private function assertDisposition(string $disposition): void
    {
        if (! in_array($disposition, [ReturnInspection::RESTOCKABLE, ReturnInspection::DAMAGED, ReturnInspection::QUARANTINED, ReturnInspection::NON_RESELLABLE], true)) {
            throw new RuntimeException('Choose an approved inventory disposition before closing inspection.');
        }
    }
}
