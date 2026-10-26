@php
    $stats = $supportOverview['stats'];
    $requests = $supportOverview['requests'];
    $selected = $supportOverview['selected'];
    $statusLabels = ['NEW' => 'New', 'AWAITING_RESPONSE' => 'Awaiting response', 'IN_PROGRESS' => 'In progress', 'RESOLVED' => 'Resolved'];
    $statusClasses = ['NEW' => 'is-new', 'AWAITING_RESPONSE' => 'is-awaiting', 'IN_PROGRESS' => 'is-progress', 'RESOLVED' => 'is-resolved'];
    $overviewUrl = route($messagesRouteName, array_filter(['section' => 'overview', 'status' => $supportOverview['filter'], 'q' => $supportOverview['search']], fn ($value) => filled($value)));
    $chatsUrl = route($messagesRouteName, ['section' => 'chats']);
    $chatUrl = fn ($conversationId) => route($messagesRouteName, ['section' => 'chats', 'conversation' => $conversationId]);
    $lastMessageTime = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('M j, Y · g:i A') : null;
@endphp
<style>
    .lk-support-overview{display:grid;gap:16px;color:#3b211b}
    .lk-support-overview *{box-sizing:border-box}
    .lk-support-overview-card,.lk-support-detail,.lk-support-queue-card{border:1px solid #ead5c3;border-radius:15px;background:#fffdf9;box-shadow:0 6px 18px rgba(86,28,23,.045)}
    .lk-support-overview-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:17px 19px;border-bottom:1px solid #ead5c3;background:linear-gradient(110deg,#fffaf5,#f4e8dc)}
    .lk-support-overview-head h2{margin:0;font-size:20px;font-weight:800;color:#3b211b}
    .lk-support-overview-head p{margin:5px 0 0;color:#876958;font-size:12px}
    .lk-support-overview-head a{color:#5b2018;font-size:11px;font-weight:800;text-decoration:none}
    .lk-support-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}
    .lk-support-stat{padding:13px;border:1px solid #ead5c3;border-radius:12px;background:#fffdf9}
    .lk-support-stat span{display:block;color:#876958;font-size:10px;font-weight:700}
    .lk-support-stat strong{display:block;margin-top:5px;color:#5b2018;font-size:22px;font-weight:900;line-height:1}
    .lk-support-stat small{display:block;margin-top:5px;color:#987865;font-size:9px}
    .lk-support-faq-performance{padding:14px 16px}
    .lk-support-faq-performance h3{margin:0 0 8px;font-size:13px;font-weight:800}
    .lk-support-faq-performance ul{display:flex;flex-wrap:wrap;gap:7px;margin:0;padding:0;list-style:none}
    .lk-support-faq-performance li{padding:6px 9px;border:1px solid #f0dfd0;border-radius:999px;color:#76594b;background:#fffaf5;font-size:10px}
    .lk-support-filters{display:grid;grid-template-columns:minmax(200px,1fr) 185px auto;gap:8px;padding:12px;border-bottom:1px solid #f0dfd0}
    .lk-support-filters input,.lk-support-filters select{min-width:0;min-height:39px;padding:8px 10px;border:1px solid #dfc4ad;border-radius:9px;background:#fff;color:#3b211b;font:inherit;font-size:11px}
    .lk-support-filters button,.lk-support-action{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 11px;border:1px solid #c19771;border-radius:9px;background:#fff;color:#5b2018;text-decoration:none;font:inherit;font-size:10px;font-weight:800;cursor:pointer}
    .lk-support-filters button.is-primary,.lk-support-action.is-primary{border-color:#5b2018;background:#5b2018;color:#fff}
    .lk-support-queue{display:grid;gap:10px;padding:12px}
    .lk-support-queue-card{padding:13px 14px;box-shadow:none}
    .lk-support-queue-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
    .lk-support-queue-card h3{margin:0;font-size:13px;font-weight:800}
    .lk-support-queue-meta{margin:4px 0;color:#876958;font-size:10px}
    .lk-support-status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:9px;font-weight:900;white-space:nowrap}
    .lk-support-status.is-new{color:#744900;background:#fff0c2}.lk-support-status.is-awaiting{color:#79432a;background:#f8e6d5}.lk-support-status.is-progress{color:#205b47;background:#dff4e8}.lk-support-status.is-resolved{color:#5e5a55;background:#ece9e5}
    .lk-support-snippet{margin:8px 0;color:#51372d;font-size:11px;line-height:1.45;overflow-wrap:anywhere}
    .lk-support-snippet strong{color:#3b211b}
    .lk-support-queue-actions{display:flex;flex-wrap:wrap;gap:7px;margin-top:10px}
    .lk-support-detail{padding:16px}
    .lk-support-detail-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px}
    .lk-support-detail-head h3{margin:0;font-size:16px;font-weight:900}
    .lk-support-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
    .lk-support-detail-block{padding:11px;border:1px solid #f0dfd0;border-radius:11px;background:#fffaf5;min-width:0}
    .lk-support-detail-block h4{margin:0 0 5px;color:#76594b;font-size:9px;text-transform:uppercase;letter-spacing:.08em}
    .lk-support-detail-block p{margin:3px 0;color:#3b211b;font-size:11px;line-height:1.5;white-space:pre-wrap;overflow-wrap:anywhere}
    .lk-support-history{display:grid;gap:7px;margin-top:12px}
    .lk-support-history article{padding:9px 10px;border-left:3px solid #c19771;border-radius:7px;background:#fffaf5}
    .lk-support-history strong{font-size:10px}.lk-support-history p{margin:4px 0;color:#51372d;font-size:10px;line-height:1.45;white-space:pre-wrap}
    .lk-support-empty{padding:24px;border:1px dashed #dfc4ad;border-radius:12px;color:#876958;text-align:center;font-size:12px}
    .lk-support-pagination{padding:0 12px 13px}
    .lk-support-flash{padding:10px 12px;border:1px solid #b9dec8;border-radius:10px;background:#effaf2;color:#256f4a;font-size:11px;font-weight:700}
    @media(max-width:900px){.lk-support-stats{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:640px){.lk-support-overview-head{padding:14px;flex-direction:column}.lk-support-stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.lk-support-stat{padding:10px}.lk-support-stat strong{font-size:19px}.lk-support-filters{grid-template-columns:minmax(0,1fr) auto}.lk-support-filters select{grid-column:1/-1;grid-row:2}.lk-support-filters button{grid-column:2;grid-row:1}.lk-support-detail-grid{grid-template-columns:1fr}.lk-support-queue-top{flex-direction:column}.lk-support-queue-actions>*{flex:1 1 145px}}
</style>
<div class="lk-support-overview">
    @if(session('status'))<div class="lk-support-flash" role="status">{{ session('status') }}</div>@endif
    <section class="lk-support-overview-card">
        <header class="lk-support-overview-head">
            <div><h2>{{ $scopeLabel }} Support Overview</h2><p>Review Buyer concerns here. Opening this page does not mark their messages as read.</p></div>
            @if($faqUrl)<a href="{{ $faqUrl }}">Manage approved FAQs</a>@endif
        </header>
        <div class="lk-support-stats" aria-label="Support statistics">
            <div class="lk-support-stat"><span>New requests</span><strong>{{ $stats['new'] }}</strong><small>Not yet acknowledged</small></div>
            <div class="lk-support-stat"><span>Awaiting response</span><strong>{{ $stats['awaiting_response'] }}</strong><small>Acknowledged, waiting for handling</small></div>
            <div class="lk-support-stat"><span>In progress</span><strong>{{ $stats['in_progress'] }}</strong><small>Accepted by a representative</small></div>
            <div class="lk-support-stat"><span>Resolved</span><strong>{{ $stats['resolved'] }}</strong><small>Explicitly closed by a representative</small></div>
            <div class="lk-support-stat"><span>FAQ helpful</span><strong>{{ $stats['faq_resolved'] }}</strong><small>Buyer marked an answer resolved</small></div>
            <div class="lk-support-stat"><span>FAQ unresolved</span><strong>{{ $stats['faq_unresolved'] }}</strong><small>Feedback only; not a human handoff</small></div>
        </div>
        @if($supportOverview['top_faqs']->isNotEmpty())
            <div class="lk-support-faq-performance"><h3>Most selected FAQs</h3><ul>@foreach($supportOverview['top_faqs'] as $faq)<li>{{ $faq->question }} · {{ number_format($faq->selection_count) }} selected</li>@endforeach</ul></div>
        @endif
    </section>

    @if($selected)
        @php
            $context = $selected->context_snapshot ?? [];
            $activeRequest = in_array($selected->status, [\App\Models\Communication\ConversationSupportRequest::NEW, \App\Models\Communication\ConversationSupportRequest::AWAITING_RESPONSE, \App\Models\Communication\ConversationSupportRequest::IN_PROGRESS], true);
            $requestActionUrls = [
                'acknowledge' => route($supportRequestRouteNames['acknowledge'], $selected),
                'accept' => route($supportRequestRouteNames['accept'], $selected),
                'resolve' => route($supportRequestRouteNames['resolve'], $selected),
            ];
        @endphp
        <section class="lk-support-detail" aria-labelledby="supportRequestDetailTitle">
            <div class="lk-support-detail-head"><div><h3 id="supportRequestDetailTitle">Concern from {{ $selected->requester?->name ?: 'Buyer' }}</h3><p class="lk-support-queue-meta">{{ $selected->category }} · Requested {{ $selected->requested_at?->format('M j, Y · g:i A') }}</p></div><span class="lk-support-status {{ $statusClasses[$selected->status] ?? '' }}">{{ $statusLabels[$selected->status] ?? $selected->status }}</span></div>
            <div class="lk-support-detail-grid">
                <div class="lk-support-detail-block"><h4>Buyer concern</h4><p><strong>{{ $selected->selected_question ?: 'Live assistance requested' }}</strong></p>@if(filled($selected->unresolved_concern))<p>{{ $selected->unresolved_concern }}</p>@endif</div>
                <div class="lk-support-detail-block"><h4>Automated answer</h4>@if(filled($selected->answer_snapshot))<p>{{ $selected->answer_snapshot }}</p><p><small>{{ $selected->answer_source }}</small></p>@else<p>No automatic answer was displayed before the handoff.</p>@endif<p>Buyer feedback: {{ $selected->last_feedback === 'UNRESOLVED' ? 'Still needs help' : ($selected->last_feedback === 'RESOLVED' ? 'Marked helpful' : 'No feedback recorded') }}</p></div>
                <div class="lk-support-detail-block"><h4>Related context</h4>
                    @if(filled(data_get($context, 'product')))<p>Product: {{ data_get($context, 'product') }}</p>@endif
                    @if(filled(data_get($context, 'variant')))<p>Variant: {{ data_get($context, 'variant') }}</p>@endif
                    @if(filled(data_get($context, 'order_number')))<p>Order #{{ data_get($context, 'order_number') }} · {{ str((string) data_get($context, 'order_status'))->replace('_', ' ')->title() }}</p>@endif
                    @if(!empty(data_get($context, 'items')))<p>Items: {{ implode(', ', data_get($context, 'items')) }}</p>@endif
                    @if(empty(data_get($context, 'product')) && empty(data_get($context, 'order_number')))<p>No product or order is linked to this concern.</p>@endif
                </div>
                <div class="lk-support-detail-block"><h4>Request information</h4>
                    <p>Status: {{ $statusLabels[$selected->status] ?? $selected->status }}</p>
                    @if($selected->acknowledged_at)<p>Acknowledged {{ $selected->acknowledged_at->format('M j, Y · g:i A') }} by {{ $selected->acknowledgedBy?->name ?: 'Representative' }}</p>@endif
                    @if($selected->accepted_at)<p>Accepted {{ $selected->accepted_at->format('M j, Y · g:i A') }} by {{ $selected->acceptedBy?->name ?: 'Representative' }}</p>@endif
                    @if($selected->resolved_at)<p>Resolved {{ $selected->resolved_at->format('M j, Y · g:i A') }} by {{ $selected->resolvedBy?->name ?: 'Representative' }}</p>@endif
                    <p>Last Buyer message: {{ $selected->last_buyer_message ?: 'No Buyer message yet' }}</p>
                    @if($selected->last_buyer_message_at)<p><small>{{ $lastMessageTime($selected->last_buyer_message_at) }}</small></p>@endif
                </div>
            </div>
            @if($selected->interactions->isNotEmpty())
                <div class="lk-support-history"><strong>Automated assistance history</strong>
                    @foreach($selected->interactions as $interaction)
                        <article><strong>{{ $interaction->category }} · {{ $interaction->question_snapshot }}</strong><p>{{ $interaction->answer_snapshot }}</p><p>Feedback: {{ $interaction->feedback === 'UNRESOLVED' ? 'Still needs help' : ($interaction->feedback === 'RESOLVED' ? 'Helpful' : 'No feedback') }} · {{ $interaction->created_at?->format('M j, Y · g:i A') }}</p></article>
                    @endforeach
                </div>
            @endif
            <div class="lk-support-queue-actions">
                <a class="lk-support-action" href="{{ $overviewUrl }}">Return to Overview</a>
                @if($selected->status === \App\Models\Communication\ConversationSupportRequest::NEW)
                    <form method="POST" action="{{ $requestActionUrls['acknowledge'] }}">@csrf<button class="lk-support-action" type="submit">Mark awaiting response</button></form>
                @endif
                @if(in_array($selected->status, [\App\Models\Communication\ConversationSupportRequest::NEW, \App\Models\Communication\ConversationSupportRequest::AWAITING_RESPONSE], true))
                    <form method="POST" action="{{ $requestActionUrls['accept'] }}">@csrf<button class="lk-support-action is-primary" type="submit">Accept &amp; Open Live Chat</button></form>
                @else
                    <a class="lk-support-action is-primary" href="{{ $chatUrl($selected->conversation_id) }}">Open Live Chat</a>
                @endif
                @if($activeRequest)<form method="POST" action="{{ $requestActionUrls['resolve'] }}">@csrf<button class="lk-support-action" type="submit">Mark Resolved</button></form>@endif
            </div>
        </section>
    @endif

    <section class="lk-support-overview-card">
        <header class="lk-support-overview-head"><div><h2>Buyer assistance requests</h2><p>FAQ feedback and human support requests are tracked separately.</p></div></header>
        <form class="lk-support-filters" method="GET" action="{{ route($messagesRouteName) }}">
            <input type="hidden" name="section" value="overview">
            <input type="search" name="q" value="{{ $supportOverview['search'] }}" placeholder="Search Buyer, order reference, or concern" aria-label="Search support requests">
            <select name="status" aria-label="Filter by support status">
                <option value="">All requests</option>
                @foreach($statusLabels as $value => $label)<option value="{{ $value }}" @selected($supportOverview['filter'] === $value)>{{ $label }}</option>@endforeach
            </select>
            <button class="is-primary" type="submit">Apply</button>
        </form>
        <div class="lk-support-queue">
            @forelse($requests as $supportRequest)
                @php
                    $reviewUrl = route($messagesRouteName, array_filter(['section' => 'overview', 'request' => $supportRequest->id, 'status' => $supportOverview['filter'], 'q' => $supportOverview['search']], fn ($value) => filled($value)));
                    $actionUrl = route($supportRequestRouteNames['accept'], $supportRequest);
                    $isUnaccepted = in_array($supportRequest->status, [\App\Models\Communication\ConversationSupportRequest::NEW, \App\Models\Communication\ConversationSupportRequest::AWAITING_RESPONSE], true);
                @endphp
                <article class="lk-support-queue-card">
                    <div class="lk-support-queue-top"><div><h3>{{ $supportRequest->requester?->name ?: 'Buyer' }} · {{ $supportRequest->category }}</h3><p class="lk-support-queue-meta">{{ $supportRequest->requested_at?->format('M j, Y · g:i A') }}@if($supportRequest->order_reference) · Order #{{ $supportRequest->order_reference }}@endif</p></div><span class="lk-support-status {{ $statusClasses[$supportRequest->status] ?? '' }}">{{ $statusLabels[$supportRequest->status] ?? $supportRequest->status }}</span></div>
                    @if($supportRequest->selected_question)<p class="lk-support-snippet"><strong>Question:</strong> {{ $supportRequest->selected_question }}</p>@endif
                    @if(filled($supportRequest->answer_snapshot))<p class="lk-support-snippet"><strong>Automatic answer:</strong> {{ \Illuminate\Support\Str::limit($supportRequest->answer_snapshot, 190) }}</p>@endif
                    @if(filled($supportRequest->unresolved_concern))<p class="lk-support-snippet"><strong>Buyer note:</strong> {{ \Illuminate\Support\Str::limit($supportRequest->unresolved_concern, 180) }}</p>@endif
                    @if($supportRequest->last_feedback)<p class="lk-support-queue-meta">Buyer feedback: {{ $supportRequest->last_feedback === 'UNRESOLVED' ? 'Still needs help' : 'Marked helpful before requesting a person' }}</p>@endif
                    @if(filled($supportRequest->last_buyer_message))<p class="lk-support-snippet"><strong>Last Buyer message:</strong> {{ \Illuminate\Support\Str::limit($supportRequest->last_buyer_message, 150) }}</p>@endif
                    @if(filled(data_get($supportRequest->context_snapshot, 'product')))<p class="lk-support-queue-meta">Product: {{ data_get($supportRequest->context_snapshot, 'product') }}@if(filled(data_get($supportRequest->context_snapshot, 'variant'))) · Variant {{ data_get($supportRequest->context_snapshot, 'variant') }}@endif</p>@endif
                    <div class="lk-support-queue-actions">
                        <a class="lk-support-action" href="{{ $reviewUrl }}">Review concern</a>
                        @if($isUnaccepted)<form method="POST" action="{{ $actionUrl }}">@csrf<button class="lk-support-action is-primary" type="submit">Accept &amp; Open Live Chat</button></form>
                        @else<a class="lk-support-action is-primary" href="{{ $chatUrl($supportRequest->conversation_id) }}">Open Live Chat</a>@endif
                    </div>
                </article>
            @empty
                <div class="lk-support-empty">No support requests match these filters.</div>
            @endforelse
        </div>
        <div class="lk-support-pagination">{{ $requests->links() }}</div>
    </section>
</div>
