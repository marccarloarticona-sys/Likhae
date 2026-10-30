@php
    $inTransitTransfer = $shipment?->transferLegs?->last(fn ($leg) => $leg->status === 'IN_TRANSIT');
    $returnWorkflow = $shipment && $shipment->relationLoaded('returnWorkflow') ? $shipment->returnWorkflow : null;
    $returnWorkflowOpen = $returnWorkflow
        && in_array($returnWorkflow->status, \App\Models\Logistics\ShipmentReturnWorkflow::OPEN_STATUSES, true);
    $sellerHasReceivedReturn = $returnWorkflow?->status === \App\Models\Logistics\ShipmentReturnWorkflow::SELLER_RECEIVED;
    $returnDisposition = $returnWorkflow?->return_disposition
        ?: \App\Models\Logistics\ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN;
    $centerName = trim((string) ($inTransitTransfer?->originLogisticsCenter?->business_name
        ?: $shipment?->logisticsCenter?->business_name
        ?: ''));
    $centerLabel = $centerName === ''
        ? 'the sorting centre'
        : $centerName.(preg_match('/(sorting\s+(centre|center)|hub)/i', $centerName) ? '' : ' sorting centre');
    $transferDestination = trim((string) ($inTransitTransfer?->destinationLogisticsCenter?->business_name ?: 'the next hub'));
    $trackingUpdate = match (true) {
        ! $shipment => null,
        $shipment->current_status === 'CANCELLED' => 'Parcel was cancelled',
        $shipment->current_status === 'RETURNED' => 'Parcel has been returned to the seller',
        $shipment->current_status === 'COMPLETED' => 'Buyer confirmed receipt',
        $shipment->current_status === 'DELIVERED' => 'Parcel has been delivered',
        (bool) $inTransitTransfer && filled($inTransitTransfer->arrival_scanned_at) => 'Parcel has arrived at '.$transferDestination.'. Hub receipt is being verified.',
        (bool) $inTransitTransfer => 'Parcel has departed from '.$centerLabel.' and is on the way to '.$transferDestination,
        in_array($shipment->current_status, ['AT_SORTING_CENTER', 'SORTED'], true) => 'Parcel has arrived at '.$centerLabel,
        in_array($shipment->current_status, ['OUT_FOR_DELIVERY', 'ASSIGNED_TO_RIDER'], true) => 'Parcel is out for delivery',
        $shipment->current_status === 'PICKED_UP' => 'Parcel has been picked up from seller',
        $shipment->current_status === 'READY_FOR_PICKUP' => 'Ready to ship',
        $shipment->current_status === 'PICKUP_ASSIGNED' => 'Pickup rider assigned',
        $shipment->current_status === 'DELIVERY_FAILED' && $returnWorkflowOpen && $returnDisposition === \App\Models\Logistics\ShipmentReturnWorkflow::DISPOSITION_SELLER_RETURN => 'Return to seller is in progress',
        $shipment->current_status === 'DELIVERY_FAILED' && $returnWorkflowOpen && $returnDisposition === \App\Models\Logistics\ShipmentReturnWorkflow::DISPOSITION_REDELIVERY => 'Redelivery is being arranged',
        $shipment->current_status === 'DELIVERY_FAILED' && $sellerHasReceivedReturn => 'Seller has received the returned parcel',
        $shipment->current_status === 'DELIVERY_FAILED' => 'Delivery attempt was unsuccessful',
        $shipment->current_status === 'PLACED' => 'Waiting for seller to accept your order',
        default => 'Seller is preparing your parcel',
    };
@endphp

<div class="ml-auto flex min-w-0 flex-wrap items-center justify-end gap-2 text-xs">
    @if($shipment)
        <span class="inline-flex min-w-0 items-center gap-1.5 text-stone-600" data-shipping-update @if(! $trackingUpdate) hidden @endif>
            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h11.25v10.5H3zM14.25 10.5h4.1l2.65 2.7v4.05h-6.75zM6.75 20.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3ZM18 20.25a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z" />
            </svg>
            <span class="lk-shipment-status-text" data-shipment-status-id="{{ $shipment->id }}" @if($fullStatus ?? false) style="max-width:none;overflow:visible;text-overflow:clip;white-space:normal;overflow-wrap:anywhere" @endif>{{ $trackingUpdate }}</span>
            <span class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded-full border border-current text-[9px] font-bold leading-none" title="Tracking updates are based on the latest parcel scan." aria-label="Tracking update information">?</span>
            <span class="ml-0.5 h-4 w-px bg-stone-300" aria-hidden="true"></span>
        </span>
    @endif
    @if($showOrderStatus ?? true)
        <span class="shrink-0 font-semibold uppercase tracking-wide text-[#7a2a22]" data-order-status-id="{{ $orderId }}">{{ $status }}</span>
    @endif
</div>
