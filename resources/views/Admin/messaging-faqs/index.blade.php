@extends('layouts.admin.app')
@section('title', 'Support FAQs')
@section('subtitle', 'Manage the approved answers available in Buyer support conversations.')
@section('active', 'messages')
@section('content')
<div style="max-width:1100px;margin:0 auto;padding:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px">
        <div><h1 style="margin:0;font-size:24px;font-weight:800">Support FAQs</h1><p style="margin:5px 0;color:#876958">Answers are plain text, safely escaped, and shown in the existing Buyer conversation.</p></div>
        <a href="{{ route('admin.messages') }}" style="color:#5b2018;font-weight:800">Back to messages</a>
    </div>
    @if(session('status'))<div role="status" style="padding:12px;margin-bottom:12px;border:1px solid #b9dec8;background:#effaf2;border-radius:10px">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" style="padding:12px;margin-bottom:12px;border:1px solid #e8b4ae;background:#fff1ef;border-radius:10px;color:#8d2d23">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('admin.support-faqs') }}" style="display:flex;gap:8px;margin-bottom:14px"><input type="search" name="q" value="{{ $search }}" placeholder="Search question, answer, or category" style="flex:1;padding:10px;border:1px solid #dfc4ad;border-radius:9px"><button type="submit" style="padding:9px 14px;border:0;border-radius:9px;background:#5b2018;color:#fff;font-weight:800">Search</button></form>
    <section style="padding:18px;margin-bottom:20px;border:1px solid #ead5c3;border-radius:16px;background:#fffaf5">
        <h2 style="margin:0 0 12px;font-size:17px;font-weight:800">Add support answer</h2>
        <form method="POST" action="{{ route('admin.support-faqs.store') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
            @csrf
            <input name="category" value="{{ old('category') }}" placeholder="Category" maxlength="60" required style="padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <input name="sort_order" type="number" min="0" max="1000" value="{{ old('sort_order', 0) }}" aria-label="Display order" style="padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <input name="question" value="{{ old('question') }}" placeholder="Question Buyers may ask" maxlength="300" required style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <textarea name="answer" rows="4" maxlength="3000" placeholder="Approved support answer" required style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">{{ old('answer') }}</textarea>
            <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Enabled</label>
            <button type="submit" style="justify-self:start;padding:10px 16px;border:0;border-radius:9px;background:#5b2018;color:white;font-weight:800">Save answer</button>
        </form>
    </section>
    <section style="display:grid;gap:12px">
        @forelse($faqs as $faq)
            <article style="padding:16px;border:1px solid #ead5c3;border-radius:14px;background:#fff">
                <form method="POST" action="{{ route('admin.support-faqs.update', $faq) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px">
                    @csrf @method('PATCH')
                    <input name="category" value="{{ $faq->category }}" maxlength="60" required aria-label="Category" style="padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <input name="sort_order" type="number" min="0" max="1000" value="{{ $faq->sort_order }}" aria-label="Display order" style="padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <input name="question" value="{{ $faq->question }}" maxlength="300" required style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <textarea name="answer" rows="3" maxlength="3000" required style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">{{ $faq->answer }}</textarea>
                    <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($faq->is_active)> Enabled</label>
                    <button type="submit" style="justify-self:start;padding:9px 13px;border:0;border-radius:8px;background:#5b2018;color:white;font-weight:800">Save changes</button>
                </form>
                <details style="margin-top:10px;color:#76594b"><summary style="cursor:pointer;font-weight:700">Preview in Buyer chat</summary><p><strong>{{ $faq->question }}</strong></p><p style="white-space:pre-wrap">{{ $faq->answer }}</p></details>
                @if($faq->is_active)<form method="POST" action="{{ route('admin.support-faqs.archive', $faq) }}" style="margin-top:9px">@csrf @method('PATCH')<button type="submit" style="padding:7px 11px;border:1px solid #c19771;border-radius:8px;background:#fff;color:#5b2018">Disable</button></form>@else<span style="display:inline-block;margin-top:10px;color:#876958;font-size:12px">Disabled · enable it above to restore.</span>@endif
            </article>
        @empty
            <div style="padding:24px;border:1px dashed #dfc4ad;border-radius:14px;color:#876958">No support FAQs match this search.</div>
        @endforelse
    </section>
    <div style="margin-top:16px">{{ $faqs->links() }}</div>
</div>
@endsection
