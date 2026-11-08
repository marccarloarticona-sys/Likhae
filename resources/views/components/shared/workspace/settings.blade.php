@props(['config', 'preferences', 'showHeading' => true])
@php
    $value = fn (string $key) => data_get($preferences, $key);
    $supportedLocales = config('app.supported_locales', ['en' => 'English']);
    $selectedLanguage = old('language', $value('language'));
    if (! is_string($selectedLanguage) || ! array_key_exists($selectedLanguage, $supportedLocales)) {
        $selectedLanguage = 'en';
    }
    $workspaceLabel = __('settings.workspaces.'.$config['workspace']);
@endphp
@if(session('status'))<div class="ws-settings-notice" role="status">{{ session('status') }}</div>@endif
@if(isset($errors) && $errors->any())<div class="ws-settings-notice is-error" role="alert">{{ $errors->first() }}</div>@endif
<div class="ws-settings-page">
    @if($showHeading)
        <header class="ws-settings-hero"><div><span>{{ $workspaceLabel }}</span><h1>{{ __('settings.title') }}</h1><p>{{ __('settings.descriptions.'.$config['workspace']) }}</p></div><a href="{{ route($config['account_route']) }}">{{ __('common.labels.account_settings') }}</a></header>
    @endif
    <form method="POST" action="{{ route($config['update_route']) }}" data-workspace-settings>@csrf @method('PUT')
        <div class="ws-settings-grid">
            <section class="ws-settings-card is-wide"><header><b aria-hidden="true">●</b><div><h2>{{ __('settings.account_visibility') }}</h2><p>{{ __('settings.account_visibility_description') }}</p></div></header><div class="ws-settings-list"><label class="ws-settings-row"><span><strong>{{ __('settings.show_online_status') }}</strong><small>{{ __('settings.online_status_description') }}</small></span><span class="ws-switch"><input type="hidden" name="show_online_status" value="0"><input type="checkbox" name="show_online_status" value="1" @checked((bool)$value('show_online_status'))><i></i></span></label></div></section>
            @if(!empty($config['notifications']))
            <section class="ws-settings-card is-wide"><header><b>⌁</b><div><h2>{{ __('settings.notifications') }}</h2><p>{{ __('settings.notification_description', ['workspace' => $workspaceLabel]) }}</p></div></header><div class="ws-settings-list">
                @foreach($config['notifications'] as $notification)
                    @php
                        $key = $notification[0];
                        $label = __('settings.workspace_notifications.'.$key.'.label');
                        $description = __('settings.workspace_notifications.'.$key.'.description');
                        $enabled = $notification[3] ?? true;
                    @endphp
                    <div class="ws-settings-row"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><span class="ws-settings-row-actions">@if($enabled)<button type="button" class="ws-sound-preview" data-notification-preview="{{ $key }}" aria-label="{{ __('common.labels.preview_notification', ['label' => $label]) }}">▶ {{ __('settings.preview') }}</button><label class="ws-switch"><input type="checkbox" name="{{ $key }}" value="1" @checked((bool)$value($key))><i></i></label>@else<span class="ws-coming-soon" aria-disabled="true">{{ __('common.labels.coming_soon') }}</span>@endif</span></div>
                @endforeach
            </div></section>
            @endif
            @if($config['show_ai'])<section class="ws-settings-card"><header><b>✦</b><div><h2>{{ __('settings.ai_assistant') }}</h2><p>{{ __('settings.ai_description') }}</p></div></header><div class="ws-settings-list"><label class="ws-settings-row"><span><strong>{{ __('settings.show_ai') }}</strong><small>{{ __('settings.show_ai_description', ['workspace' => $workspaceLabel]) }}</small></span><span class="ws-switch"><input type="checkbox" name="{{ $config['ai_key'] }}" value="1" @checked((bool)$value($config['ai_key']))><i></i></span></label><label class="ws-settings-row"><span><strong>{{ __('settings.ai_response_sound') }}</strong><small>{{ __('settings.ai_response_sound_description') }}</small></span><span class="ws-switch"><input type="checkbox" name="{{ $config['ai_sound_key'] }}" value="1" @checked((bool)$value($config['ai_sound_key']))><i></i></span></label></div></section>@endif
            <section class="ws-settings-card"><header><b>♪</b><div><h2>{{ __('settings.sounds') }}</h2><p>{{ __('settings.sounds_description') }}</p></div></header><div class="ws-settings-list"><label class="ws-settings-row"><span><strong>{{ __('settings.notification_sounds') }}</strong><small>{{ __('settings.notification_sounds_description') }}</small></span><span class="ws-settings-row-actions">@if(empty($config['sound_previews']))<button type="button" class="ws-sound-preview" data-notification-preview="general_updates" aria-label="{{ __('common.labels.preview_notification', ['label' => __('settings.notification_sounds')]) }}">▶ {{ __('settings.preview') }}</button>@endif<span class="ws-switch"><input type="checkbox" name="{{ $config['sound_key'] }}" value="1" data-notification-sound-toggle @checked((bool)$value($config['sound_key']))><i></i></span></span></label>@if(!empty($config['sound_previews']))
