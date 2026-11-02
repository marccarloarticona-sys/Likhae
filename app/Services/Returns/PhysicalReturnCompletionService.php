<?php

namespace App\Services\Returns;

use App\Models\Logistics\ShipmentReturnWorkflow;
use App\Models\Returns\ReturnCustodyEvent;
use App\Models\Returns\ReturnInspection;
use App\Models\Seller\SellerReturnRefundRequest;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PhysicalReturnCompletionService
{
    public function isRequired(SellerReturnRefundRequest $sellerRequest): bool
    {
        $sellerRequest->loadMissing('request');

        return trim((string) $sellerRequest->request?->solution) === 'Return & Refund';
    }

    public function assertComplete(SellerReturnRefundRequest $sellerRequest): void
    {
        if (! $this->isRequired($sellerRequest)) {
            return;
        }
        if (! Schema::hasTable('shipment_return_workflows')
            || ! Schema::hasColumn('shipment_return_workflows', 'seller_return_refund_request_id')
            || ! Schema::hasTable('return_custody_events')
            || ! Schema::hasTable('return_inspections')) {
            throw new RuntimeException('Seller return receipt and inspection are unavailable until the buyer return waybill schema is installed.');
        }

        $hasSellerReceipt = ShipmentReturnWorkflow::query()
            ->where('seller_return_refund_request_id', $sellerRequest->id)
            ->where('status', ShipmentReturnWorkflow::SELLER_RECEIVED)
            ->exists()
            && ReturnCustodyEvent::query()
                ->where('seller_return_refund_request_id', $sellerRequest->id)
                ->where('event_type', 'SELLER_RECEIVED')
                ->exists();
        $itemIds = $sellerRequest->items()->pluck('id');
        $inspectedCount = $itemIds->isEmpty() ? 0 : ReturnInspection::query()
            ->where('seller_return_refund_request_id', $sellerRequest->id)
            ->where('inspection_status', 'COMPLETED')
            ->whereIn('return_refund_request_item_id', $itemIds)
            ->distinct()
            ->count('return_refund_request_item_id');
        if (! $hasSellerReceipt || $itemIds->isEmpty() || $inspectedCount !== $itemIds->count()) {
            throw new RuntimeException('Seller receipt and inspection of every returned item are required before the refund can be paid.');
        }
    }
}
