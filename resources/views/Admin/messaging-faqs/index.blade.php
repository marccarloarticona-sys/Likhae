@extends('layouts.admin.app')
@section('title', 'Support FAQs')
@section('subtitle', 'Manage the approved answers available in Buyer support conversations.')
@section('active', 'messages')
@section('content')
@php($supportRoles = ['BUYER' => 'Buyer', 'SELLER' => 'Seller', 'LOGISTICS' => 'Logistics', 'RIDER' => 'Rider'])
<div style="max-width:1100px;margin:0 auto;padding:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px">
        <div><h1 style="margin:0;font-size:24px;font-weight:800">Support FAQs</h1><p style="margin:5px 0;color:#876958">Manage approved answers for Buyer, Seller, Logistics, and Rider support chats.</p></div>
        <a href="{{ route('admin.messages') }}" style="color:#5b2018;font-weight:800">Back to messages</a>
    </div>
    @if(session('status'))<div role="status" style="padding:12px;margin-bottom:12px;border:1px solid #b9dec8;background:#effaf2;border-radius:10px">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert" style="padding:12px;margin-bottom:12px;border:1px solid #e8b4ae;background:#fff1ef;border-radius:10px;color:#8d2d23">{{ $errors->first() }}</div>@endif
    <form method="GET" action="{{ route('admin.support-faqs') }}" style="display:flex;gap:8px;margin-bottom:14px"><input type="search" name="q" value="{{ $search }}" placeholder="Search question, answer, or category" style="flex:1;padding:10px;border:1px solid #dfc4ad;border-radius:9px"><button type="submit" style="padding:9px 14px;border:0;border-radius:9px;background:#5b2018;color:#fff;font-weight:800">Search</button></form>
    <section style="padding:18px;margin-bottom:20px;border:1px solid #ead5c3;border-radius:16px;background:#fffaf5">
        <h2 style="margin:0 0 6px;font-size:17px;font-weight:800">Welcome and fallback messages</h2>
        <p style="margin:0 0 14px;color:#876958;font-size:12px">These messages appear for the selected role. The welcome is shown when support opens; fallback is used when no approved answer matches.</p>
        <form method="POST" action="{{ route('admin.support-faqs.settings') }}" style="display:grid;gap:12px">
            @csrf
            @foreach($supportRoles as $role => $label)
                @php($welcome = $settings->get($role.':WELCOME')?->answer)
                @php($fallback = $settings->get($role.':FALLBACK')?->answer)
                <fieldset style="display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:12px;border:1px solid #ead5c3;border-radius:12px">
                    <legend style="padding:0 6px;font-weight:800">{{ $label }}</legend>
                    <input type="hidden" name="settings[{{ $loop->index }}][role]" value="{{ $role }}">
                    <label style="display:grid;gap:5px;font-size:11px;font-weight:700">Welcome message<textarea name="settings[{{ $loop->index }}][welcome]" maxlength="1200" rows="3" placeholder="Welcome the {{ strtolower($label) }} and explain how support works." style="padding:9px;border:1px solid #dfc4ad;border-radius:8px;font:inherit;font-weight:400">{{ $welcome }}</textarea></label>
                    <label style="display:grid;gap:5px;font-size:11px;font-weight:700">Fallback message<textarea name="settings[{{ $loop->index }}][fallback]" maxlength="1200" rows="3" placeholder="Explain what the user can do when no answer matches." style="padding:9px;border:1px solid #dfc4ad;border-radius:8px;font:inherit;font-weight:400">{{ $fallback }}</textarea></label>
                </fieldset>
            @endforeach
            <button type="submit" style="justify-self:start;padding:10px 16px;border:0;border-radius:9px;background:#5b2018;color:white;font-weight:800">Save chatbot messages</button>
        </form>
    </section>
    <section style="padding:18px;margin-bottom:20px;border:1px solid #ead5c3;border-radius:16px;background:#fff">
        <h2 style="margin:0 0 10px;font-size:17px;font-weight:800">Frequently unanswered questions</h2>
        <p style="margin:0 0 12px;color:#876958;font-size:12px">These are real questions users marked as unresolved. Add an FAQ or keywords to cover them.</p>
        <div style="display:grid;gap:7px">
            @forelse($unansweredQuestions as $question)
                <div style="display:flex;justify-content:space-between;gap:12px;padding:9px 11px;border-radius:9px;background:#fffaf5"><span>{{ $question->question_snapshot }} <small style="color:#876958">· {{ $question->category }}</small></span><strong>{{ $question->attempts }}</strong></div>
            @empty
                <p style="margin:0;color:#876958;font-size:12px">No unresolved chatbot answers have been recorded yet.</p>
            @endforelse
        </div>
    </section>
    <section style="padding:18px;margin-bottom:20px;border:1px solid #ead5c3;border-radius:16px;background:#fffaf5">
        <h2 style="margin:0 0 6px;font-size:17px;font-weight:800">Add support answer</h2>
        <p style="margin:0 0 12px;color:#876958;font-size:12px">Set a role, phrases users may type, and a stable display order. Plain text is escaped in chat.</p>
        <form method="POST" action="{{ route('admin.support-faqs.store') }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
            @csrf
            <select name="target_role" aria-label="Visible to role" style="padding:10px;border:1px solid #dfc4ad;border-radius:9px"><option value="ALL">All roles</option>@foreach($supportRoles as $role => $label)<option value="{{ $role }}" @selected(old('target_role', 'BUYER') === $role)>{{ $label }} only</option>@endforeach</select>
            <input name="category" value="{{ old('category') }}" placeholder="Category" maxlength="60" required style="padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <input name="sort_order" type="number" min="0" max="1000" value="{{ old('sort_order', 0) }}" aria-label="Display order" style="padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <input name="question" value="{{ old('question') }}" placeholder="Question users may ask" maxlength="300" required style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">
            <textarea name="alternate_questions" rows="2" maxlength="4000" placeholder="Alternative question phrases, one per line" style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">{{ old('alternate_questions') }}</textarea>
            <textarea name="keywords" rows="2" maxlength="2000" placeholder="Matching keywords, one per line" style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">{{ old('keywords') }}</textarea>
            <textarea name="answer" rows="4" maxlength="3000" placeholder="Approved support answer" required style="grid-column:1/-1;padding:10px;border:1px solid #dfc4ad;border-radius:9px">{{ old('answer') }}</textarea>
            <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Enabled</label>
            <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_automatic" value="0"><input type="checkbox" name="is_automatic" value="1" checked> Use as automatic answer</label>
            <button type="submit" style="justify-self:start;padding:10px 16px;border:0;border-radius:9px;background:#5b2018;color:white;font-weight:800">Save answer</button>
        </form>
    </section>
    <section style="display:grid;gap:12px">
        @forelse($faqs as $faq)
            <article style="padding:16px;border:1px solid #ead5c3;border-radius:14px;background:#fff">
                <form method="POST" action="{{ route('admin.support-faqs.update', $faq) }}" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px">
                    @csrf @method('PATCH')
                    <select name="target_role" aria-label="Visible to role" style="padding:9px;border:1px solid #dfc4ad;border-radius:8px"><option value="ALL" @selected($faq->target_role === 'ALL')>All roles</option>@foreach($supportRoles as $role => $label)<option value="{{ $role }}" @selected($faq->target_role === $role)>{{ $label }} only</option>@endforeach</select>
                    <input name="category" value="{{ $faq->category }}" maxlength="60" required aria-label="Category" style="padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <input name="sort_order" type="number" min="0" max="1000" value="{{ $faq->sort_order }}" aria-label="Display order" style="padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <input name="question" value="{{ $faq->question }}" maxlength="300" required style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">
                    <textarea name="alternate_questions" rows="2" maxlength="4000" placeholder="Alternative phrases, one per line" style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">{{ implode("\n", $faq->alternate_questions ?? []) }}</textarea>
                    <textarea name="keywords" rows="2" maxlength="2000" placeholder="Keywords, one per line" style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">{{ implode("\n", $faq->keywords ?? []) }}</textarea>
                    <textarea name="answer" rows="3" maxlength="3000" required style="grid-column:1/-1;padding:9px;border:1px solid #dfc4ad;border-radius:8px">{{ $faq->answer }}</textarea>
                    <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($faq->is_active)> Enabled</label>
                    <label style="display:flex;align-items:center;gap:7px"><input type="hidden" name="is_automatic" value="0"><input type="checkbox" name="is_automatic" value="1" @checked($faq->is_automatic)> Use as automatic answer</label>
                    <button type="submit" style="justify-self:start;padding:9px 13px;border:0;border-radius:8px;background:#5b2018;color:white;font-weight:800">Save changes</button>
                </form>
                <details style="margin-top:10px;color:#76594b"><summary style="cursor:pointer;font-weight:700">Preview for {{ $faq->target_role === 'ALL' ? 'all roles' : ($supportRoles[$faq->target_role] ?? $faq->target_role) }}</summary><p><strong>{{ $faq->question }}</strong></p><p style="white-space:pre-wrap">{{ $faq->answer }}</p></details>
                @if($faq->is_active)<form method="POST" action="{{ route('admin.support-faqs.archive', $faq) }}" style="margin-top:9px">@csrf @method('PATCH')<button type="submit" style="padding:7px 11px;border:1px solid #c19771;border-radius:8px;background:#fff;color:#5b2018">Disable</button></form>@else<span style="display:inline-block;margin-top:10px;color:#876958;font-size:12px">Disabled · enable it above to restore.</span>@endif
            </article>
        @empty
            <div style="padding:24px;border:1px dashed #dfc4ad;border-radius:14px;color:#876958">No support FAQs match this search.</div>
        @endforelse
    </section>
    <div style="margin-top:16px">{{ $faqs->links() }}</div>
</div>
@endsection
