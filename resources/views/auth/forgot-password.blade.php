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
    <title>Forgot Password — LIKHAE</title>
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
                <div class="auth-hero__content">
                    <div class="auth-hero__copy">
                        <span class="auth-eyebrow">{{ $accountLabel }}</span>
                        <h1 id="recovery-hero-title">Your account,<br><em>always within reach.</em></h1>
                        <p>Securely recover your LIKHAE access and get back to what matters.</p>
                    </div>
                </div>
                <div class="auth-hero__footer">
                    <ul class="auth-hero__benefits" aria-label="LIKHAE account security features">
                        <li><strong>Secure recovery</strong><span>Protected reset links</span></li>
                        <li><strong>Account privacy</strong><span>No account details revealed</span></li>
                        <li><strong>Ready when you are</strong><span>Get back to LIKHAE</span></li>
                    </ul>
                    <p class="auth-hero__tagline">Crafted for everyday needs.</p>
                </div>
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
                    <h2>Forgot Password?</h2>
                    <p>Enter your registered email address and we'll send you a link to reset your password.</p>
                </header>

                @if ($errors->any())
                    <div class="auth-alert auth-alert--error" role="alert" aria-live="polite">
                        @foreach ($errors->all() as $error)<span>{{ $error }}</span>@endforeach
                    </div>
                @endif

                @if (session('status'))
                    <div class="auth-alert auth-alert--success" role="status" aria-live="polite">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('password.email') }}" class="auth-form" data-recovery-form data-recovery-action="request">
                    @csrf
                    <input type="hidden" name="portal" value="{{ $portal }}">
                    <div class="auth-field {{ $errors->has('email') ? 'has-error' : '' }}">
                        <label for="email">Email address</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon" aria-hidden="true">@</span>
                            <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="Enter your registered email" autocomplete="email" required autofocus @class(['is-invalid' => $errors->has('email')])>
                        </div>
                        @error('email')<span class="auth-field-error">{{ $message }}</span>@enderror
                    </div>

                    <button type="submit" class="auth-submit" data-loading-text="Sending reset link...">
                        <span>Send Reset Link</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
                    </button>
                </form>

                <p class="auth-switch auth-recovery__back"><a href="{{ $loginRoute }}">Back to Login</a></p>
                <div class="auth-trust"><span>Secure recovery</span><span>Privacy protected</span><span>LIKHAE marketplace</span></div>
            </div>
        </section>
    </div>
</body>
</html>
