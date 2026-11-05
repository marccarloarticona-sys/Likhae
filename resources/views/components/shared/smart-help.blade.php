@php
    $smartSession = $smartHelp['session'] ?? [];
    $smartStatus = $smartSession['status'] ?? 'AUTOMATED_HELP';
    $humanHandoff = in_array($smartStatus, ['HUMAN_ASSISTANCE_REQUESTED', 'HUMAN_CONVERSATION'], true);
    $humanConversation = $smartStatus === 'HUMAN_CONVERSATION';
    $lastFeedback = $smartSession['last_feedback'] ?? null;
    $recipientName = $smartHelp['recipient_name'] ?? 'Seller';
    $recipientAvatar = $avatar ?? $smartHelp['recipient_avatar'] ?? asset('images/defaults/profile-avatar.svg');
    $handoffLabel = ($smartHelp['recipient_type'] ?? null) === \App\Models\Communication\MessagingFaq::OWNER_ADMIN ? 'Talk to Live Admin' : 'Request live help';
    $hasAutomatedHistory = ($historyDisplayed ?? false)
        && (! empty($smartHelp['history']) || filled($smartSession['answer'] ?? null));
@endphp
<style>
    .lk-smart-help{margin:12px 0 4px;color:#3c2019;font-size:12px}
    .lk-smart-help-welcome,.lk-smart-help-sender{display:flex;align-items:center;gap:8px}
    .lk-smart-help-welcome{max-width:82%;margin:14px 0 0}
    .lk-smart-help-avatar{width:30px;height:30px;flex:0 0 30px;border-radius:50%;object-fit:cover}
    .lk-smart-help-welcome-copy{min-width:0}
    .lk-smart-help-sender{margin:0 0 5px;color:#76594b;font-size:11px}
    .lk-smart-help-automated-badge{display:inline-flex;align-items:center;border:1px solid #ead5c3;border-radius:999px;padding:2px 7px;background:#fffaf5;color:#8a6655;font-size:9px;font-weight:800;letter-spacing:.02em}
    .lk-smart-help-bubble{max-width:100%;padding:11px 13px}
    .lk-smart-help-bubble small,.lk-smart-help-source{display:block;margin-bottom:4px;color:#8a6655;font-size:10px;font-weight:700}
    .lk-smart-help-bubble p{margin:0}
    .lk-smart-help-live-status{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:12px;color:#76594b;font-size:11px}
    .lk-smart-help-live-status[hidden]{display:none}
    .lk-smart-help-live-dot{width:7px;height:7px;border-radius:50%;background:#3d8c64}
    .lk-smart-help-live-status strong{color:#3c2019}
    .lk-smart-help-live-status small{color:#8a6655}
    .lk-smart-help-status{margin:7px 0 0;color:#8a6655;font-size:11px;line-height:1.45}
    .lk-smart-help-status:empty{display:none}
    .lk-smart-help-status.lk-smart-help-error{color:#922e23}
    .lk-smart-help-tools{margin-top:10px;border-top:1px solid #ead5c3;padding-top:8px}
    .lk-smart-help-tools[hidden]{display:none}
    .lk-smart-help-tools summary{display:flex;align-items:center;gap:7px;width:max-content;max-width:100%;color:#76594b;font-size:11px;cursor:pointer;list-style:none}
    .lk-smart-help-tools summary::-webkit-details-marker{display:none}
    .lk-smart-help-tools summary::after{content:'+';display:inline-grid;width:18px;height:18px;place-items:center;border:1px solid #dfc4ad;border-radius:50%;color:#5b2018;font-size:14px;line-height:1}
    .lk-smart-help-tools[open] summary::after{content:'−'}
    .lk-smart-help-tools summary strong{color:#3c2019}
    .lk-smart-help-context{margin:8px 0;color:#8a6655;font-size:11px}
    .lk-smart-help-questions{display:flex;flex-wrap:wrap;gap:7px;margin-top:8px}
    .lk-smart-help button{border:1px solid #dfc4ad;border-radius:999px;background:#fffaf5;color:#5b2018;padding:7px 10px;font:inherit;font-size:11px;font-weight:700;cursor:pointer}
    .lk-smart-help button:hover:not(:disabled){background:#f6e9dd}
    .lk-smart-help button:disabled{opacity:.55;cursor:not-allowed}
    .lk-smart-help-feedback{display:flex;flex-wrap:wrap;align-items:center;gap:7px;margin-top:9px}
    .lk-smart-help-feedback span{color:#76594b;font-size:11px}
    .lk-smart-help-feedback[hidden],.lk-smart-help-feedback-result[hidden]{display:none}
    .lk-smart-help-feedback-result{margin:7px 0 0;color:#76594b;font-size:11px}
    .lk-smart-help-ask{display:flex;gap:7px;margin-top:9px}
    .lk-smart-help-ask input{min-width:0;flex:1;padding:8px 10px;border:1px solid #dfc4ad;border-radius:10px;background:#fff;color:#3c2019;font:inherit}
    .lk-smart-help-ask button{white-space:nowrap}
    .lk-smart-help-exchange-row{max-width:82%}
    .lk-smart-help-exchange-row .lk-message-bubble{white-space:pre-wrap}
    .lk-smart-help-answer-bubble .lk-smart-help-source{margin:0 0 4px}
    .lk-smart-help-answer-bubble .lk-smart-help-answer-text{margin:0}
    .lk-smart-help-answer-meta{margin-top:6px;color:#8a6655;font-size:10px}
    .lk-smart-help-question-row .lk-message-bubble{white-space:pre-wrap}
    @media(max-width:700px){.lk-smart-help-welcome,.lk-smart-help-exchange-row{max-width:96%}.lk-smart-help-tools summary{width:100%}.lk-smart-help-questions button{max-width:100%;text-align:left}}
</style>
<section
    class="lk-smart-help"
    data-smart-help
    data-answer-url="{{ $smartHelp['answer_url'] }}"
    data-feedback-url="{{ $smartHelp['feedback_url'] }}"
    data-handoff-url="{{ $smartHelp['handoff_url'] }}"
    data-product-id="{{ $smartHelp['product_id'] ?? '' }}"
    data-variant-id="{{ $smartHelp['variant_id'] ?? '' }}"
    data-smart-help-avatar="{{ $recipientAvatar }}"
    data-smart-help-recipient-name="{{ $recipientName }}"
    data-human-handoff="{{ $humanHandoff ? 'true' : 'false' }}"
    data-has-answer="{{ filled($smartSession['answer'] ?? null) ? 'true' : 'false' }}"
>
    <input type="hidden" data-smart-help-token value="{{ csrf_token() }}">

    @if(! $humanHandoff && ! $hasAutomatedHistory)
        <div class="lk-smart-help-welcome" aria-label="Automated welcome from {{ $recipientName }}">
            <img class="lk-message-small-avatar lk-smart-help-avatar" src="{{ $recipientAvatar }}" alt="" loading="lazy" decoding="async">
            <div class="lk-smart-help-welcome-copy">
                <div class="lk-smart-help-sender"><strong>{{ $recipientName }}</strong><span class="lk-smart-help-automated-badge">Automated</span></div>
                <div class="lk-message-bubble lk-smart-help-bubble"><small>Automated help</small><p>{{ $smartHelp['welcome_message'] }}</p></div>
            </div>
        </div>
    @endif

    <div class="lk-smart-help-live-status" data-smart-help-live-status role="status" aria-live="polite" @unless($humanHandoff) hidden @endunless>
        <span class="lk-smart-help-live-dot" aria-hidden="true"></span>
        <strong data-smart-help-live-title>{{ $humanConversation ? 'Live chat with' : 'Live chat requested with' }} {{ $recipientName }}</strong>
        <small data-smart-help-live-description>{{ $humanConversation ? 'Replies appear in this conversation.' : 'Your message stays in this conversation while they join.' }}</small>
    </div>

    <p class="lk-smart-help-status" data-smart-help-status role="status" aria-live="polite" @if($humanHandoff) hidden @endif>
        @if(($smartSession['last_feedback'] ?? null) === 'RESOLVED')
            Glad that helped. Continue chatting or open automated help for another question.
        @else
            Automated answers are labeled in this conversation.
        @endif
    </p>

    @unless($humanHandoff)
    <details class="lk-smart-help-tools" data-smart-help-tools>
        <summary><strong>Automated help</strong><span>Suggested questions and answers</span></summary>
        @if(filled($smartHelp['context_label'] ?? null))<p class="lk-smart-help-context">For this conversation: {{ $smartHelp['context_label'] }}</p>@endif
        <div class="lk-smart-help-feedback" data-smart-help-feedback @if(empty($smartSession['answer']) || $humanHandoff || filled($lastFeedback)) hidden @endif>
            <span>Did the latest automated answer help?</span>
            <button type="button" data-smart-feedback="RESOLVED">Yes, thanks</button>
            <button type="button" data-smart-feedback="UNRESOLVED">I still need help</button>
        </div>
        <p class="lk-smart-help-feedback-result" data-smart-help-feedback-note @if(!filled($lastFeedback) || $humanHandoff) hidden @endif>
            @if($lastFeedback === 'RESOLVED')Marked helpful.@elseif($lastFeedback === 'UNRESOLVED')Thanks for letting us know. You can request live help in this conversation.@endif
        </p>
        <div class="lk-smart-help-questions" data-smart-help-questions>
            @foreach($smartHelp['suggestions'] ?? [] as $suggestion)
                <button type="button" data-smart-question data-question-key="{{ $suggestion['key'] }}">{{ $suggestion['question'] }}</button>
            @endforeach
        </div>
        <form class="lk-smart-help-ask" data-smart-ask-form>
            <input type="text" maxlength="300" data-smart-ask-input placeholder="Ask an automated question" aria-label="Ask an automated question">
            <button type="submit" data-smart-ask-submit>Ask</button>
        </form>
        <div class="lk-smart-help-feedback" data-smart-help-handoff-row>
            <span>Need a person?</span>
            <button type="button" data-smart-handoff>{{ $handoffLabel }}</button>
        </div>
    </details>
    @endunless
</section>
