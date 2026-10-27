@props(['status', 'next', 'actor', 'shipmentId' => null, 'returnedLabel' => null])

<section class="lk-status-guidance" aria-label="Current status and next step" @if($shipmentId) data-shipment-guidance-id="{{ $shipmentId }}" @endif @if($returnedLabel) data-shipment-returned-label="{{ $returnedLabel }}" @endif>
    <div class="lk-status-guidance__item lk-status-guidance__current">
        <span>Current status</span>
        <strong data-guidance-status>{{ $status }}</strong>
    </div>
    <div class="lk-status-guidance__item">
        <span>Next step</span>
        <p data-guidance-next>{{ $next }}</p>
    </div>
    <div class="lk-status-guidance__item">
        <span>Next action by</span>
        <p data-guidance-actor>{{ $actor }}</p>
    </div>
</section>
