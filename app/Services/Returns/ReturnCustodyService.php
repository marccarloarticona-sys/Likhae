<?php

namespace App\Services\Returns;

use App\Models\Buyer\ReturnRefundRequestItem;
use App\Models\Returns\ReturnCustodyEvent;
use App\Models\Returns\ReturnInspection;
use App\Models\Seller\SellerReturnRefundRequest;
use App\Models\User;
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
        if ((int) $item->seller_return_refund_request_id !== (int) $sellerRequest->id) {
            throw new RuntimeException('The inspected item is not part of this seller return request.');
        }
        if (! ReturnCustodyEvent::query()
            ->where('seller_return_refund_request_id', $sellerRequest->id)
            ->where('event_type', 'SELLER_RECEIVED')
            ->exists()) {
            throw new RuntimeException('Inspection is blocked until seller/facility receipt is recorded.');
        }

        return ReturnInspection::create([
            'seller_return_refund_request_id' => $sellerRequest->id,
            'return_refund_request_item_id' => $item->id,
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
