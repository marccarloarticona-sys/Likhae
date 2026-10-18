@extends('layouts.admin')
@section('title', 'Dispute '.$dispute->dispute_number)
@section('subtitle', 'Review the complete escalation and record an accountable Admin decision.')
@section('active', 'complaints')
@push('head')
<style>
    @media (max-width: 900px) {
        .ad-dispute-layout { grid-template-columns: 1fr !important; }
        .ad-dispute-layout > aside { position: static !important; }
        .ad-dispute-page .ad-summary-grid { grid-template-columns: 1fr !important; }
    }
</style>
@endpush
@section('content')
@php
    $sellerRefund = $dispute->sellerReturnRefundRequest;
    $parent = $sellerRefund?->request ?? $dispute->returnRefundRequest;
    $affectedItems = $sellerRefund?->items ?? $parent?->items ?? collect();
    $evidence = $parent?->evidence ?? collect();
    $events = $parent?->events ?? collect();
    $canDecide = in_array($dispute->status, ['OPEN', 'UNDER_REVIEW'], true);
    $statusClass = str($dispute->status)->lower()->replace('_', '-');
    $isCashCase = strtoupper((string) $parent?->refund_method) === 'CASH';
@endphp

<div class="ad-dispute-page" style="display:grid;gap:16px">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Refunds & Disputes</span>
            <h2>{{ $dispute->dispute_number }}</h2>
            <p>{{ $dispute->subject }} · opened {{ $dispute->opened_at?->format('M d, Y g:i A') }}</p>
        </div>
        <div class="ad-inline-actions">
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.complaints') }}">Back to Disputes</a>
            <span class="ad-status is-{{ $statusClass }}">{{ str($dispute->status)->headline() }}</span>
        </div>
    </div>

    @if(session('status'))
        <div class="ad-card" style="padding:14px;color:var(--ad-blue-dark);background:var(--ad-blue-soft)">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="ad-card" style="padding:14px;color:var(--ad-danger);background:var(--ad-danger-soft)">
            <strong>Action could not be saved.</strong>
            <ul style="margin:6px 0 0 18px">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="ad-dispute-layout" style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px;align-items:start">
        <div style="display:grid;gap:16px">
            <section class="ad-card">
                <header class="ad-card-head">
                    <div>
                        <span class="ad-overline">Case overview</span>
                        <h2>{{ $parent?->request_number ?: 'General dispute' }}</h2>
                        <p>Admin access includes the Buyer request, Seller decision, order items, evidence, and audit history.</p>
                    </div>
                    <span class="ad-status is-{{ str($dispute->type)->lower()->replace('_', '-') }}">{{ str($dispute->type)->headline() }}</span>
                </header>
                <div class="ad-card-body">
                    <div class="ad-summary-grid" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
                        <div><small>Buyer</small><strong>{{ $dispute->openedBy?->name ?: $parent?->buyer?->name ?: 'Unavailable' }}</strong><span>{{ $dispute->openedBy?->email }}</span></div>
                        <div><small>Seller</small><strong>{{ $sellerRefund?->sellerProfile?->business_name ?: $dispute->sellerOrder?->sellerProfile?->business_name ?: 'Unavailable' }}</strong><span>{{ $sellerRefund?->sellerOrder?->seller_order_number ?: 'Seller order unavailable' }}</span></div>
                        <div><small>Order</small><strong>{{ $dispute->order?->order_number ?: 'Unavailable' }}</strong><span>{{ $dispute->shipment?->tracking_number ?: 'Tracking unavailable' }}</span></div>
                        <div><small>Request status</small><strong>{{ $parent ? str($parent->status)->headline() : 'General dispute' }}</strong><span>{{ $sellerRefund ? str($sellerRefund->status)->headline() : '' }}</span></div>
                        <div><small>Issue</small><strong>{{ $parent?->issue_category ?: $dispute->type }}</strong><span>{{ $parent?->issue_reason ?: 'See dispute description' }}</span></div>
                        <div><small>Requested amount</small><strong>{{ $sellerRefund ? 'PHP '.number_format((float) $sellerRefund->requested_amount, 2) : 'Not available' }}</strong><span>{{ $sellerRefund?->approved_amount !== null ? 'Approved PHP '.number_format((float) $sellerRefund->approved_amount, 2) : 'Not approved' }}</span></div>
                    </div>
                </div>
            </section>

            <section class="ad-card">
                <header class="ad-card-head"><div><h3>Complaint and requested remedy</h3><p>Read the Buyer’s escalation together with the original Seller decision.</p></div></header>
                <div class="ad-card-body" style="display:grid;gap:14px">
                    <div><small class="ad-overline">Dispute subject</small><p style="margin:5px 0 0;font-weight:600">{{ $dispute->subject }}</p></div>
                    <div><small class="ad-overline">Buyer’s escalation</small><p style="margin:5px 0 0;white-space:pre-wrap">{{ $dispute->description }}</p></div>
                    @if($sellerRefund?->decision_reason)
                        <div style="padding:12px;border-left:3px solid var(--ad-warning);background:var(--ad-warning-soft)"><small class="ad-overline">Seller decision</small><p style="margin:5px 0 0;white-space:pre-wrap">{{ $sellerRefund->decision_reason }}</p></div>
                    @endif
                    @if($parent?->description)
                        <div><small class="ad-overline">Original Buyer request</small><p style="margin:5px 0 0;white-space:pre-wrap">{{ $parent->description }}</p></div>
                    @endif
                </div>
            </section>

            @if($parent)
                <section class="ad-card">
                    <header class="ad-card-head"><div><h3>Refund and affected items</h3><p>Confirm what the Seller must refund before recording the decision.</p></div></header>
                    <div class="ad-card-body">
                        <div class="ad-summary-grid" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
                            <div><small>{{ $isCashCase ? 'Cash refund source' : 'GCash recipient' }}</small><strong>{{ $isCashCase ? 'Pending authorized payer policy' : ($parent->gcash_recipient_name ?: 'Not provided') }}</strong><span>{{ $isCashCase ? 'No online destination' : ($parent->gcash_mobile ?: 'Not provided') }}</span></div>
                            <div><small>Refund method</small><strong>{{ str($parent->refund_method ?: 'Not specified')->headline() }}</strong><span>{{ $isCashCase ? 'Approval → custody → inspection → cash states' : 'Historical Seller payment report' }}</span></div>
                            <div><small>Parent requested</small><strong>PHP {{ number_format((float) $parent->requested_amount, 2) }}</strong><span>Eligible amount is item-scoped</span></div>
                        </div>
                        <div class="ad-table-wrap" style="margin-top:16px">
                            <table class="ad-table">
                                <thead><tr><th>Product</th><th>Quantity</th><th>Eligible amount</th></tr></thead>
                                <tbody>
                                    @forelse($affectedItems as $item)
                                        <tr><td><strong>{{ $item->orderItem?->product_name ?: 'Item unavailable' }}</strong><small>{{ $item->orderItem?->variant_description ?: 'Standard' }}</small></td><td>{{ $item->quantity }}</td><td>PHP {{ number_format((float) $item->refundable_amount, 2) }}</td></tr>
                                    @empty
                                        <tr><td colspan="3">No affected items were linked to this dispute.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif

            @if($evidence->isNotEmpty() || $dispute->evidence->isNotEmpty())
                <section class="ad-card">
                    <header class="ad-card-head"><div><h3>Protected evidence</h3><p>Evidence remains private and is served through authorized, no-store endpoints.</p></div></header>
                    <div class="ad-card-body" style="display:grid;gap:8px">
                        @foreach($evidence as $file)
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--ad-border)"><div><strong>{{ $file->original_name ?: 'Buyer evidence' }}</strong><small>{{ str($file->evidence_type)->headline() }} · {{ $file->mime_type ?: 'Protected file' }}</small></div><a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.disputes.refund-evidence', $file) }}" target="_blank" rel="noopener">Open evidence</a></div>
                        @endforeach
                        @foreach($dispute->evidence as $file)
                            <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.disputes.evidence', $file) }}" target="_blank" rel="noopener">Open {{ $file->original_name ?: 'dispute evidence' }}</a>
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--ad-border)"><div><strong>{{ $file->original_name ?: 'Dispute evidence' }}</strong><small>Dispute attachment · {{ $file->mime_type ?: 'Protected file' }}</small></div><span class="ad-status is-review">Attached to dispute</span></div>
                        @endforeach
                    </div>
                </section>
            @endif

            <section class="ad-card">
                <header class="ad-card-head"><div><h3>History</h3><p>Persisted events for this request and Seller child.</p></div></header>
                <div class="ad-card-body">
                    <ol style="display:grid;gap:12px;margin:0;padding-left:20px">
                        <li><strong>Dispute opened</strong><small>{{ $dispute->opened_at?->format('M d, Y g:i A') }} · {{ $dispute->openedBy?->name }}</small><p style="margin:4px 0 0;white-space:pre-wrap">{{ $dispute->description }}</p></li>
                        @foreach($events->where('seller_return_refund_request_id', $sellerRefund?->id)->sortByDesc('created_at') as $event)
                            <li><strong>{{ str($event->event_type)->headline() }}</strong><small>{{ $event->created_at?->format('M d, Y g:i A') }} · {{ $event->actor?->name ?: 'System' }}</small>@if($event->details)<p style="margin:4px 0 0;white-space:pre-wrap">{{ $event->details }}</p>@endif</li>
                        @endforeach
                    </ol>
                </div>
            </section>
        </div>

        <aside style="display:grid;gap:16px;position:sticky;top:16px">
            @if($sellerRefund && $canDecide)
                <section class="ad-card">
                    <header class="ad-card-head"><div><span class="ad-overline">Admin decision</span><h3>Resolve this escalation</h3><p>Choose one accountable outcome. {{ $isCashCase ? 'Cash payout remains blocked until the authorized payer policy is configured.' : 'The Admin does not transfer GCash directly.' }}</p></div></header>
                    <div class="ad-card-body" style="display:grid;gap:14px">
                        <form method="POST" action="{{ route('admin.disputes.update', $dispute) }}" style="display:grid;gap:8px;padding-bottom:14px;border-bottom:1px solid var(--ad-border)" data-confirm-title="Return case to seller?" data-confirm-message="Record this resolution and require the seller to refund the eligible amount to the buyer. Verify the resolution before continuing." data-confirm-label="Return to seller">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="RESOLVED">
                            <input type="hidden" name="admin_action" value="RETURN_TO_SELLER_REFUND">
                            <label class="ad-field"><span>Solution: return to Seller and demand refund</span><textarea name="resolution" rows="4" required>Admin reviewed the escalation and requires the Seller to refund the eligible amount to the Buyer via {{ $isCashCase ? 'cash after return custody and inspection' : 'the historical GCash workflow' }}.</textarea></label>
                            <button class="ad-btn ad-btn-primary" type="submit">Return to Seller &amp; Require Refund</button>
                        </form>
                        <form method="POST" action="{{ route('admin.disputes.update', $dispute) }}" style="display:grid;gap:8px" data-confirm-title="Reject this escalation?" data-confirm-message="Close the buyer's escalation as rejected using the reason entered below?" data-confirm-label="Reject escalation" data-confirm-tone="danger">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="REJECTED">
                            <input type="hidden" name="admin_action" value="REJECT_ESCALATION">
                            <label class="ad-field"><span>Reason for rejecting the escalation</span><textarea name="resolution" rows="4" required placeholder="Explain why the escalation is rejected."></textarea></label>
                            <button class="ad-btn ad-btn-danger" type="submit">Reject Escalation</button>
                        </form>
                    </div>
                </section>
            @elseif($canDecide)
                <section class="ad-card">
                    <header class="ad-card-head"><div><span class="ad-overline">Admin decision</span><h3>Update dispute</h3><p>Record a status and explanation for this general dispute.</p></div></header>
                    <div class="ad-card-body">
                        <form method="POST" action="{{ route('admin.disputes.update', $dispute) }}" style="display:grid;gap:10px" data-confirm-title="Save dispute decision?" data-confirm-message="Record the selected dispute status and resolution for both parties." data-confirm-label="Save decision">
                            @csrf
                            @method('PATCH')
                            <label class="ad-field"><span>Status</span><select name="status">@foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'REJECTED'] as $status)<option value="{{ $status }}" @selected($dispute->status === $status)>{{ str($status)->headline() }}</option>@endforeach</select></label>
                            <label class="ad-field"><span>Resolution</span><textarea name="resolution" rows="5" required placeholder="Record the investigation outcome."></textarea></label>
                            <button class="ad-btn ad-btn-primary" type="submit">Save Decision</button>
                        </form>
                    </div>
                </section>
            @else
                <section class="ad-card">
                    <header class="ad-card-head"><div><span class="ad-overline">Decision recorded</span><h3>{{ str($dispute->status)->headline() }}</h3><p>{{ $dispute->resolved_at?->format('M d, Y g:i A') }}</p></div></header>
                    <div class="ad-card-body"><p style="white-space:pre-wrap;margin:0">{{ $dispute->resolution ?: 'No resolution note was recorded.' }}</p></div>
                </section>
            @endif

            <section class="ad-card">
                <header class="ad-card-head"><div><h3>What happens next</h3><p>Workflow responsibility after this decision.</p></div></header>
                <div class="ad-card-body">
                    <ol style="display:grid;gap:9px;margin:0;padding-left:20px;font-size:10px">
                        <li><strong>Return to Seller:</strong> the Seller sees Admin Refund Required; {{ $isCashCase ? 'cash stays in the obligation workflow until an authorized payer is configured.' : 'historical online payment proof remains available.' }}</li>
                        <li><strong>Reject escalation:</strong> the Seller request is closed as rejected and the Buyer receives the recorded reason.</li>
                        <li><strong>Audit:</strong> the decision, actor, amount, and explanation are retained in the refund event history.</li>
                    </ol>
                </div>
            </section>
        </aside>
    </div>
</div>
@endsection
