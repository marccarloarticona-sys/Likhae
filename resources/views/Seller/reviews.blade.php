@extends('layouts.seller')
@section('title', 'Reviews')
@section('active', 'reviews')
@section('subtitle', 'Read buyer feedback and manage your replies.')
@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Feedback</span><h2>Product Reviews</h2><p>Reviews are shown only for products in your store.</p></div><a class="sl-btn sl-btn-ghost" href="{{ route('seller.reviews.export', $filters) }}">Download PDF</a></div>

    <section class="sl-card sl-order-filters">
        <form method="GET" action="{{ route('seller.reviews') }}" class="grid gap-3 md:grid-cols-6">
            <label class="sl-field"><span>Rating</span><select name="rating"><option value="">All ratings</option>@foreach(range(5,1) as $rating)<option value="{{ $rating }}" @selected((string)($filters['rating'] ?? '') === (string)$rating)>{{ $rating }} stars</option>@endforeach</select></label>
            <label class="sl-field md:col-span-2"><span>Product</span><select name="product_id"><option value="">All products</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected((string)($filters['product_id'] ?? '') === (string)$product->id)>{{ $product->name }}</option>@endforeach</select></label>
            <label class="sl-field md:col-span-2"><span>Variant</span><select name="variant"><option value="">All variants</option>@foreach($variants as $variant)<option value="{{ $variant }}" @selected(($filters['variant'] ?? '') === $variant)>{{ $variant }}</option>@endforeach</select></label>
            <label class="sl-field"><span>From date</span><input type="date" name="from_date" value="{{ $filters['from_date'] ?? '' }}"></label>
            <label class="sl-field"><span>To date</span><input type="date" name="to_date" value="{{ $filters['to_date'] ?? '' }}"></label>
            <div class="flex items-end gap-2 md:col-span-6"><button class="sl-btn sl-btn-primary" type="submit">Apply Filters</button><a class="sl-btn sl-btn-ghost" href="{{ route('seller.reviews') }}">Clear</a></div>
        </form>
    </section>

    @forelse($reviews as $review)
        <article class="sl-card mb-4">
            <header class="sl-card-head"><div><span class="sl-eyebrow">{{ $review->created_at?->format('M d, Y g:i A') }}</span><h3>{{ $review->buyer_display_name ?? 'Verified Buyer' }}</h3></div><strong aria-label="{{ $review->rating }} out of 5 stars">{{ str_repeat('★', (int) $review->rating) }}{{ str_repeat('☆', 5 - (int) $review->rating) }} <span>{{ $review->rating }}/5</span></strong></header>
            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_180px]">
                <div><p class="font-semibold">{{ $review->orderItem?->product_name ?? $review->orderItem?->product?->name ?? 'Product' }}</p><p class="text-sm text-stone-600">Variant: {{ $review->orderItem?->variant_description ?: 'Standard' }}</p><p class="mt-3 whitespace-pre-line">{{ $review->comment ?: 'No written comment.' }}</p></div>
                @if(!empty($reviewImagesById[$review->id] ?? []))<div class="flex flex-wrap gap-2">@foreach($reviewImagesById[$review->id] as $reviewImageUrl)<img class="h-40 w-40 max-w-full rounded-xl border border-stone-200 object-cover" src="{{ $reviewImageUrl }}" alt="Buyer review photo">@endforeach</div>@endif
            </div>

            @if(filled($review->seller_reply))
                <div class="mt-4 rounded-xl border border-stone-200 bg-stone-50 p-4"><div class="flex flex-wrap items-center justify-between gap-2"><strong>Your reply</strong><time class="text-sm text-stone-500">{{ $review->seller_replied_at?->format('M d, Y g:i A') ?? 'Date unavailable' }}</time></div><p class="mt-2 whitespace-pre-line">{{ $review->seller_reply }}</p></div>
            @endif

            <form method="POST" action="{{ route('seller.reviews.reply', $review) }}" class="mt-4">
                @csrf<input type="hidden" name="review_id" value="{{ $review->id }}">
                <label class="sl-field"><span>{{ filled($review->seller_reply) ? 'Edit reply' : 'Reply to buyer' }}</span><textarea name="seller_reply" rows="3" maxlength="2000">{{ old('review_id') == $review->id ? old('seller_reply') : $review->seller_reply }}</textarea></label>
                @if(old('review_id') == $review->id) @error('seller_reply')<p class="mt-2 text-sm font-semibold text-red-700">{{ $message }}</p>@enderror @endif
                <button class="sl-btn sl-btn-primary mt-3" type="submit">{{ filled($review->seller_reply) ? 'Save Reply' : 'Post Reply' }}</button>
            </form>
        </article>
    @empty
        <section class="sl-card"><x-seller.empty-state title="No reviews found" message="Reviews matching these filters will appear here." action="Clear Filters" :href="route('seller.reviews')" /></section>
    @endforelse

    @if($reviews->hasPages())<div class="mt-4">{{ $reviews->links() }}</div>@endif
</div>
@endsection
