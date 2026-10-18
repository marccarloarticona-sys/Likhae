@extends('layouts.admin')
@section('title', 'Compliance Case '.$case->case_number)
@section('subtitle', 'Review the recorded warning, product context, case history, and authorized decision.')
@section('active', 'compliance')

@php
    $product = $case->product;
    $seller = $case->sellerProfile;
    $sellerUser = $seller?->user;
    $warningAction = $case->actions->where('action_type', 'WARNING')->last();
    $publicActions = $case->actions->whereIn('action_type', ['WARNING', 'PRODUCT_SUSPEND']);
    $categoryLabel = $product?->category?->parent?->name
        ? $product->category->parent->name.' / '.$product->category->name
        : ($product?->category?->name ?: 'Not provided');
@endphp

@section('content')
<div class="ad-page ad-compliance-page ad-compliance-detail-page">
    <div class="ad-page-head">
        <div>
            <a class="ad-link" href="{{ route('admin.compliance') }}">&larr; Back to Compliance</a>
            <span class="ad-overline">Case {{ $case->case_number }}</span>
            <h2>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</h2>
            <p>Opened {{ $case->opened_at?->format('M d, Y H:i') ?? 'Date unavailable' }} · Last updated {{ $case->updated_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</p>
        </div>
        <span class="ad-status is-{{ \Illuminate\Support\Str::slug($case->status) }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span>
    </div>

    @if(session('status'))<div class="ad-note" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>@endif

    <div class="ad-compliance-detail-grid">
        <section class="ad-card">
            <header class="ad-card-head"><div><span class="ad-overline">Case overview</span><h2>Recorded context</h2></div></header>
            <div class="ad-card-body"><dl class="ad-compliance-meta">
                <div><dt>Case reference</dt><dd>{{ $case->case_number }}</dd></div>
                <div><dt>Status</dt><dd>{{ str($case->status)->replace('_', ' ')->headline() }}</dd></div>
                <div><dt>Violation type</dt><dd>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</dd></div>
                <div><dt>Date opened</dt><dd>{{ $case->opened_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
                <div><dt>Issuing Admin</dt><dd>{{ $case->openedByAdmin?->name ?: 'System' }}</dd></div>
                <div><dt>Last activity</dt><dd>{{ $case->actions->last()?->performed_at?->format('M d, Y H:i') ?? $case->updated_at?->format('M d, Y H:i') ?? 'Not provided' }}</dd></div>
            </dl></div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><span class="ad-overline">Seller information</span><h2>{{ $seller?->business_name ?: 'Seller unavailable' }}</h2></div></header>
            <div class="ad-card-body"><dl class="ad-compliance-meta">
                <div><dt>Seller name</dt><dd>{{ $sellerUser?->name ?: 'Not provided' }}</dd></div>
                <div><dt>Account reference</dt><dd>@if($sellerUser)<a class="ad-link" href="{{ route('admin.users.show', $sellerUser) }}">USR-{{ $sellerUser->id }}</a>@else Not provided @endif</dd></div>
                <div><dt>Registered line of business</dt><dd>{{ $seller?->primaryCategory?->name ?: 'Not provided' }}</dd></div>
                <div><dt>Account status</dt><dd>{{ $seller?->status ? str($seller->status)->replace('_', ' ')->headline() : 'Not provided' }}</dd></div>
            </dl><p class="ad-compliance-case-description">Previous compliance cases: {{ number_format($previousCaseCount ?? 0) }}</p>@if(($previousCases ?? collect())->isNotEmpty())<ul class="ad-compliance-history">@foreach($previousCases as $previousCase)<li><strong>{{ $previousCase->case_number }}</strong><span>{{ str($previousCase->violation_type)->replace('_', ' ')->headline() }} · {{ str($previousCase->status)->replace('_', ' ')->headline() }} · {{ $previousCase->opened_at?->format('M d, Y') }}</span></li>@endforeach</ul>@endif</div>
        </section>
    </div>

    <section class="ad-card">
        <header class="ad-card-head"><div><span class="ad-overline">Seller response</span><h2>Submitted explanations and evidence</h2></div></header>
        <div class="ad-card-body">
            @forelse($case->responses as $response)
                <article class="ad-compliance-response">
                    <div class="ad-compliance-response-head"><strong>{{ $response->sellerProfile?->business_name ?: $seller?->business_name ?: 'Seller' }}</strong><span>{{ $response->submitted_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</span></div>
                    <dl class="ad-compliance-meta">
                        <div><dt>Explanation</dt><dd>{!! nl2br(e($response->explanation)) !!}</dd></div>
                        <div><dt>Corrective action</dt><dd>{!! nl2br(e($response->corrective_action)) !!}</dd></div>
                    </dl>
                    @if($response->evidence_path)
                        <a class="ad-link" href="{{ route('admin.compliance.responses.evidence', [$case, $response]) }}">Download evidence{{ $response->evidence_original_name ? ' · '.$response->evidence_original_name : '' }}</a>
                    @else
                        <span class="ad-compliance-muted">No evidence file attached.</span>
                    @endif
                </article>
            @empty
                <p class="ad-compliance-case-description">No Seller response has been submitted for this case.</p>
            @endforelse
        </div>
    </section>

    <section class="ad-card">
        <header class="ad-card-head"><div><span class="ad-overline">Product information</span><h2>{{ $product?->name ?: 'Account-level seller case' }}</h2></div>@if($product)<a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.products.show', $product) }}">View Product</a>@endif</header>
        <div class="ad-card-body">
            @if($product)
                <div class="ad-compliance-product-grid">
                    <div class="ad-compliance-product-images">
                        @forelse($product->images as $image)
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->file_path) }}" alt="{{ $image->alt_text ?: $product->name }}" loading="lazy">
                        @empty
                            <span class="ad-product-gallery-placeholder">No product images recorded.</span>
                        @endforelse
                    </div>
                    <dl class="ad-compliance-meta">
                        <div><dt>Product ID</dt><dd>#{{ $product->id }}</dd></div>
                        <div><dt>Category / subcategory</dt><dd>{{ $categoryLabel }}</dd></div>
                        <div><dt>Listing status</dt><dd>{{ str($product->status)->replace('_', ' ')->headline() }}</dd></div>
                        <div><dt>Description</dt><dd>{{ $product->description ?: 'Not provided' }}</dd></div>
                    </dl>
                </div>
            @else
                <p class="ad-compliance-case-description">No product is linked to this case.</p>
            @endif
        </div>
    </section>

    <div class="ad-compliance-detail-grid">
        <section class="ad-card">
            <header class="ad-card-head"><div><span class="ad-overline">Violation details</span><h2>Warning and required correction</h2></div></header>
            <div class="ad-card-body"><p class="ad-compliance-case-description">{{ $case->description ?: 'No warning reason recorded.' }}</p>@if($warningAction)<p class="ad-compliance-case-description"><strong>Warning recorded:</strong> {{ $warningAction->performed_at?->format('M d, Y H:i') ?? 'Date unavailable' }} by {{ $warningAction->performedBy?->name ?: 'System' }}</p>@endif</div>
        </section>

        <section class="ad-card">
            <header class="ad-card-head"><div><span class="ad-overline">Evidence</span><h2>Recorded references</h2></div></header>
            <div class="ad-card-body"><p class="ad-compliance-case-description">Evidence and product references supplied with the warning are preserved in the recorded case description and action history.</p><p class="ad-compliance-case-description">Private attachments are not exposed unless a supported authenticated evidence record exists.</p></div>
        </section>
    </div>

    <section class="ad-card">
        <header class="ad-card-head"><div><span class="ad-overline">Case timeline</span><h2>Recorded events</h2></div></header>
        <div class="ad-card-body"><ol class="ad-compliance-timeline">
            @forelse($case->actions as $action)
                <li><strong>{{ str($action->action_type)->replace('_', ' ')->headline() }}</strong><span>{{ $action->performed_at?->format('M d, Y H:i') ?? 'Date unavailable' }} · {{ $action->performedBy?->name ?: 'System' }}</span>@if($action->reason)<p>{{ $action->reason }}</p>@endif</li>
            @empty
                <li><span>No case actions have been recorded.</span></li>
            @endforelse
        </ol></div>
    </section>

    <section class="ad-card ad-compliance-decision-card">
        <header class="ad-card-head"><div><span class="ad-overline">Admin decision</span><h2>Review and resolve</h2><p>Investigation notes remain Admin-only. Product reinstatement follows the authorized moderation workflow.</p></div></header>
        <div class="ad-card-body">
            <form class="ad-compliance-form" method="POST" action="{{ route('admin.compliance.update', $case) }}" data-confirm-title="Save compliance decision?" data-confirm-message="Update this case status and record the decision. If product reinstatement is selected, the listing will become available to buyers." data-confirm-label="Save decision">
                @csrf @method('PATCH')
                <label><span>Case status</span><select name="status" required>@foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED'] as $caseStatus)<option value="{{ $caseStatus }}" @selected($case->status === $caseStatus)>{{ str($caseStatus)->replace('_', ' ')->headline() }}</option>@endforeach</select></label>
                <label><span>Investigation note or resolution reason</span><textarea name="note" maxlength="5000" placeholder="Document the decision or next correction requested."></textarea></label>
                @if($product?->status === 'SUSPENDED')<label><span><input type="checkbox" name="reinstate_product" value="1"> Reinstate product only after resolving this case</span></label>@endif
                <div class="ad-inline-actions"><button class="ad-btn ad-btn-primary" type="submit">Save decision</button>@if($sellerUser)<a class="ad-btn ad-btn-secondary" href="{{ route('admin.users.show', $sellerUser) }}">Open User Management</a>@endif</div>
            </form>
        </div>
    </section>
</div>
@endsection
