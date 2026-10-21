@extends('layouts.seller.app')
@section('title', 'Compliance Notices')
@section('active', 'products')
@section('subtitle', 'Review product warnings, required corrections, and recorded case outcomes.')

@section('content')
<div class="sl-page sl-compliance-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Seller compliance</span><h2>Compliance Notices</h2><p>Warnings connected to your products remain available for reference.</p></div><a class="sl-btn sl-btn-ghost" href="{{ route('seller.products') }}">Back to Products</a></div>
    @if(session('status'))<div class="sl-alert sl-alert-success" role="status">{{ session('status') }}</div>@endif
    <section class="sl-card">
        <div class="sl-table-wrap"><table class="sl-table sl-compliance-table"><thead><tr><th>Case</th><th>Product</th><th>Warning category</th><th>Date issued</th><th>Case status</th><th>Product status</th><th></th></tr></thead><tbody>
            @forelse($cases as $case)
                <tr>
                    <td><strong>{{ $case->case_number }}</strong><small>{{ $case->opened_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</small></td>
                    <td>{{ $case->product?->name ?: 'Account-level seller case' }}</td>
                    <td>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</td>
                    <td>{{ $case->opened_at?->format('M d, Y') ?? 'Not provided' }}</td>
                    <td><span class="sl-status {{ in_array($case->status, ['RESOLVED', 'CLOSED'], true) ? 'is-success' : ($case->status === 'UNDER_REVIEW' ? 'is-info' : 'is-warning') }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span></td>
                    <td>{{ $case->product?->status ? str($case->product->status)->replace('_', ' ')->headline() : 'Not applicable' }}</td>
                    <td><a class="sl-btn sl-btn-ghost sl-btn-sm" href="{{ route('seller.compliance.show', $case) }}">View Details</a></td>
                </tr>
            @empty
                <tr><td colspan="7"><div class="sl-empty-state"><h3>No compliance notices</h3><p>Product warnings and Admin decisions will appear here.</p></div></td></tr>
            @endforelse
        </tbody></table></div>
        @if($cases->hasPages())<div class="sl-card-body">{{ $cases->links() }}</div>@endif
    </section>
</div>
@endsection
