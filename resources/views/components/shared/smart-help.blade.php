@php
    $smartSession = $smartHelp['session'] ?? [];
    $smartStatus = $smartSession['status'] ?? 'AUTOMATED_HELP';
    $humanHandoff = in_array($smartStatus, ['HUMAN_ASSISTANCE_REQUESTED', 'HUMAN_CONVERSATION'], true);
    $lastFeedback = $smartSession['last_feedback'] ?? null;
    $handoffLabel = ($smartHelp['recipient_type'] ?? null) === \App\Models\Communication\MessagingFaq::OWNER_ADMIN ? 'Talk to Live Admin' : 'Request live help';
@endphp
<style>
    .lk-smart-help{margin:18px 0 10px;padding:14px;border:1px solid #ead5c3;border-radius:18px;background:rgba(255,250,245,.94);color:#3c2019;font-size:12px}
    .lk-smart-help-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px}
    .lk-smart-help-head strong{font-size:13px}
    .lk-smart-help-context,.lk-smart-help-status{margin:4px 0;color:#8a6655}
    .lk-smart-help-exchange{display:grid;gap:7px}
    .lk-smart-help-question-bubble{justify-self:end;max-width:88%;padding:10px 13px;border-radius:16px 16px 5px 16px;background:#561c17;color:#fff;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
    .lk-smart-help-answer-bubble{justify-self:start;max-width:94%;padding:12px 14px;border:1px solid #efdfd1;border-radius:16px 16px 16px 5px;background:#fff;color:#3c2019;line-height:1.55;white-space:pre-wrap;overflow-wrap:anywhere}
    .lk-smart-help-answer-bubble small{display:block;margin-bottom:5px;color:#8a6655;font-size:10px;font-weight:700}
    .lk-smart-help-answer-bubble p{margin:0}
    .lk-smart-help-answer-bubble .lk-smart-help-feedback-result{margin-top:7px;color:#76594b;font-size:10px}
    .lk-smart-help-questions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
    .lk-smart-help button{border:1px solid #dfc4ad;border-radius:999px;background:#fff;color:#5b2018;padding:7px 10px;font:inherit;font-weight:700;cursor:pointer}
    .lk-smart-help button:hover:not(:disabled){background:#f6e9dd}
    .lk-smart-help button:disabled{opacity:.55;cursor:not-allowed}
    .lk-smart-help-feedback{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:9px}
    .lk-smart-help-feedback span{color:#76594b}
    .lk-smart-help-error{color:#922e23}
    .lk-smart-help-feedback-result{margin:7px 0 0;color:#76594b;font-size:11px}
    .lk-smart-help-ask{display:flex;gap:7px;margin-top:10px}.lk-smart-help-ask input{min-width:0;flex:1;padding:9px 11px;border:1px solid #dfc4ad;border-radius:10px;background:#fff;color:#3c2019;font:inherit}.lk-smart-help-ask button{white-space:nowrap}
    @media(max-width:700px){.lk-smart-help{margin:14px 0 8px;padding:11px}.lk-smart-help-question-bubble,.lk-smart-help-answer-bubble{max-width:96%}.lk-smart-help-questions button{max-width:100%;text-align:left}}
</style>
<section
    class="lk-smart-help"
    data-smart-help
    data-answer-url="{{ $smartHelp['answer_url'] }}"
    data-feedback-url="{{ $smartHelp['feedback_url'] }}"
    data-handoff-url="{{ $smartHelp['handoff_url'] }}"
    data-product-id="{{ $smartHelp['product_id'] ?? '' }}"
    data-variant-id="{{ $smartHelp['variant_id'] ?? '' }}"
    data-human-handoff="{{ $humanHandoff ? 'true' : 'false' }}"
    data-has-answer="{{ filled($smartSession['answer'] ?? null) ? 'true' : 'false' }}"
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
            Glad that helped. Choose another question or continue chatting below.
        @else
            Choose a suggested question. Its verified answer will appear in this conversation.
        @endif
    </p>
    @if(! $humanHandoff)<div class="lk-smart-help-answer-bubble" style="max-width:100%"><small>LIKHAE Support</small><p>{{ $smartHelp['welcome_message'] }}</p></div>@endif
    <div class="lk-smart-help-feedback" data-smart-help-feedback @if(empty($smartSession['answer']) || $humanHandoff || filled($lastFeedback)) hidden @endif>
        <span>Did this answer help?</span>
        <button type="button" data-smart-feedback="RESOLVED">Yes, thanks</button>
        <button type="button" data-smart-feedback="UNRESOLVED">I still need help</button>
    </div>
    <p class="lk-smart-help-feedback-result" data-smart-help-feedback-note @if(!filled($lastFeedback) || $humanHandoff) hidden @endif>
        @if($lastFeedback === 'RESOLVED')Marked helpful.@elseif($lastFeedback === 'UNRESOLVED')Thanks for letting us know. You can request live help in this conversation.@endif
    </p>
    <div class="lk-smart-help-questions" data-smart-help-questions>
        @foreach($smartHelp['suggestions'] ?? [] as $suggestion)
            <button type="button" data-smart-question data-question-key="{{ $suggestion['key'] }}" {{ $humanHandoff ? 'disabled' : '' }}>{{ $suggestion['question'] }}</button>
        @endforeach
    </div>
    @if(! $humanHandoff)
        <form class="lk-smart-help-ask" data-smart-ask-form>
            <input type="text" maxlength="300" data-smart-ask-input placeholder="Ask a question in your own words" aria-label="Ask a support question">
            <button type="submit" data-smart-ask-submit>Ask</button>
        </form>
    @endif
    <div class="lk-smart-help-feedback" data-smart-help-handoff-row @if($humanHandoff) hidden @endif>
        <span>Use the message box below to add details. This keeps the same conversation history.</span>
        <button type="button" data-smart-handoff>{{ $handoffLabel }}</button>
    </div>
</section>
