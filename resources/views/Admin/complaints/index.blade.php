@extends('layouts.admin.app')
@section('title', 'Complaints and Disputes')
@section('subtitle', 'Review existing disputes and explicitly escalated refund cases.')
@section('active', 'complaints')
@section('content')
<div style="display:grid;gap:16px">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Cases</span>
            <h2>Complaints and Disputes</h2>
            <p>Ordinary Seller-managed refund requests remain outside this queue until a Buyer files an escalation.</p>
        </div>
    </div>

    <div class="ad-card">
        <div class="ad-table-wrap">
            <table class="ad-table">
                <thead>
                    <tr>
                        <th>Dispute</th>
                        <th>Buyer / Seller</th>
                        <th>Request</th>
                        <th>Issue and evidence</th>
                        <th>Decision</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($disputes as $dispute)
                        @php($sellerRefund = $dispute->sellerReturnRefundRequest)
                        @php($legacyRefund = $dispute->returnRefundRequest)
                        <tr>
                            <td>
                                <strong>{{ $dispute->dispute_number }}</strong>
                                <small>{{ str($dispute->status)->headline() }}</small>
                                <a style="display:block" href="{{ route('admin.disputes.show', $dispute) }}">Open full case</a>
                            </td>
                            <td>
                                {{ $dispute->openedBy?->name }}
                                <small>{{ $sellerRefund?->sellerProfile?->business_name ?: $dispute->sellerOrder?->sellerProfile?->business_name }}</small>
                            </td>
                            <td>
                                <strong>{{ $sellerRefund?->request?->request_number ?: $legacyRefund?->request_number ?: 'General dispute' }}</strong>
                                <small>{{ $dispute->order?->order_number }}</small>
                                @if($sellerRefund)
                                    <small>
                                        Requested PHP {{ number_format((float) $sellerRefund->requested_amount, 2) }}
                                        @if($sellerRefund->approved_amount !== null)
                                            &middot; Approved PHP {{ number_format((float) $sellerRefund->approved_amount, 2) }}
                                        @endif
                                    </small>
                                @endif
                            </td>
                            <td>
                                <strong>{{ $dispute->subject }}</strong>
                                <small>{{ $dispute->description }}</small>

                                @foreach($dispute->evidence as $evidence)
                                    <small style="display:block">{{ $evidence->original_name }}</small>
                                @endforeach

                                @if($sellerRefund)
                                    @foreach($sellerRefund->request?->evidence ?? [] as $evidence)
                                        <a style="display:block" href="{{ route('admin.disputes.refund-evidence', $evidence) }}" target="_blank" rel="noopener">
                                            View protected {{ $evidence->original_name ?: 'Buyer evidence' }}
                                        </a>
                                    @endforeach

                                    @if($sellerRefund->payouts->isNotEmpty())
                                        @php($payout = $sellerRefund->payouts->sortByDesc('id')->first())
                                        <a style="display:block" href="{{ route('admin.disputes.payout-proof', $payout) }}" target="_blank" rel="noopener">View protected payment proof</a>
                                    @endif
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('admin.disputes.update', $dispute) }}" style="display:grid;gap:6px">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status">
                                        @foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'REJECTED'] as $status)
                                            <option value="{{ $status }}" @selected($dispute->status === $status)>{{ str($status)->headline() }}</option>
                                        @endforeach
                                    </select>
                                    <textarea name="resolution" placeholder="Investigation notes and decision explanation">{{ $dispute->resolution }}</textarea>
                                    <button class="ad-btn ad-btn-primary ad-btn-sm" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">No disputes or escalated refund cases.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="ad-card-body">{{ $disputes->links() }}</div>
    </div>
</div>
@endsection
