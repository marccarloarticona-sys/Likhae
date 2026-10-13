@props([
    'workspace',
    'page' => 'dashboard',
    'pageTitle' => null,
    'chatUrl' => null,
])

@php
    $workspaceConfig = config("workspace_ai.workspaces.{$workspace}");
    $pageKey = \Illuminate\Support\Str::after($page, $workspace . '.');
    $pageKey = \Illuminate\Support\Str::before($pageKey, '.');
    $pageContext = $workspaceConfig['pages'][$pageKey] ?? $workspaceConfig['pages']['dashboard'];
    $clientConfig = [
        'workspace' => $workspace,
        'assistantName' => $workspaceConfig['assistant_name'],
        'welcome' => $workspaceConfig['welcome'],
        'shortcuts' => $workspaceConfig['shortcuts'],
        'page' => $page,
        'pageTitle' => $pageTitle ?: $pageContext['title'],
    ];
    $aiResponseSound = (bool) data_get(auth()->user()?->notification_preferences, $workspace.'_ai_response_sound', false);
@endphp

<script>window.LIKHAE_WORKSPACE_AI_CONFIG = @json($clientConfig);</script>
<section
    class="likhae-workspace-ai-widget"
    data-likhae-workspace-ai
    data-workspace="{{ $workspace }}"
    data-chat-url="{{ $chatUrl ?: route($workspace . '.ai.chat') }}"
    data-ai-response-sound="{{ $aiResponseSound ? '1' : '0' }}"
>
    <button
        class="likhae-workspace-ai-head"
        type="button"
        data-likhae-workspace-ai-head
        aria-label="Open {{ $workspaceConfig['assistant_name'] }}"
        aria-controls="likhaeWorkspaceAiWindow"
        aria-expanded="false"
        title="{{ $workspaceConfig['assistant_name'] }}"
    >
        <img class="likhae-workspace-ai-head-icon" src="{{ asset('images/buyer/likhae-ai-logo.png') }}" alt="">
        <span class="likhae-workspace-ai-online-dot" aria-hidden="true"></span>
    </button>

    <div
        id="likhaeWorkspaceAiWindow"
        class="likhae-workspace-ai-window"
        role="dialog"
        aria-modal="false"
        aria-label="{{ $workspaceConfig['assistant_name'] }}"
        hidden
    >
        <header class="likhae-workspace-ai-header">
            <img class="likhae-workspace-ai-avatar" src="{{ asset('images/buyer/likhae-ai-logo.png') }}" alt="">
            <div class="likhae-workspace-ai-title">
                <strong>{{ $workspaceConfig['assistant_name'] }}</strong>
                <span><i></i><b data-likhae-workspace-ai-state>Offline</b></span>
            </div>
            <div class="likhae-workspace-ai-window-actions">
                <button type="button" data-likhae-workspace-ai-toggle aria-label="Turn assistant on" aria-pressed="false" title="AI is offline — turn on">&#128683;</button>
                <button type="button" data-likhae-workspace-ai-minimize aria-label="Minimize assistant" title="Minimize">−</button>
                <button type="button" data-likhae-workspace-ai-close aria-label="Close assistant" title="Close">×</button>
            </div>
        </header>

        <div class="likhae-workspace-ai-context" data-likhae-workspace-ai-context>
            {{ $pageTitle ?: $pageContext['title'] }}
        </div>
        <div class="likhae-workspace-ai-messages" data-likhae-workspace-ai-messages aria-live="polite"></div>

        <form class="likhae-workspace-ai-composer" data-likhae-workspace-ai-form>
            <label class="sr-only" for="likhaeWorkspaceAiInput">Ask the workspace assistant</label>
            <textarea id="likhaeWorkspaceAiInput" data-likhae-workspace-ai-input rows="1" maxlength="1000" placeholder="Ask about this workspace…"></textarea>
            <button type="submit" data-likhae-workspace-ai-send aria-label="Send message"><span aria-hidden="true">➤</span></button>
        </form>
    </div>
</section>
