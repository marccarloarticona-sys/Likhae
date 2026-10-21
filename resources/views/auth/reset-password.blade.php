@php
    $portal = ($portal ?? 'marketplace') === 'logistics' ? 'logistics' : 'marketplace';
    $isLogistics = $portal === 'logistics';
    $accountLabel = $isLogistics ? 'Logistics & Rider Portal' : 'Marketplace';
    $loginRoute = $isLogistics ? route('logistics.login') : route('login');
    $homeRoute = $isLogistics ? route('logistics.home') : url('/');
    $heroImage = asset($isLogistics ? 'images/logistics-login-hero.png' : 'images/login-page-bg.jpg');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <x-shared.likhae-favicon />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>:root { --lk-auth-bg-image: url("{{ $heroImage }}"); }</style>
    <title>Create New Password — LIKHAE</title>
    @vite(['resources/css/auth/login.css', 'resources/js/auth/recovery.js'])
</head>
<body class="{{ $isLogistics ? 'auth-workspace-logistics' : '' }} auth-recovery">
    <div class="auth-shell">
        <section class="auth-hero" aria-labelledby="recovery-hero-title">
            <div class="auth-hero__inner">
                <a href="{{ $homeRoute }}" class="auth-brand">
                    @if ($isLogistics)
                        <span class="likhae-logo likhae-logo--auth likhae-logo--logistics" role="img" aria-label="LIKHAE {{ $accountLabel }}"><img class="likhae-logo__image" src="{{ asset('images/likhae-logo.png') }}" alt="" width="194" height="42" loading="eager" decoding="async" aria-hidden="true"></span>
                    @else
                        <x-shared.likhae-logo context="Marketplace" class="likhae-logo--auth" />
                    @endif
                </a>
                <div class="auth-hero__content"><div class="auth-hero__copy"><span class="auth-eyebrow">{{ $accountLabel }}</span><h1 id="recovery-hero-title">A fresh start,<br><em>made secure.</em></h1><p>Create a new password and return to your LIKHAE workspace.</p></div></div>
                <div class="auth-hero__footer"><ul class="auth-hero__benefits" aria-label="LIKHAE password security features"><li><strong>Strong passwords</strong><span>Built-in security checks</span></li><li><strong>Private access</strong><span>Only you can use your link</span></li><li><strong>Ready to sign in</strong><span>Return to your portal</span></li></ul><p class="auth-hero__tagline">Crafted for everyday needs.</p></div>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-panel__inner">
                <div class="auth-panel-brand">
                    <a href="{{ $homeRoute }}" class="auth-brand auth-brand--panel">
                        @if ($isLogistics)
                            <span class="likhae-logo likhae-logo--auth-panel" role="img" aria-label="LIKHAE {{ $accountLabel }}"><img src="{{ asset('images/likhae-logo-black-text.png') }}" alt="LIKHAE" width="194" height="42" loading="eager" decoding="async"></span>
                        @else
                            <x-shared.likhae-logo context="Marketplace" class="likhae-logo--auth likhae-logo--auth-panel" />
                        @endif
                    </a>
                </div>

                <header class="auth-panel__head">
                    <span class="auth-panel__eyebrow">{{ $accountLabel }}</span>
                    <h2>Create New Password</h2>
                    <p>Enter and confirm your new LIKHAE account password.</p>
                </header>

                @if ($errors->any())
                    <div class="auth-alert auth-alert--error" role="alert" aria-live="polite">
                        @foreach ($errors->all() as $error)<span>{{ $error }}</span>@endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" class="auth-form" data-recovery-form data-recovery-action="reset">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="portal" value="{{ $portal }}">

                    <div class="auth-field {{ $errors->has('email') ? 'has-error' : '' }}">
                        <label for="email">Email address</label>
                        <div class="auth-input-wrap"><span class="auth-input-icon" aria-hidden="true">@</span><input id="email" name="email" type="email" value="{{ old('email', $email) }}" placeholder="Enter your registered email" autocomplete="email" required @class(['is-invalid' => $errors->has('email')])></div>
                        @error('email')<span class="auth-field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="auth-field {{ $errors->has('password') ? 'has-error' : '' }}">
                        <label for="new-password">New password</label>
                        <div class="auth-field__wrap"><span class="auth-input-icon" aria-hidden="true">&#8226;</span><input id="new-password" name="password" type="password" placeholder="Enter new password" autocomplete="new-password" required @class(['is-invalid' => $errors->has('password')])><button type="button" class="auth-eye" data-password-toggle="new-password" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div>
                    </div>

                    <div class="auth-field {{ $errors->has('password_confirmation') ? 'has-error' : '' }}">
                        <label for="password-confirmation">Confirm new password</label>
                        <div class="auth-field__wrap"><span class="auth-input-icon" aria-hidden="true">&#8226;</span><input id="password-confirmation" name="password_confirmation" type="password" placeholder="Confirm new password" autocomplete="new-password" required @class(['is-invalid' => $errors->has('password_confirmation')])><button type="button" class="auth-eye" data-password-toggle="password-confirmation" aria-label="Show password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div>
                    </div>

                    <p class="auth-recovery__hint">Use at least 8 characters with uppercase and lowercase letters and a number.</p>
                    <button type="submit" class="auth-submit" data-loading-text="Resetting password..."><span>Reset Password</span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg></button>
                </form>

                <p class="auth-switch auth-recovery__back"><a href="{{ $loginRoute }}">Back to Login</a></p>
                <p class="auth-switch auth-recovery__new-link"><a href="{{ route('password.request', ['portal' => $portal]) }}">Request a new reset link</a></p>
                <div class="auth-trust"><span>Secure recovery</span><span>Privacy protected</span><span>LIKHAE marketplace</span></div>
            </div>
        </section>
    </div>
</body>
</html>
