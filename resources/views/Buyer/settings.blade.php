@extends('layouts.buyer')
@section('title', 'System Settings')
@section('active', 'settings')
@push('head') @vite('resources/css/Buyer/settings.css') @endpush
@section('content')
@if(session('buyer_notice'))<div class="lk-settings-notice" role="status">{{ session('buyer_notice') }}</div>@endif
@if($errors->any())<div class="lk-settings-notice lk-settings-notice--error" role="alert">{{ $errors->first() }}</div>@endif
@php($value = fn (string $key) => old($key, $preferences[$key] ?? false))
@php($soundCatalog = [
    ['order_updates', 'Order tone', 'Order confirmation, processing, and status changes.'],
    ['delivery_updates', 'Delivery tone', 'Pickup, sorting, rider, and delivery updates.'],
    ['chat_updates', 'Message tone', 'New messages from the Messages feature.'],
    ['promotion_updates', 'Promotion tone', 'Vouchers, discounts, and campaign updates.'],
    ['earnings_updates', 'Payment tone', 'Payment, refund, and account balance updates.'],
    ['warning_updates', 'Warning tone', 'Security, failed actions, and urgent alerts.'],
    ['return_updates', 'Return tone', 'Returns, refunds, and dispute updates.'],
    ['general_updates', 'General tone', 'Other supported LIKHAE notifications.'],
])
<div class="lk-settings-page">
    <header class="lk-settings-hero"><div><span>Buyer Center</span><h1>System Settings</h1><p>Fine-tune how LIKHAE looks, sounds, and keeps you informed.</p></div><a href="{{ route('buyer.account') }}" class="lk-settings-account-link">My Account</a></header>
    <form method="POST" action="{{ route('buyer.settings.update') }}" data-likhae-settings-form data-ai-settings-url="{{ route('buyer.settings.ai.update') }}">@csrf @method('PUT')
        <div class="lk-settings-grid">
            <section class="lk-settings-card lk-settings-card--wide"><header><span class="lk-settings-icon" aria-hidden="true">●</span><div><h2>Account Visibility</h2><p>Choose whether other users can see when you are online in Messages.</p></div></header><div class="lk-settings-list"><label class="lk-settings-row"><span><strong>Show Online Status</strong><small>When off, you appear offline to everyone while you continue using LIKHAE.</small></span><span class="lk-settings-switch"><input type="hidden" name="show_online_status" value="0"><input type="checkbox" name="show_online_status" value="1" @checked((bool)$value('show_online_status'))><i></i></span></label></div></section>
            <section class="lk-settings-card lk-settings-card--wide"><header><span class="lk-settings-icon">⌁</span><div><h2>Notifications</h2><p>Choose the Buyer updates you want LIKHAE to send.</p></div></header><div class="lk-settings-list">@foreach ([['order_updates','Order Updates','Order confirmation, processing, and status changes.'],['delivery_updates','Delivery Updates','Pickup, sorting, rider, and delivery updates.'],['chat_updates','Chat Notifications','New messages from the existing Messages feature.'],['promotion_updates','Promotions & Vouchers','Eligible voucher, discount, and promotion updates.']] as [$key, $label, $description])<label class="lk-settings-row"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><span class="lk-settings-switch"><input type="checkbox" name="{{ $key }}" value="1" @checked((bool) $value($key))><i></i></span></label>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">✦</span><div><h2>LIKHAE AI Assistant</h2><p>Control the optional Buyer AI assistant.</p></div></header><div class="lk-settings-list"><label class="lk-settings-row"><span><strong>Show AI Assistant</strong><small>Show the floating AI chat head on Buyer pages.</small></span><span class="lk-settings-control"><span class="lk-settings-switch"><input type="checkbox" name="ai_assistant_enabled" value="1" data-ai-setting aria-label="Show AI Assistant" @checked((bool) $value('ai_assistant_enabled'))><i></i></span><small class="lk-settings-switch-state" data-ai-setting-state>On</small></span></label><label class="lk-settings-row"><span><strong>AI Response Sound</strong><small>Play a subtle sound after the assistant replies.</small></span><span class="lk-settings-control"><span class="lk-settings-switch"><input type="checkbox" name="ai_response_sound" value="1" data-ai-setting aria-label="AI Response Sound" @checked((bool) $value('ai_response_sound'))><i></i></span><small class="lk-settings-switch-state" data-ai-setting-state>Off</small></span></label></div></section>
            <section class="lk-settings-card lk-settings-card--wide"><header><span class="lk-settings-icon">♪</span><div><h2>Sounds</h2><p>Manage LIKHAE interface sound cues.</p></div></header><div class="lk-settings-list lk-settings-sound-list"><label class="lk-settings-row"><span><strong>Notification Sounds</strong><small>Play sounds for supported LIKHAE notifications.</small></span><span class="lk-settings-switch"><input type="checkbox" name="notification_sounds" value="1" data-notification-sound-toggle @checked((bool) $value('notification_sounds'))><i></i></span></label>@foreach($soundCatalog as [$key, $label, $description])<div class="lk-settings-row lk-settings-sound-row"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><button type="button" class="lk-sound-preview" data-notification-preview="{{ $key }}" aria-label="Preview {{ $label }}">▶ Preview</button></div>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">◐</span><div><h2>Appearance</h2><p>Apply your preferred LIKHAE theme.</p></div></header><div class="lk-settings-theme-options">@foreach (['light' => ['☀','Light'], 'dark' => ['◐','Dark'], 'system' => ['◌','System']] as $key => [$icon, $label])<label><input type="radio" name="theme" value="{{ $key }}" @checked($value('theme') === $key)><span><b>{{ $icon }}</b>{{ $label }}</span></label>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">A</span><div><h2>Language</h2><p>More languages will appear when translations are ready.</p></div></header><label class="lk-settings-select-label">Display language<select name="language"><option value="en" @selected($value('language') === 'en')>English</option></select></label></section>
        </div><footer class="lk-settings-save"><p>Your online visibility setting applies across LIKHAE.</p><span class="lk-settings-feedback" data-ai-settings-feedback role="status" aria-live="polite" aria-atomic="true"></span><button type="submit">Save Settings</button></footer>
    </form>
