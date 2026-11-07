@extends('layouts.buyer.app')
@section('title', __('settings.buyer_title'))
@section('active', 'settings')
@push('head')
    @vite('resources/css/Buyer/settings.css')
@endpush

@section('content')
@php
    $value = fn (string $key) => old($key, $preferences[$key] ?? false);
    $supportedLocales = config('app.supported_locales', ['en' => 'English']);
    $selectedLanguage = old('language', $preferences['language'] ?? 'en');
    if (! is_string($selectedLanguage) || ! array_key_exists($selectedLanguage, $supportedLocales)) {
        $selectedLanguage = 'en';
    }
    $soundCatalog = [
        'order_updates', 'delivery_updates', 'chat_updates', 'promotion_updates',
        'earnings_updates', 'warning_updates', 'return_updates', 'general_updates',
    ];
    $notificationOptions = ['order_updates', 'delivery_updates', 'chat_updates', 'promotion_updates'];
@endphp

<div class="lk-settings-page">
    @if(session('buyer_notice'))
        <div class="lk-settings-notice" role="status">{{ session('buyer_notice') }}</div>
    @endif
    @if($errors->any())
        <div class="lk-settings-notice lk-settings-notice--error" role="alert">{{ $errors->first() }}</div>
    @endif

    <header class="lk-settings-hero">
        <div class="lk-settings-hero-copy">
            <span class="lk-settings-eyebrow">{{ __('settings.workspaces.buyer') }}</span>
            <h1>{{ __('settings.buyer_title') }}</h1>
            <p>{{ __('settings.descriptions.buyer') }}</p>
        </div>
        <a href="{{ route('buyer.account') }}" class="lk-settings-account-link">
            <span>{{ __('navigation.items.my_account') }}</span>
            <span aria-hidden="true">→</span>
        </a>
    </header>

    <form method="POST" action="{{ route('buyer.settings.update') }}" data-likhae-settings-form data-ai-settings-url="{{ route('buyer.settings.ai.update') }}">
        @csrf
        @method('PUT')

        <div class="lk-settings-grid">
            <section class="lk-settings-card">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">●</span>
                    <div>
                        <h2>{{ __('settings.account_visibility') }}</h2>
                        <p>{{ __('settings.account_visibility_description') }}</p>
                    </div>
                </header>
                <div class="lk-settings-list">
                    <label class="lk-settings-row">
                        <span class="lk-settings-copy">
                            <strong>{{ __('settings.show_online_status') }}</strong>
                            <small>{{ __('settings.online_status_description') }}</small>
                        </span>
                        <span class="lk-settings-switch">
                            <input type="hidden" name="show_online_status" value="0">
                            <input type="checkbox" name="show_online_status" value="1" role="switch" @checked((bool) $value('show_online_status'))>
                            <i aria-hidden="true"></i>
                        </span>
                    </label>
                </div>
            </section>

            <section class="lk-settings-card">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">✦</span>
                    <div>
                        <h2>{{ __('settings.ai_assistant') }}</h2>
                        <p>{{ __('settings.ai_description') }}</p>
                    </div>
                </header>
                <div class="lk-settings-list">
                    <label class="lk-settings-row">
                        <span class="lk-settings-copy">
                            <strong>{{ __('settings.show_ai') }}</strong>
                            <small>{{ __('settings.show_ai_description', ['workspace' => __('settings.workspaces.buyer')]) }}</small>
                        </span>
                        <span class="lk-settings-control">
                            <span class="lk-settings-switch">
                                <input type="checkbox" name="ai_assistant_enabled" value="1" role="switch" data-ai-setting aria-label="{{ __('settings.show_ai') }}" @checked((bool) $value('ai_assistant_enabled'))>
                                <i aria-hidden="true"></i>
                            </span>
                            <small class="lk-settings-switch-state" data-ai-setting-state>{{ $value('ai_assistant_enabled') ? __('common.labels.on') : __('common.labels.off') }}</small>
                        </span>
                    </label>
                    <label class="lk-settings-row">
                        <span class="lk-settings-copy">
                            <strong>{{ __('settings.ai_response_sound') }}</strong>
                            <small>{{ __('settings.ai_response_sound_description') }}</small>
                        </span>
                        <span class="lk-settings-control">
                            <span class="lk-settings-switch">
                                <input type="checkbox" name="ai_response_sound" value="1" role="switch" data-ai-setting aria-label="{{ __('settings.ai_response_sound') }}" @checked((bool) $value('ai_response_sound'))>
                                <i aria-hidden="true"></i>
                            </span>
                            <small class="lk-settings-switch-state" data-ai-setting-state>{{ $value('ai_response_sound') ? __('common.labels.on') : __('common.labels.off') }}</small>
                        </span>
                    </label>
                </div>
            </section>

            <section class="lk-settings-card lk-settings-card--wide">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">⌁</span>
                    <div>
                        <h2>{{ __('settings.notifications') }}</h2>
                        <p>{{ __('settings.buyer_notifications_intro') }}</p>
                    </div>
                </header>
                <div class="lk-settings-list lk-settings-list--notifications">
                    @foreach($notificationOptions as $key)
                        <label class="lk-settings-row">
                            <span class="lk-settings-copy">
                                <strong>{{ __('settings.buyer_notifications.'.$key.'.label') }}</strong>
                                <small>{{ __('settings.buyer_notifications.'.$key.'.description') }}</small>
                            </span>
                            <span class="lk-settings-switch">
                                <input type="checkbox" name="{{ $key }}" value="1" role="switch" @checked((bool) $value($key))>
                                <i aria-hidden="true"></i>
                            </span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="lk-settings-card lk-settings-card--wide">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">♪</span>
                    <div>
                        <h2>{{ __('settings.sounds') }}</h2>
                        <p>{{ __('settings.buyer_sounds_description') }}</p>
                    </div>
                </header>
                <div class="lk-settings-list lk-settings-sound-list">
                    <label class="lk-settings-row lk-settings-sound-master">
                        <span class="lk-settings-copy">
                            <strong>{{ __('settings.notification_sounds') }}</strong>
                            <small>{{ __('settings.buyer_notification_sounds_description') }}</small>
                        </span>
                        <span class="lk-settings-switch">
                            <input type="checkbox" name="notification_sounds" value="1" role="switch" data-notification-sound-toggle @checked((bool) $value('notification_sounds'))>
                            <i aria-hidden="true"></i>
                        </span>
                    </label>
                    @foreach($soundCatalog as $key)
                        <div class="lk-settings-row lk-settings-sound-row">
                            <span class="lk-settings-copy">
                                <strong>{{ __('settings.buyer_sounds.'.$key.'.label') }}</strong>
                                <small>{{ __('settings.buyer_sounds.'.$key.'.description') }}</small>
                            </span>
                            <button type="button" class="lk-sound-preview" data-notification-preview="{{ $key }}" aria-label="{{ __('common.labels.preview_notification', ['label' => __('settings.buyer_sounds.'.$key.'.label')]) }}">▶ <span>{{ __('settings.preview') }}</span></button>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="lk-settings-card">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">◐</span>
                    <div>
                        <h2>{{ __('settings.appearance') }}</h2>
                        <p>{{ __('settings.appearance_description') }}</p>
                    </div>
                </header>
                <div class="lk-settings-theme-options" role="radiogroup" aria-label="{{ __('common.labels.display_theme') }}">
                    @foreach(['light' => '☀', 'dark' => '◐', 'system' => '◌'] as $key => $icon)
                        <label>
                            <input type="radio" name="theme" value="{{ $key }}" @checked($value('theme') === $key)>
                            <span><b aria-hidden="true">{{ $icon }}</b>{{ __('settings.themes.'.$key) }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section class="lk-settings-card">
                <header class="lk-settings-card-head">
                    <span class="lk-settings-icon" aria-hidden="true">A</span>
                    <div>
                        <h2>{{ __('settings.language') }}</h2>
                        <p>{{ __('settings.language_description') }}</p>
                    </div>
                </header>
                <label class="lk-settings-select-label">
                    <span>{{ __('settings.display_language') }}</span>
                    <select name="language">
                        @foreach($supportedLocales as $localeCode => $localeName)
                            <option value="{{ $localeCode }}" @selected($selectedLanguage === $localeCode)>{{ $localeName }}</option>
                        @endforeach
                    </select>
                </label>
            </section>
        </div>

        <footer class="lk-settings-save">
            <p>{{ __('settings.online_visibility_footer') }}</p>
            <span class="lk-settings-feedback" data-ai-settings-feedback role="status" aria-live="polite" aria-atomic="true"></span>
            <button type="submit">{{ __('common.buttons.save_settings') }}</button>
        </footer>
    </form>
</div>

@push('scripts')
<script>
(() => {
    const messages = @js([
        'on' => __('common.labels.on'),
        'off' => __('common.labels.off'),
        'saving' => __('common.messages.ai_preference_saving'),
        'saved' => __('common.messages.ai_preference_saved'),
        'saveFailed' => __('common.messages.setting_save_failed'),
    ]);
    const form = document.querySelector('[data-likhae-settings-form]');
    if (!form) return;

    const applyTheme = (theme) => {
        if (theme === 'system') localStorage.removeItem('likhae-theme');
        else localStorage.setItem('likhae-theme', theme);
        document.documentElement.dataset.buyerThemePreference = theme;
        document.documentElement.classList.toggle(
            'dark',
            theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches),
        );
        const themeToggle = document.getElementById('adminThemeToggle');
        const dark = document.documentElement.classList.contains('dark');
        themeToggle?.setAttribute('aria-pressed', String(dark));
        const themeState = document.getElementById('adminThemeState') || document.getElementById('themeToggleBadge');
        if (themeState) themeState.textContent = dark ? themeState.dataset.labelOn : themeState.dataset.labelOff;
    };
    form.querySelectorAll('input[name="theme"]').forEach((input) => {
        input.addEventListener('change', () => applyTheme(input.value));
    });
    form.addEventListener('submit', () => {
        applyTheme(form.querySelector('input[name="theme"]:checked')?.value || 'system');
        if (form.querySelector('[data-notification-sound-toggle]')?.checked) {
            window.likhaeEnableNotificationSounds?.();
        }
    });

    const feedback = form.querySelector('[data-ai-settings-feedback]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const savedValues = new Map();
    const generations = new Map();
    const queues = new Map();
    const updateStatus = (input, enabled) => {
        input.checked = enabled;
        const state = input.closest('.lk-settings-row')?.querySelector('[data-ai-setting-state]');
        if (state) state.textContent = enabled ? messages.on : messages.off;
    };
    const emitPreference = (key, enabled) => {
        window.dispatchEvent(new CustomEvent('likhae:buyer-ai-preference', {
            detail: { key, enabled },
        }));
    };

    form.querySelectorAll('[data-ai-setting]').forEach((input) => {
        savedValues.set(input.name, input.checked);
        input.closest('.lk-settings-row')?.querySelector('[data-ai-setting-state]')
            ?.replaceChildren(document.createTextNode(input.checked ? messages.on : messages.off));

        input.addEventListener('change', () => {
            const key = input.name;
            const enabled = input.checked;
            const generation = (generations.get(key) || 0) + 1;
            generations.set(key, generation);
            updateStatus(input, enabled);
            emitPreference(key, enabled);
            if (feedback) feedback.textContent = messages.saving;

            const save = async () => {
                const response = await fetch(form.dataset.aiSettingsUrl, {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ key, enabled }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.key !== key || payload.enabled !== enabled) {
                    const validationMessage = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(validationMessage || payload.message || messages.saveFailed);
                }
                savedValues.set(key, enabled);
                if (generations.get(key) === generation && feedback) feedback.textContent = messages.saved;
            };

            const previous = queues.get(key) || Promise.resolve();
            const queued = previous.catch(() => {}).then(save).catch((error) => {
                if (generations.get(key) === generation) {
                    const restored = savedValues.get(key) || false;
                    updateStatus(input, restored);
                    emitPreference(key, restored);
                    if (feedback) feedback.textContent = error instanceof Error ? error.message : messages.saveFailed;
                }
            });
            queues.set(key, queued);
        });
    });
})();
</script>
@endpush
@endsection
