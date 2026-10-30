@extends('layouts.seller.app')
@section('title', 'Suggested Answers')
@section('active', 'messages')
@section('subtitle', 'Manage approved answers shown to Buyers in your conversations.')

@section('content')
<div class="sl-page sl-faq-manager">
    <div class="sl-page-toolbar">
        <div>
            <span class="sl-eyebrow">Buyer chats</span>
            <h2>Suggested answers</h2>
            <p>Add a question and its answer. New answers are available in all of your Buyer chats.</p>
        </div>
        <a class="sl-btn sl-btn-ghost" href="{{ route('seller.messages') }}">Back to messages</a>
    </div>

    @if(session('status'))
        <div class="sl-faq-notice sl-faq-notice--success" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="sl-faq-notice sl-faq-notice--error" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="sl-card sl-faq-create">
        <header class="sl-card-head">
            <div>
                <span class="sl-eyebrow">Create a reply</span>
                <h2>Add a suggested answer</h2>
                <p>Write the question a Buyer might ask and the reply you want to show.</p>
            </div>
        </header>
        <form class="sl-faq-form" method="POST" action="{{ route('seller.messages.faqs.store') }}">
            @csrf
            <input type="hidden" name="category" value="{{ old('category', 'Store information') }}">
            <input type="hidden" name="sort_order" value="0">
            <input type="hidden" name="product_id" value="">
            <input type="hidden" name="product_variant_id" value="">

            <label class="sl-field">
                <span>Buyer question</span>
                <input name="question" value="{{ old('question') }}" maxlength="300" required placeholder="Example: How long does shipping take?">
            </label>
            <label class="sl-field">
                <span>Your answer</span>
                <textarea name="answer" rows="4" maxlength="3000" required placeholder="Write a clear reply for Buyers">{{ old('answer') }}</textarea>
                <small>Plain text only. Formatting and links are not needed.</small>
            </label>
            <div class="sl-faq-form-actions">
                <label class="sl-faq-active-toggle"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Show to Buyers</label>
                <button class="sl-btn sl-btn-primary" type="submit">Save answer</button>
            </div>
        </form>
    </section>

    <section class="sl-faq-saved-section" aria-labelledby="saved-suggested-answers">
        <div class="sl-faq-saved-heading">
            <div>
                <span class="sl-eyebrow">Your replies</span>
                <h2 id="saved-suggested-answers">Saved answers</h2>
            </div>
            <span>{{ $faqs->total() }} {{ $faqs->total() === 1 ? 'answer' : 'answers' }}</span>
        </div>

        <div class="sl-faq-saved-list">
            @forelse($faqs as $faq)
                <article class="sl-card sl-faq-saved-item">
                    <header class="sl-faq-saved-item__head">
                        <span class="sl-faq-status {{ $faq->is_active ? 'is-active' : 'is-paused' }}">{{ $faq->is_active ? 'Shown to Buyers' : 'Paused' }}</span>
                        <span class="sl-faq-scope">
                            @if($faq->product)
                                {{ $faq->product->name }}@if($faq->productVariant?->sku) · {{ $faq->productVariant->sku }}@endif
                            @else
                                All products
                            @endif
                        </span>
                    </header>

                    <form class="sl-faq-form sl-faq-edit-form" method="POST" action="{{ route('seller.messages.faqs.update', $faq) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="category" value="{{ $faq->category ?: 'Store information' }}">
                        <input type="hidden" name="sort_order" value="{{ $faq->sort_order }}">
                        <input type="hidden" name="product_id" value="{{ $faq->product_id }}">
                        <input type="hidden" name="product_variant_id" value="{{ $faq->product_variant_id }}">

                        <label class="sl-field">
                            <span>Buyer question</span>
                            <input name="question" value="{{ $faq->question }}" maxlength="300" required>
                        </label>
                        <label class="sl-field">
                            <span>Your answer</span>
                            <textarea name="answer" rows="3" maxlength="3000" required>{{ $faq->answer }}</textarea>
                        </label>
                        <div class="sl-faq-form-actions">
                            <label class="sl-faq-active-toggle"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($faq->is_active)> Show to Buyers</label>
                            <button class="sl-btn sl-btn-primary" type="submit">Save changes</button>
                        </div>
                    </form>

                    @if($faq->is_active)
                        <form class="sl-faq-archive-form" method="POST" action="{{ route('seller.messages.faqs.archive', $faq) }}">
                            @csrf
                            @method('PATCH')
                            <button class="sl-btn sl-btn-ghost" type="submit">Pause this answer</button>
                        </form>
                    @else
                        <p class="sl-faq-paused-note">This answer is hidden from Buyers. Turn it back on above and save to show it again.</p>
                    @endif
                </article>
            @empty
                <div class="sl-card sl-faq-empty">
                    <strong>No suggested answers yet</strong>
                    <p>Add a question and answer above to help Buyers in your chats.</p>
                </div>
            @endforelse
        </div>

        @if($faqs->hasPages())
            <div class="sl-faq-pagination">{{ $faqs->links() }}</div>
        @endif
    </section>
</div>
@endsection