</div>
@push('scripts')
<script>
(() => {
    const form = document.querySelector('[data-likhae-settings-form]');
    if (!form) return;

    const applyTheme = (theme) => {
        if (theme === 'system') localStorage.removeItem('likhae-theme');
        else localStorage.setItem('likhae-theme', theme);
        document.documentElement.classList.toggle(
            'dark',
            theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches),
        );
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
        if (state) state.textContent = enabled ? 'On' : 'Off';
    };
    const emitPreference = (key, enabled) => {
        window.dispatchEvent(new CustomEvent('likhae:buyer-ai-preference', {
            detail: { key, enabled },
        }));
    };

    form.querySelectorAll('[data-ai-setting]').forEach((input) => {
        savedValues.set(input.name, input.checked);
        input.closest('.lk-settings-row')?.querySelector('[data-ai-setting-state]')
            ?.replaceChildren(document.createTextNode(input.checked ? 'On' : 'Off'));

        input.addEventListener('change', () => {
            const key = input.name;
            const enabled = input.checked;
            const generation = (generations.get(key) || 0) + 1;
            generations.set(key, generation);
            updateStatus(input, enabled);
            emitPreference(key, enabled);
            if (feedback) feedback.textContent = 'Saving AI preference…';

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
                    throw new Error(validationMessage || payload.message || 'This setting could not be saved. Please try again.');
                }
                savedValues.set(key, enabled);
                if (generations.get(key) === generation && feedback) feedback.textContent = 'AI preference saved.';
            };

            const previous = queues.get(key) || Promise.resolve();
            const queued = previous.catch(() => {}).then(save).catch((error) => {
                if (generations.get(key) === generation) {
                    const restored = savedValues.get(key) || false;
                    updateStatus(input, restored);
                    emitPreference(key, restored);
                    if (feedback) feedback.textContent = error instanceof Error ? error.message : 'This setting could not be saved. Please try again.';
                }
            });
            queues.set(key, queued);
        });
    });
})();
</script>
@endpush
@endsection
