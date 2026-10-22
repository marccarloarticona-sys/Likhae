@extends('layouts.admin.app')
@section('title', 'Complaints and Disputes')
@section('subtitle', 'Review existing disputes and explicitly escalated refund cases.')
@section('active', 'complaints')
@section('content')
@php
    $openCount = (int) $statusCounts->get('OPEN', 0);
    $reviewCount = (int) $statusCounts->get('UNDER_REVIEW', 0);
    $completedCount = (int) $statusCounts->get('RESOLVED', 0) + (int) $statusCounts->get('REJECTED', 0) + (int) $statusCounts->get('CLOSED', 0);
    $totalCount = (int) $statusCounts->sum();
@endphp

<div class="ad-disputes-page">
    <div class="ad-page-head ad-disputes-hero">
        <div>
            <span class="ad-overline">Case management</span>
            <h2>Complaints and Disputes</h2>
            <p>Review buyer escalations, compare the evidence, then record a reasoned decision in the case.</p>
        </div>
        <div class="ad-disputes-hero-note">
            <strong>How this queue works</strong>
            <span>Seller-managed refund requests stay with the Seller until a Buyer escalates them to Admin.</span>
        </div>
    </div>

    @if(session('status'))
        <div class="ad-note" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="ad-disputes-stats" aria-label="Dispute case totals">
        <a class="ad-dispute-stat is-open" href="{{ route('admin.complaints', ['status' => 'OPEN']) }}">
            <span>New cases</span>
            <strong>{{ number_format($openCount) }}</strong>
            <small>Waiting for Admin review</small>
        </a>
        <a class="ad-dispute-stat is-review" href="{{ route('admin.complaints', ['status' => 'UNDER_REVIEW']) }}">
            <span>Under review</span>
            <strong>{{ number_format($reviewCount) }}</strong>
            <small>Investigation in progress</small>
        </a>
        <div class="ad-dispute-stat is-complete">
            <span>Decision recorded</span>
            <strong>{{ number_format($completedCount) }}</strong>
            <small>Resolved, rejected, or closed</small>
        </div>
        <a class="ad-dispute-stat is-total" href="{{ route('admin.complaints') }}">
            <span>All cases</span>
            <strong>{{ number_format($totalCount) }}</strong>
            <small>Every reported dispute</small>
        </a>
    </div>

    <section class="ad-card ad-disputes-queue">
        <form class="ad-disputes-filters" method="GET" action="{{ route('admin.complaints') }}">
            <label class="ad-disputes-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input type="search" name="search" value="{{ $filters['search'] }}" maxlength="120" placeholder="Search case, Buyer, Seller, order, or issue" aria-label="Search disputes">
            </label>
            <label class="ad-disputes-status-filter">
                <span class="ad-sr-only">Filter by case status</span>
                <select name="status" aria-label="Filter by case status">
                    <option value="">All case statuses</option>
                    @foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'REJECTED', 'CLOSED'] as $caseStatus)
                        <option value="{{ $caseStatus }}" @selected($filters['status'] === $caseStatus)>{{ str($caseStatus)->replace('_', ' ')->headline() }}</option>
                    @endforeach
                </select>
            </label>
            <button class="ad-btn ad-btn-primary" type="submit">Search cases</button>
            @if($filters['status'] || $filters['search'])
                <a class="ad-btn ad-btn-secondary" href="{{ route('admin.complaints') }}">Clear</a>
            @endif
        </form>

        <div class="ad-disputes-list-head">
            <div>
                <span class="ad-overline">Review queue</span>
                <h3>Reported cases</h3>
            </div>
            <p>{{ $disputes->total() }} {{ str('case')->plural($disputes->total()) }}</p>
        </div>

        <div class="ad-disputes-case-list">
            @forelse($disputes as $dispute)
                @php
                    $sellerRefund = $dispute->sellerReturnRefundRequest;
                    $refundRequest = $sellerRefund?->request ?? $dispute->returnRefundRequest;
                    $sellerName = $sellerRefund?->sellerProfile?->business_name ?: $dispute->sellerOrder?->sellerProfile?->business_name;
                    $orderNumber = $dispute->order?->order_number ?: $dispute->sellerOrder?->seller_order_number;
                    $evidenceCount = $dispute->evidence->count() + ($refundRequest?->evidence?->count() ?? 0);
                    $statusClass = str($dispute->status)->lower()->replace('_', '-');
                @endphp
                <article class="ad-dispute-case">
                    <div class="ad-dispute-case-top">
                        <div class="ad-dispute-case-title">
                            <div class="ad-dispute-case-reference">
                                <span>{{ $dispute->dispute_number }}</span>
                                <span aria-hidden="true">·</span>
                                <span>{{ $dispute->opened_at?->format('M d, Y g:i A') ?: 'Date unavailable' }}</span>
                            </div>
                            <h4>{{ $dispute->subject }}</h4>
                            <p>{{ $dispute->description }}</p>
                        </div>
                        <div class="ad-dispute-case-badges">
                            <span class="ad-status is-{{ $statusClass }}">{{ str($dispute->status)->replace('_', ' ')->headline() }}</span>
                            <span class="ad-dispute-type">{{ str($dispute->type)->replace('_', ' ')->headline() }}</span>
                        </div>
                    </div>

                    <div class="ad-dispute-case-details">
                        <div>
                            <small>Buyer</small>
                            <strong>{{ $dispute->openedBy?->name ?: 'Buyer account unavailable' }}</strong>
                            <span>Complaint filed by Buyer</span>
                        </div>
                        <div>
                            <small>Seller</small>
                            <strong>{{ $sellerName ?: 'No Seller linked' }}</strong>
                            <span>{{ $orderNumber ? 'Order '.$orderNumber : 'Order details unavailable' }}</span>
                        </div>
                        <div>
                            <small>Refund request</small>
                            <strong>{{ $refundRequest?->request_number ?: 'General dispute' }}</strong>
                            <span>
                                @if($sellerRefund)
                                    Requested PHP {{ number_format((float) $sellerRefund->requested_amount, 2) }}
                                @elseif($refundRequest)
                                    Requested PHP {{ number_format((float) $refundRequest->requested_amount, 2) }}
                                @else
                                    No refund amount attached
                                @endif
                            </span>
                        </div>
                        <div>
                            <small>Evidence</small>
                            <strong>{{ number_format($evidenceCount) }} {{ str('file')->plural($evidenceCount) }}</strong>
                            <span>{{ $evidenceCount ? 'Available in protected case review' : 'No files attached' }}</span>
                        </div>
                    </div>

                    <footer class="ad-dispute-case-footer">
                        <span>{{ $dispute->assignedAdmin?->name ? 'Assigned to '.$dispute->assignedAdmin->name : 'Unassigned case' }}</span>
                        <a class="ad-btn ad-btn-primary" href="{{ route('admin.disputes.show', $dispute) }}">Review case</a>
                    </footer>
                </article>
            @empty
                <div class="ad-disputes-empty">
                    <span class="ad-disputes-empty-icon" aria-hidden="true">✓</span>
                    <h4>{{ $filters['status'] || $filters['search'] ? 'No matching cases' : 'No open complaints' }}</h4>
                    <p>{{ $filters['status'] || $filters['search'] ? 'Try another status or search term.' : 'New Buyer escalations and disputes will appear here for review.' }}</p>
                    @if($filters['status'] || $filters['search'])
                        <a class="ad-btn ad-btn-secondary" href="{{ route('admin.complaints') }}">Show all cases</a>
                    @endif
                </div>
            @endforelse
        </div>

        @if($disputes->hasPages())
            <div class="ad-disputes-pagination">{{ $disputes->links() }}</div>
        @endif
    </section>
</div>
@endsection
