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
    <form method="POST" action="{{ route('buyer.settings.update') }}" data-likhae-settings-form>@csrf @method('PUT')
        <div class="lk-settings-grid">
            <section class="lk-settings-card lk-settings-card--wide"><header><span class="lk-settings-icon">⌁</span><div><h2>Notifications</h2><p>Choose the Buyer updates you want LIKHAE to send.</p></div></header><div class="lk-settings-list">@foreach ([['order_updates','Order Updates','Order confirmation, processing, and status changes.'],['delivery_updates','Delivery Updates','Pickup, sorting, rider, and delivery updates.'],['chat_updates','Chat Notifications','New messages from the existing Messages feature.'],['promotion_updates','Promotions & Vouchers','Eligible voucher, discount, and promotion updates.']] as [$key, $label, $description])<label class="lk-settings-row"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><span class="lk-settings-switch"><input type="checkbox" name="{{ $key }}" value="1" @checked((bool) $value($key))><i></i></span></label>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">✦</span><div><h2>LIKHAE AI Assistant</h2><p>Control the optional Buyer AI assistant.</p></div></header><div class="lk-settings-list"><label class="lk-settings-row"><span><strong>Show AI Assistant</strong><small>Show the floating AI chat head on Buyer pages.</small></span><span class="lk-settings-switch"><input type="checkbox" name="ai_assistant_enabled" value="1" @checked((bool) $value('ai_assistant_enabled'))><i></i></span></label><label class="lk-settings-row"><span><strong>AI Response Sound</strong><small>Play a subtle sound after the assistant replies.</small></span><span class="lk-settings-switch"><input type="checkbox" name="ai_response_sound" value="1" @checked((bool) $value('ai_response_sound'))><i></i></span></label></div></section>
            <section class="lk-settings-card lk-settings-card--wide"><header><span class="lk-settings-icon">♪</span><div><h2>Sounds</h2><p>Manage LIKHAE interface sound cues.</p></div></header><div class="lk-settings-list lk-settings-sound-list"><label class="lk-settings-row"><span><strong>Notification Sounds</strong><small>Play sounds for supported LIKHAE notifications.</small></span><span class="lk-settings-switch"><input type="checkbox" name="notification_sounds" value="1" data-notification-sound-toggle @checked((bool) $value('notification_sounds'))><i></i></span></label>@foreach($soundCatalog as [$key, $label, $description])<div class="lk-settings-row lk-settings-sound-row"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><button type="button" class="lk-sound-preview" data-notification-preview="{{ $key }}" aria-label="Preview {{ $label }}">▶ Preview</button></div>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">◐</span><div><h2>Appearance</h2><p>Apply your preferred LIKHAE theme.</p></div></header><div class="lk-settings-theme-options">@foreach (['light' => ['☀','Light'], 'dark' => ['◐','Dark'], 'system' => ['◌','System']] as $key => [$icon, $label])<label><input type="radio" name="theme" value="{{ $key }}" @checked($value('theme') === $key)><span><b>{{ $icon }}</b>{{ $label }}</span></label>@endforeach</div></section>
            <section class="lk-settings-card"><header><span class="lk-settings-icon">A</span><div><h2>Language</h2><p>More languages will appear when translations are ready.</p></div></header><label class="lk-settings-select-label">Display language<select name="language"><option value="en" @selected($value('language') === 'en')>English</option></select></label></section>
        </div><footer class="lk-settings-save"><p>Changes apply only to your Buyer account.</p><button type="submit">Save Settings</button></footer>
    </form>
</div>
@push('scripts')<script>(()=>{const f=document.querySelector('[data-likhae-settings-form]');if(!f)return;const a=v=>{if(v==='system')localStorage.removeItem('likhae-theme');else localStorage.setItem('likhae-theme',v);document.documentElement.classList.toggle('dark',v==='dark'||(v==='system'&&matchMedia('(prefers-color-scheme: dark)').matches))};f.querySelectorAll('input[name="theme"]').forEach(i=>i.addEventListener('change',()=>a(i.value)));f.addEventListener('submit',()=>{a(f.querySelector('input[name="theme"]:checked')?.value||'system');if(f.querySelector('[data-notification-sound-toggle]')?.checked)window.likhaeEnableNotificationSounds?.()})})();</script>@endpush
@endsection
