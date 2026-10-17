@extends('layouts.seller')
@section('title', 'Compliance Case '.$case->case_number)
@section('active', 'products')
@section('subtitle', 'Review the warning, product context, and current Admin decision.')

@php
    $product = $case->product;
    $categoryLabel = $product?->category?->parent?->name
        ? $product->category->parent->name.' / '.$product->category->name
        : ($product?->category?->name ?: 'Not provided');
@endphp

@section('content')
<div class="sl-page sl-compliance-page sl-compliance-detail-page">
    <div class="sl-page-toolbar"><div><a class="sl-editor-back" href="{{ route('seller.compliance') }}">&larr; Back to Compliance Notices</a><span class="sl-eyebrow">{{ $case->case_number }}</span><h2>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</h2><p>Issued {{ $case->opened_at?->format('M d, Y H:i') ?? 'Date unavailable' }}.</p></div><span class="sl-status {{ in_array($case->status, ['RESOLVED', 'CLOSED'], true) ? 'is-success' : ($case->status === 'UNDER_REVIEW' ? 'is-info' : 'is-warning') }}">{{ str($case->status)->replace('_', ' ')->headline() }}</span></div>
    <div class="sl-compliance-detail-grid">
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Warning details</span><h2>What needs attention</h2></div></header><div class="sl-card-body"><dl class="sl-compliance-meta"><div><dt>Case reference</dt><dd>{{ $case->case_number }}</dd></div><div><dt>Related product</dt><dd>{{ $product?->name ?: 'Account-level seller case' }}</dd></div><div><dt>Current listing status</dt><dd>{{ $product?->status ? str($product->status)->replace('_', ' ')->headline() : 'Not applicable' }}</dd></div><div><dt>Warning category</dt><dd>{{ str($case->violation_type)->replace('_', ' ')->headline() }}</dd></div></dl><div class="sl-compliance-notice-copy">{!! nl2br(e($warningAction?->reason ?: $case->description ?: 'No warning reason recorded.')) !!}</div></div></section>
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Product correction</span><h2>Make the requested change</h2></div></header><div class="sl-card-body"><p class="sl-compliance-help">Update the affected listing using the existing Product Management editor. Saving corrections does not automatically resolve this case.</p>
            @if($product)
                <a class="sl-btn sl-btn-primary" href="{{ route('seller.products', ['mode' => 'edit', 'product' => $product->id]) }}">Edit Product</a>
            @endif
            @if($product?->status === 'SUSPENDED')<p class="sl-compliance-warning">This listing remains suspended. Seller edits cannot publish, archive, restore, or reinstate it; only Admin can authorize reinstatement.</p>@endif
        </div></section>
    </div>

    <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Product context</span><h2>{{ $product?->name ?: 'No product linked' }}</h2></div></header><div class="sl-card-body">@if($product)<div class="sl-compliance-product-grid"><div class="sl-compliance-images">@forelse($product->images as $image)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->file_path) }}" alt="{{ $image->alt_text ?: $product->name }}" loading="lazy">@empty<span class="sl-compliance-help">No product images recorded.</span>@endforelse</div><dl class="sl-compliance-meta"><div><dt>Category</dt><dd>{{ $categoryLabel }}</dd></div><div><dt>Description</dt><dd>{{ $product->description ?: 'Not provided' }}</dd></div></dl></div>@else<p class="sl-compliance-help">No product is linked to this case.</p>@endif</div></section>

    <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Case history</span><h2>Seller-visible events</h2></div></header><div class="sl-card-body"><ol class="sl-compliance-timeline">@forelse($publicActions as $action)<li><strong>{{ str($action->action_type)->replace('_', ' ')->headline() }}</strong><span>{{ $action->performed_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</span></li>@empty<li><span>No public case events recorded.</span></li>@endforelse</ol></div></section>

    <section class="sl-card sl-compliance-response-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller response</span><h2>Explain the correction</h2></div></header><div class="sl-card-body">
        @if(session('status'))<div class="sl-compliance-feedback" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="sl-compliance-feedback is-warning" role="alert">{{ $errors->first() }}</div>@endif
        @if(in_array($case->status, ['OPEN', 'UNDER_REVIEW'], true))
            <p class="sl-compliance-help">Submit your explanation and the corrective action you took. Admin will review the response before resolving this case.</p>
            <form class="sl-compliance-response-form" method="POST" enctype="multipart/form-data" action="{{ route('seller.compliance.responses.store', $case) }}">
                @csrf
                <label><span>Explanation</span><textarea name="explanation" minlength="10" maxlength="10000" required placeholder="Explain what happened and the correction you made.">{{ old('explanation') }}</textarea></label>
                <label><span>Corrective action</span><textarea name="corrective_action" minlength="5" maxlength="10000" required placeholder="Describe the specific changes completed on the listing.">{{ old('corrective_action') }}</textarea></label>
                <label><span>Evidence (optional, JPG/PNG/WEBP/PDF up to 5 MB)</span><input type="file" name="evidence" accept=".jpg,.jpeg,.png,.webp,.pdf"></label>
                <button class="sl-btn sl-btn-primary" type="submit">Submit Response</button>
            </form>
        @else
            <p class="sl-compliance-help">This case is {{ str($case->status)->replace('_', ' ')->lower() }} and no further response can be submitted.</p>
        @endif
        <div class="sl-compliance-response-history"><h3>Submitted responses</h3>
            @forelse($case->responses as $response)
                <article class="sl-compliance-response"><div class="sl-compliance-response-head"><strong>{{ $response->submitted_at?->format('M d, Y H:i') ?? 'Date unavailable' }}</strong></div><p><strong>Explanation:</strong> {!! nl2br(e($response->explanation)) !!}</p><p><strong>Corrective action:</strong> {!! nl2br(e($response->corrective_action)) !!}</p>
                    @if($response->evidence_path)
                        @if($response->evidence_available)
                            <a class="sl-link" href="{{ route('seller.compliance.responses.evidence', [$case, $response]) }}">Download {{ $response->evidence_original_name ?: 'attached evidence' }}</a>
                        @else
                            <span class="sl-compliance-help">Evidence file unavailable.</span>
                        @endif
                    @endif
                </article>
            @empty
                <p class="sl-compliance-help">No response submitted yet.</p>
            @endforelse
        </div>
    </div></section>
</div>
@endsection
