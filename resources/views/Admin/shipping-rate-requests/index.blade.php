@extends('layouts.admin.app')
@section('title', 'Shipping Rate Requests')
@section('subtitle', 'Review shipping fee and commission split proposals.')
@section('active', 'shipping-rate-requests')
@section('content')
<div style="display:grid;gap:16px">
    @if(session('status'))<div role="status" class="ad-card ad-card-body" style="color:#245434">{{ session('status') }}</div>@endif
    @if($schemaReady && $policy['initialized'])
        <div class="ad-card ad-card-body">
            <span class="ad-overline">Shipping commission split</span>
            <h2>Set Logistics Share</h2>
            <p>Applies to shipping fees only. The Rider receives the remainder. Existing orders keep their saved rate version.</p>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.commission-split') }}" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap" onsubmit="return confirm('Activate this shipping split for new orders?')">
                @csrf
                <label class="ad-field"><span>Logistics share (%)</span><input type="number" name="logistics_percentage" min="0" max="100" step="0.0001" value="{{ old('logistics_percentage', $policy['logistics_percentage']) }}" required></label>
                <div style="padding:10px 0;color:#4b5960">Rider receives {{ number_format(100 - (float) old('logistics_percentage', $policy['logistics_percentage']), 2) }}%</div>
                <button class="ad-btn ad-btn-primary">Save Shipping Split</button>
            </form>
            @error('logistics_percentage')<p role="alert" style="color:#a52920">{{ $message }}</p>@enderror
        </div>
    @endif

    <div class="ad-card ad-card-body">
        <div class="ad-page-head"><div><span class="ad-overline">GLOBAL rate policy</span><h2>Current Approved Shipping Fee</h2><p>New checkout transactions use this global fee. Existing SellerOrder snapshots remain unchanged.</p></div></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
            <div><small>{{ $policy['initialized'] ? 'Active approved version' : 'Bootstrap fallback' }}</small><h2>PHP {{ number_format((float) $policy['shipping_fee'], 2) }}</h2></div>
            <div><small>Rider Pool</small><h2>{{ number_format((float) $policy['rider_pool_percentage'], 2) }}%</h2></div>
            <div><small>Logistics</small><h2>{{ number_format((float) $policy['logistics_percentage'], 2) }}%</h2></div>
            <div><small>Effective since</small><h3>{{ $currentVersion?->effective_from?->format('M j, Y g:i A') ?? 'Not initialized' }}</h3></div>
        </div>
        @if(! $schemaReady)
            <p style="margin:16px 0 0"><strong>Rate-governance migration is not installed.</strong> Checkout continues to use the PHP 50 config fallback.</p>
        @elseif(! $policy['initialized'])
            <p style="margin:16px 0 8px">No production rate row was inserted by the migration. Use this explicit Admin action to establish the current PHP 50 global policy and its 60/40 allocation.</p>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.initialize') }}" onsubmit="return confirm('Initialize the current GLOBAL rate at PHP {{ number_format((float) $policy['shipping_fee'], 2) }} with the 60/40 allocation policy? Checkout will continue at the same fee.')">@csrf<button class="ad-btn ad-btn-primary">Initialize Current Global Rate</button></form>
        @endif
    </div>

    <div class="ad-card ad-card-body">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap"><div><span class="ad-overline">Logistics proposals</span><h2>Shipping Rate Requests</h2></div>
            <nav style="display:flex;gap:8px;flex-wrap:wrap">@foreach(['PENDING','APPROVED','REJECTED','CANCELLED','ALL'] as $filter)<a class="ad-btn {{ $status === $filter ? 'ad-btn-primary' : 'ad-btn-secondary' }}" href="{{ route('admin.shipping-rate-requests', ['status' => $filter]) }}">{{ str($filter)->headline() }}</a>@endforeach</nav>
        </div>
        <div class="ad-table-wrap" style="margin-top:14px"><table class="ad-table"><thead><tr><th>Logistics</th><th>Current</th><th>Requested</th><th>Change</th><th>Reason</th><th>Requested At</th><th>Status</th><th>Action</th></tr></thead><tbody>
        @forelse($requests as $rateRequest)
            <tr><td>{{ $rateRequest->requester?->logisticsCenter?->business_name ?? $rateRequest->requester?->name ?? 'Logistics account' }}</td><td>@if($rateRequest->requested_rider_pool_percentage !== null)Rider {{ number_format((float) $rateRequest->current_rider_pool_percentage, 2) }}% / Logistics {{ number_format(100 - (float) $rateRequest->current_rider_pool_percentage, 2) }}%@else PHP {{ number_format((float) $rateRequest->current_shipping_fee, 2) }}@endif</td><td>@if($rateRequest->requested_rider_pool_percentage !== null)Rider {{ number_format((float) $rateRequest->requested_rider_pool_percentage, 2) }}% / Logistics {{ number_format(100 - (float) $rateRequest->requested_rider_pool_percentage, 2) }}%@else PHP {{ number_format((float) $rateRequest->requested_shipping_fee, 2) }}@endif</td><td>@if($rateRequest->requested_rider_pool_percentage !== null)Rider share change @else{{ $rateRequest->change_type }} · PHP {{ number_format(abs((float) $rateRequest->change_amount), 2) }} ({{ number_format((float) $rateRequest->change_percentage, 2) }}%)@endif</td><td>{{ \App\Models\Finance\ShippingRateChangeRequest::REASONS[$rateRequest->reason_code] ?? $rateRequest->reason_code }}</td><td>{{ $rateRequest->created_at?->format('M j, Y g:i A') }}</td><td>{{ str($rateRequest->status)->headline() }}</td><td><a class="ad-btn ad-btn-secondary" href="{{ route('admin.shipping-rate-requests.show', $rateRequest) }}">Review</a></td></tr>
        @empty
            <tr><td colspan="8">No requests in this view.</td></tr>
        @endforelse
        </tbody></table></div>
        @if(method_exists($requests, 'links'))<div style="margin-top:14px">{{ $requests->links() }}</div>@endif
    </div>
</div>
@endsection
