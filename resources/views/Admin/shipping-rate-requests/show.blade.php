@extends('layouts.admin')
@section('title', 'Review Shipping Rate Request')
@section('subtitle', 'Approve the exact proposal or reject it with a reason.')
@section('active', 'shipping-rate-requests')
@section('content')
<div style="display:grid;gap:16px;max-width:1100px">
    @if(session('status'))<div role="status" class="ad-card ad-card-body" style="color:#245434">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" class="ad-card ad-card-body" style="color:#8b3024">{{ $errors->first() }}</div>@endif
    <div class="ad-card ad-card-body">
        <div class="ad-page-head"><div><span class="ad-overline">{{ str($shippingRateRequest->status)->headline() }}</span><h2>{{ $shippingRateRequest->requester?->logisticsCenter?->business_name ?? $shippingRateRequest->requester?->name ?? 'Logistics account' }}</h2><p>Submitted {{ $shippingRateRequest->created_at?->format('M j, Y g:i A') }}</p></div><a class="ad-btn ad-btn-secondary" href="{{ route('admin.shipping-rate-requests') }}">Back to requests</a></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
            <div><small>Current Approved Rate</small><h3>PHP {{ number_format((float) $policy['shipping_fee'], 2) }}</h3></div>
            <div><small>Request Base</small><h3>PHP {{ number_format((float) $shippingRateRequest->current_shipping_fee, 2) }}</h3></div>
            <div><small>Requested Rate</small><h3>PHP {{ number_format((float) $shippingRateRequest->requested_shipping_fee, 2) }}</h3></div>
            <div><small>Change</small><h3>{{ $shippingRateRequest->change_type }} · PHP {{ number_format(abs((float) $shippingRateRequest->change_amount), 2) }}</h3><small>{{ number_format((float) $shippingRateRequest->change_percentage, 2) }}%</small></div>
            <div><small>Current Allocation</small><h3>Rider {{ number_format((float) $policy['rider_pool_percentage'], 2) }}% · Logistics {{ number_format((float) $policy['logistics_percentage'], 2) }}%</h3></div>
        </div>
        @if($isStale)
            <div role="alert" style="margin-top:14px;padding:14px;border:1px solid #d99b2b;border-radius:10px;background:#fff8e8;color:#684b13">
                <strong>Request baseline is stale.</strong>
                <p style="margin:6px 0 0">This request was submitted when the GLOBAL rate was PHP {{ number_format((float) $shippingRateRequest->current_shipping_fee, 2) }}. The current active GLOBAL rate is PHP {{ number_format((float) $policy['shipping_fee'], 2) }}. The requested target remains PHP {{ number_format((float) $shippingRateRequest->requested_shipping_fee, 2) }}; the stored request baseline will not be rewritten.</p>
                <p style="margin:6px 0 0">Admin may approve that exact target after confirming the changed baseline. The new rate will replace the current active version, and the original request baseline and predecessor version will remain in the audit history.</p>
            </div>
        @endif
        <div style="margin-top:16px"><strong>Reason</strong><p>{{ \App\Models\Finance\ShippingRateChangeRequest::REASONS[$shippingRateRequest->reason_code] ?? $shippingRateRequest->reason_code }}{{ $shippingRateRequest->reason_details ? ': '.$shippingRateRequest->reason_details : '' }}</p></div>
        <p style="color:#6c7780">No future revenue estimate is shown because the request has no defined order-volume forecast.</p>
        @if($shippingRateRequest->reviewer)<p><strong>Decision:</strong> {{ $shippingRateRequest->reviewer->name }} · {{ $shippingRateRequest->reviewed_at?->format('M j, Y g:i A') }}<br><strong>Decision note:</strong> {{ $shippingRateRequest->admin_decision_reason ?: '—' }}</p>@endif
    </div>

    @if($shippingRateRequest->status === 'PENDING')
        <div class="ad-card ad-card-body">
            <h2>Admin decision</h2>
            <p>Approving activates exactly PHP {{ number_format((float) $shippingRateRequest->requested_shipping_fee, 2) }} immediately for new eligible checkouts. Existing SellerOrder fee snapshots remain unchanged. The active Rider/Logistics split stays at {{ number_format((float) $policy['rider_pool_percentage'], 2) }}% / {{ number_format((float) $policy['logistics_percentage'], 2) }}%.</p>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.approve', $shippingRateRequest) }}" style="display:grid;gap:10px;margin-bottom:18px" onsubmit="return confirm('Approve exactly PHP {{ number_format((float) $shippingRateRequest->requested_shipping_fee, 2) }} effective immediately? Existing orders will keep their snapshots.')">
                @csrf
                <input type="hidden" name="expected_active_version_id" value="{{ $policy['version']?->id }}">
                @if($isStale)
                    <label><input type="checkbox" name="confirm_stale_baseline" value="1" required @checked(old('confirm_stale_baseline'))> I reviewed the original PHP {{ number_format((float) $shippingRateRequest->current_shipping_fee, 2) }} baseline, current PHP {{ number_format((float) $policy['shipping_fee'], 2) }} rate, and confirm approval of the exact PHP {{ number_format((float) $shippingRateRequest->requested_shipping_fee, 2) }} target.</label>
                @endif
                <label>{{ $isStale ? 'Required Admin decision note' : 'Optional Admin decision note' }}<textarea name="admin_decision_reason" maxlength="2000" rows="2" @required($isStale) style="display:block;width:100%;margin-top:6px">{{ old('admin_decision_reason') }}</textarea></label>
                <div><button type="submit" class="ad-btn ad-btn-primary">Approve Exact Requested Rate</button></div>
            </form>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.reject', $shippingRateRequest) }}" style="display:grid;gap:10px;border-top:1px solid #e8ece9;padding-top:16px">
                @csrf
                <label>Rejection reason<textarea name="admin_decision_reason" required minlength="3" maxlength="2000" rows="2" style="display:block;width:100%;margin-top:6px">{{ old('admin_decision_reason') }}</textarea></label>
                <div><button type="submit" class="ad-btn ad-btn-secondary">Reject Request</button></div>
            </form>
        </div>
    @endif

    <div class="ad-card ad-card-body">
        <h2>History from this Logistics operator</h2>
        <div class="ad-table-wrap"><table class="ad-table"><thead><tr><th>Date</th><th>Current</th><th>Requested</th><th>Reason</th><th>Status</th><th>Decision</th></tr></thead><tbody>
        @forelse($history as $historicRequest)
            <tr><td>{{ $historicRequest->created_at?->format('M j, Y g:i A') }}</td><td>PHP {{ number_format((float) $historicRequest->current_shipping_fee, 2) }}</td><td>PHP {{ number_format((float) $historicRequest->requested_shipping_fee, 2) }}</td><td>{{ \App\Models\Finance\ShippingRateChangeRequest::REASONS[$historicRequest->reason_code] ?? $historicRequest->reason_code }}</td><td>{{ str($historicRequest->status)->headline() }}</td><td>{{ $historicRequest->admin_decision_reason ?: '—' }}</td></tr>
        @empty
            <tr><td colspan="6">No earlier requests from this operator.</td></tr>
        @endforelse
        </tbody></table></div>
    </div>
</div>
@endsection
