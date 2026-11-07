@extends('layouts.seller.app')

@section('title', 'Subcategory Requests')
@section('active', 'products')
@section('subtitle', 'Request a product subcategory for Admin review.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar">
        <div><span class="sl-eyebrow">Catalog</span><h2>Request a Subcategory</h2><p>Admin reviews every request and checks for an existing subcategory before deciding.</p></div>
        <a href="{{ route('seller.products') }}" class="sl-btn sl-btn-ghost">Back to Products</a>
    </div>

    @if(session('status'))
        <div class="sl-alert sl-alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="sl-alert sl-alert-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="sl-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Seller request</span><h2>Suggest a subcategory</h2><p>Submitting this form does not create a category. The parent category is fixed to your store’s main category.</p></div></header>
        <div class="sl-card-body">
            @if(! $schemaReady)
                <div class="sl-alert sl-alert-warning" role="status">Subcategory requests will be available after the request workflow migration is installed.</div>
            @elseif(! $parentCategory)
                <div class="sl-alert sl-alert-warning" role="status">Your Seller account does not have an active main category. Contact Admin to update your store category before requesting a subcategory.</div>
            @else
                <form method="POST" action="{{ route('seller.products.subcategory-requests.store') }}" class="sl-form-grid">
                    @csrf
                    <div class="sl-field sl-span-2">
                        <span>Parent category</span>
                        <div class="sl-readonly-value">{{ $parentCategory->name }}</div>
                        <small>This is the main category assigned to your store and cannot be changed on this request.</small>
                    </div>
                    @if($existingSubcategories->isNotEmpty())
                        <div class="sl-field sl-span-2">
                            <span>Existing subcategories under {{ $parentCategory->name }}</span>
                            <div class="sl-readonly-value">{{ $existingSubcategories->pluck('name')->implode(' · ') }}</div>
                            <small>Check this list first. Admin will also check for duplicates and may link your request to an existing subcategory.</small>
                        </div>
                    @endif
                    <label class="sl-field sl-span-2">
                        <span>Requested subcategory</span>
                        <input type="text" name="requested_name" maxlength="150" minlength="2" required value="{{ old('requested_name') }}" placeholder="Enter the subcategory name">
                        @error('requested_name')<small class="sl-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="sl-field sl-span-2">
                        <span>Why is this subcategory needed?</span>
                        <textarea name="reason" rows="4" minlength="10" maxlength="2000" required placeholder="Describe the products you need to list and why the current categories do not fit.">{{ old('reason') }}</textarea>
                        @error('reason')<small class="sl-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="sl-span-2 sl-toolbar-group">
                        <button type="submit" class="sl-btn sl-btn-primary" @disabled(! $schemaReady || ! $parentCategory)>Send Request</button>
                    </div>
                </form>
            @endif
        </div>
    </section>

    <section class="sl-card">
        <header class="sl-card-head"><div><span class="sl-eyebrow">Request history</span><h2>Your subcategory requests</h2><p>Admin decisions and resolutions appear here.</p></div></header>
        <div class="sl-card-body">
            @forelse($requests as $subcategoryRequest)
                @php
                    $requestStatusClass = match ($subcategoryRequest->status) {
                        'APPROVED' => 'is-success',
                        'REJECTED' => 'is-danger',
                        default => 'is-warning',
                    };
                @endphp
                <article class="sl-subcategory-request-row">
                    <div class="sl-subcategory-request-row__head">
                        <div><strong>{{ $subcategoryRequest->requested_name }}</strong><small>{{ $subcategoryRequest->parentCategory?->name ?? $subcategoryRequest->parent_category_name_snapshot }} · Submitted {{ $subcategoryRequest->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</small></div>
                        <span class="sl-status {{ $requestStatusClass }}">{{ str($subcategoryRequest->status)->lower()->headline() }}</span>
                    </div>
                    <p><strong>Your reason:</strong> {{ $subcategoryRequest->reason }}</p>
                    @if($subcategoryRequest->resolvedCategory)
                        <p><strong>Resolved subcategory:</strong> {{ $subcategoryRequest->resolvedCategory->parent?->name }} / {{ $subcategoryRequest->resolvedCategory->name }}</p>
                    @endif
                    @if(filled($subcategoryRequest->resolution))
                        <div class="sl-subcategory-request-resolution"><strong>Admin resolution</strong><p>{{ $subcategoryRequest->resolution }}</p></div>
                    @elseif($subcategoryRequest->status === 'PENDING')
                        <p class="text-muted">Waiting for Admin review. The category has not been created.</p>
                    @endif
                </article>
            @empty
                <p class="text-muted">No subcategory requests yet.</p>
            @endforelse
        </div>
    </section>
</div>
@endsection