<div class="ws-sound-type-previews">
    <p>{{ __('settings.sound_type_previews') }}</p>
    <div>
        @foreach($config['sound_previews'] as $type => $labelKey)
            @php($soundLabel = __('settings.admin_sound_previews.'.$labelKey))
            <button type="button" class="ws-sound-preview" data-notification-preview="{{ $type }}" aria-label="{{ __('common.labels.preview_notification', ['label' => $soundLabel]) }}">&#9654; {{ __('settings.preview') }} {{ $soundLabel }}</button>
        @endforeach
    </div>
</div>
@endif</div></section>
            <section class="ws-settings-card"><header><b>◐</b><div><h2>{{ __('settings.appearance') }}</h2><p>{{ __('settings.appearance_description') }}</p></div></header><div class="ws-theme-options">@foreach(['light'=>['☀','Light'],'dark'=>['◐','Dark'],'system'=>['◌','System']] as $key=>[$icon,$label])<label><input type="radio" name="theme" value="{{ $key }}" @checked($value('theme')===$key)><span><em>{{ $icon }}</em>{{ __('settings.themes.'.$key) }}</span></label>@endforeach</div></section>
            @if($config['show_language'] ?? true)
                <section class="ws-settings-card"><header><b>A</b><div><h2>{{ __('settings.language') }}</h2><p>{{ __('settings.language_description') }}</p></div></header><label class="ws-select" for="workspaceLanguage">{{ __('settings.display_language') }}<select id="workspaceLanguage" name="language">@foreach($supportedLocales as $localeCode => $localeName)<option value="{{ $localeCode }}" @selected($selectedLanguage === $localeCode)>{{ $localeName }}</option>@endforeach</select></label></section>
            @endif
        </div><footer class="ws-settings-save"><p>{{ __('settings.online_visibility_footer') }}</p><button type="submit">{{ __('common.buttons.save_settings') }}</button></footer>
    </form>
</div>
@push('scripts')<script>(()=>{const f=document.querySelector('[data-workspace-settings]');if(!f)return;const apply=v=>{if(v==='system')localStorage.removeItem('likhae-theme');else localStorage.setItem('likhae-theme',v);const dark=v==='dark'||(v==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',dark);const badge=document.getElementById('adminThemeState')||document.getElementById('themeToggleBadge');if(badge)badge.textContent=dark?badge.dataset.labelOn:badge.dataset.labelOff};f.querySelectorAll('input[name="theme"]').forEach(i=>i.addEventListener('change',()=>apply(i.value)));f.addEventListener('submit',()=>{apply(f.querySelector('input[name="theme"]:checked')?.value||'system');if(f.querySelector('[data-notification-sound-toggle]')?.checked)window.likhaeEnableNotificationSounds?.()})})();</script>@endpush
