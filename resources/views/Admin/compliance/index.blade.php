@extends('layouts.admin.app')
@section('title', 'Seller Compliance')
@section('subtitle', 'Investigate Seller violations, review corrections, and record authorized decisions.')
@section('active', 'compliance')

@section('content')
<div class="ad-page ad-compliance-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Compliance</span>
            <h2>Seller Compliance Cases</h2>
            <p>Warnings, investigations, and resolutions remain available for audit history.</p>
        </div>
        <div class="ad-inline-actions">
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.compliance.export', request()->query()) }}">Export PDF</a>
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.products') }}">Review Products</a>
        </div>
    </div>

    @if(session('status'))<div class="ad-note" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>@endif

    <div class="ad-stat-grid ad-stat-grid-4">
        <x-admin.stat-card label="Open cases" :value="number_format($stats['open'] ?? 0)" detail="Warnings requiring attention" icon="case" tone="warning" />
        <x-admin.stat-card label="Under review" :value="number_format($stats['under_review'] ?? 0)" detail="Currently being investigated" icon="chart" tone="blue" />
        <x-admin.stat-card label="Resolved cases" :value="number_format($stats['resolved'] ?? 0)" detail="Resolved or closed history" icon="flag" tone="success" />
        <x-admin.stat-card label="Suspended products" :value="number_format($stats['suspended_products'] ?? 0)" detail="Unique products linked to cases" icon="case" tone="danger" />
    </div>

    <section class="ad-card">
        <form class="ad-filter-bar" method="GET" action="{{ route('admin.compliance') }}">
            <label class="ad-filter-search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                <input type="search" name="search" value="{{ $search ?? '' }}" placeholder="Search case, Seller, store, product, or violation" aria-label="Search compliance cases">
            </label>
            <select class="ad-select" name="status" aria-label="Filter compliance status">
                <option value="">All statuses</option>
                @foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED'] as $caseStatus)
                    <option value="{{ $caseStatus }}" @selected(($status ?? '') === $caseStatus)>{{ str($caseStatus)->replace('_', ' ')->headline() }}</option>
                @endforeach
            </select>
            <button class="ad-btn ad-btn-primary" type="submit">Search</button>
            @if(($search ?? null) || ($status ?? null))<a class="ad-btn ad-btn-secondary" href="{{ route('admin.compliance') }}">Clear</a>@endif
        </form>
        <div class="ad-table-wrap">
            <table class="ad-table">
                <thead>
                    <tr><th>Case reference</th><th>Seller / Store</th><th>Related product</th><th>Violation type</th><th>Date reported</th><th>Status</th><th>Last activity</th><th></th></tr>
                </thead>
                <tbody>
                @forelse($cases as $case)
                    @php $lastActivity = $case->actions->last(); @endphp
                    <tr>
                        <td><strong>{{ $case->case_number }}</strong><small>Opened {{ $case->opened_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</small></td>
                        <td><strong>{{ $case->sellerProfile?->business_name ?: 'Seller unavailable' }}</strong><small>{{ $case->sellerProfile?->user?->name ?: 'Account unavailable' }}</small></td>
                        <td>{{ $case->product?->name ? '#'.$case->product->id.' · '.$case->product->name : 'Account-level seller case' }}</td>
                        <td>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</td>
                        <td>{{ $case->opened_at?->format('M d, Y') ?? 'Not provided' }}</td>
                        <td><span class="ad-status is-{{ \Illuminate\Support\Str::slug($case->status) }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span></td>
                        <td>{{ $lastActivity?->performed_at?->format('M d, Y H:i') ?? $case->updated_at?->format('M d, Y H:i') ?? 'Not provided' }}</td>
                        <td><a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.compliance.show', $case) }}">View Case</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8"><div class="ad-empty"><h3>No compliance cases found</h3><p>Try a different search or status filter.</p></div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($cases->hasPages())<div class="ad-card-body">{{ $cases->links() }}</div>@endif
    </section>
</div>
@endsection
