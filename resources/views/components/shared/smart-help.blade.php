@php
    $smartSession = $smartHelp['session'] ?? [];
    $smartStatus = $smartSession['status'] ?? 'AUTOMATED_HELP';
    $humanHandoff = in_array($smartStatus, ['HUMAN_ASSISTANCE_REQUESTED', 'HUMAN_CONVERSATION'], true);
@endphp
<style>
    .lk-smart-help{margin:10px 16px;padding:14px;border:1px solid #ead5c3;border-radius:14px;background:#fffaf5;color:#3c2019;font-size:12px}
    .lk-smart-help-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
    .lk-smart-help-head strong{font-size:13px}
    .lk-smart-help-context,.lk-smart-help-status{margin:4px 0;color:#8a6655}
    .lk-smart-help-questions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
    .lk-smart-help button{border:1px solid #dfc4ad;border-radius:999px;background:#fff;color:#5b2018;padding:7px 10px;font:inherit;font-weight:700;cursor:pointer}
    .lk-smart-help button:hover:not(:disabled){background:#f6e9dd}
    .lk-smart-help button:disabled{opacity:.55;cursor:not-allowed}
    .lk-smart-help-answer{margin-top:10px;padding:11px;border:1px solid #efdfd1;border-radius:10px;background:#fff}
    .lk-smart-help-answer strong{display:block;margin-bottom:4px}
    .lk-smart-help-answer p{margin:4px 0;line-height:1.5;white-space:pre-wrap}
    .lk-smart-help-feedback{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:9px}
    .lk-smart-help-feedback span{color:#76594b}
    .lk-smart-help-error{color:#922e23}
    .lk-smart-help-concern{width:100%;min-height:58px;padding:8px;border:1px solid #dfc4ad;border-radius:9px;background:#fff;color:#3c2019;font:inherit;resize:vertical}
    @media(max-width:700px){.lk-smart-help{margin:8px;padding:11px}.lk-smart-help-questions button{max-width:100%;text-align:left}}
</style>
<section
    class="lk-smart-help"
    data-smart-help
    data-answer-url="{{ route('buyer.messages.smart-help.answer', ['conversation' => $smartHelp['conversation_id']]) }}"
    data-feedback-url="{{ $smartHelp['feedback_url'] }}"
    data-handoff-url="{{ $smartHelp['handoff_url'] }}"
    data-product-id="{{ $smartHelp['product_id'] ?? '' }}"
    data-variant-id="{{ $smartHelp['variant_id'] ?? '' }}"
    data-human-handoff="{{ $humanHandoff ? 'true' : 'false' }}"
>
    <input type="hidden" data-smart-help-token value="{{ csrf_token() }}">
    <div class="lk-smart-help-head">
        <strong>Suggested questions for {{ $smartHelp['recipient_name'] }}</strong>
        <span>Automated help</span>
    </div>
    @if(filled($smartHelp['context_label'] ?? null))<p class="lk-smart-help-context">Context: {{ $smartHelp['context_label'] }}</p>@endif
    <p class="lk-smart-help-status" data-smart-help-status role="status" aria-live="polite">
        @if($humanHandoff)
            Live help was requested. This conversation stays with {{ $smartHelp['recipient_name'] }}.
        @elseif(($smartSession['last_feedback'] ?? null) === 'RESOLVED')
            Glad that helped. Choose another question or continue the conversation below.
        @else
            Choose a question to see a verified or approved answer. Suggested help will not send a chat message.
        @endif
    </p>
    <div class="lk-smart-help-questions" data-smart-help-questions>
        @foreach($smartHelp['suggestions'] ?? [] as $suggestion)
            <button type="button" data-smart-question data-question-key="{{ $suggestion['key'] }}" {{ $humanHandoff ? 'disabled' : '' }}>{{ $suggestion['question'] }}</button>
        @endforeach
    </div>
    <article class="lk-smart-help-answer" data-smart-help-answer @if(empty($smartSession['answer'])) hidden @endif>
        <strong data-smart-help-question>{{ $smartSession['question'] ?? '' }}</strong>
        <p data-smart-help-answer-text>{{ $smartSession['answer'] ?? '' }}</p>
        <small data-smart-help-source>{{ $smartSession['answer_source'] ?? '' }}</small>
    </article>
    <div class="lk-smart-help-feedback" data-smart-help-feedback @if(empty($smartSession['answer']) || $humanHandoff) hidden @endif>
        <span>Did this resolve your question?</span>
        <button type="button" data-smart-feedback="RESOLVED">Yes, thanks</button>
        <button type="button" data-smart-feedback="UNRESOLVED">I still need help</button>
    </div>
    <div class="lk-smart-help-feedback" data-smart-help-handoff-row @if($humanHandoff) hidden @endif>
        <textarea class="lk-smart-help-concern" maxlength="1000" data-smart-concern placeholder="Add a short note for the Seller or Admin (optional)" aria-label="Additional concern for support"></textarea>
        <button type="button" data-smart-handoff>Chat live with {{ $smartHelp['recipient_name'] }}</button>
    </div>
</section>
