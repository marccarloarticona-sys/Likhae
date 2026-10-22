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

    <title>{{ $accountLabel }} Login &mdash; LIKHAE</title>

    @vite([
        'resources/css/auth/login.css',
        'resources/js/auth/login.js'
    ])
</head>
<body class="{{ ($workspace ?? '') === 'logistics' ? 'auth-workspace-logistics' : '' }}" @if (($workspace ?? '') === 'logistics') style="--lk-auth-bg-image: url('{{ asset('images/logistics-login-hero.png') }}')" @endif>
<div class="auth-shell">
    <section class="auth-hero" aria-labelledby="workspace-hero-title">
        <div class="auth-hero__inner">
            <a href="{{ $homeRoute }}" class="auth-brand">
                <span class="likhae-logo likhae-logo--auth likhae-logo--logistics" role="img" aria-label="LIKHAE {{ $accountLabel }}">
                    <img class="likhae-logo__image" src="{{ asset('images/likhae-logo.png') }}" alt="" width="194" height="42" loading="eager" decoding="async" aria-hidden="true">
                </span>
            </a>

            <div class="auth-hero__content">
                <div class="auth-hero__copy">
                    <span class="auth-eyebrow">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 10 12 3l9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V10Z" />
                            <path d="M9 21v-7h6v7M3 10h18" />
                        </svg>
                        {{ $accountLabel }}
                    </span>

                    <h1 id="workspace-hero-title">
                        Every parcel,
                        <em>right on schedule.</em>
                    </h1>

                    <p>Coordinate parcel intake, sorting, and delivery from one workspace built for logistics teams and riders.</p>
                </div>
            </div>

            <div class="auth-hero__footer">
                <ul class="auth-hero__benefits" aria-label="Logistics workspace features">
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m12 3 9 5-9 5-9-5 9-5Z" />
                            <path d="m3 12 9 5 9-5M3 16l9 5 9-5M12 13v8" />
                        </svg>
                        <strong>Parcel intake</strong>
                        <span>Track every handover</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 6h11v12H3zM14 10h4l3 4v4h-7" />
                            <circle cx="7" cy="18" r="2" />
                            <circle cx="18" cy="18" r="2" />
                        </svg>
                        <strong>Rider dispatch</strong>
                        <span>Coordinate local routes</span>
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" />
                            <path d="m8.5 12 2.5 2.5 4.5-5" />
                        </svg>
                        <strong>Delivery updates</strong>
                        <span>Follow each parcel's progress</span>
                    </li>
                </ul>
                <p class="auth-hero__tagline">From parcel intake to the customer&rsquo;s door.</p>
            </div>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-panel__inner">
            <div class="auth-mobile-brand {{ ($workspace ?? '') === 'logistics' ? 'auth-panel-brand auth-logistics-card-brand' : '' }}">
                <a href="{{ $homeRoute }}" class="auth-brand">
                    @if (($workspace ?? '') === 'logistics')
                        <span class="likhae-logo likhae-logo--auth-panel" role="img" aria-label="LIKHAE {{ $accountLabel }}">
                            <img src="{{ asset('images/likhae-logo-black-text.png') }}" alt="" width="194" height="42" loading="eager" decoding="async" aria-hidden="true">
                        </span>
                    @else
                        <x-shared.likhae-logo :context="$accountLabel" class="likhae-logo--auth" />
                    @endif
                </a>
            </div>

            <header class="auth-panel__head">
                <span class="auth-panel__eyebrow">{{ $accountLabel }}</span>
                <h2>Welcome back</h2>
                <p>Sign in to enter your dedicated {{ strtolower($accountLabel) }} workspace.</p>
            </header>

            @if ($errors->any())
                <div class="auth-alert auth-alert--error">
                    @foreach ($errors->all() as $error)
                        <span>{{ $error }}</span>
                    @endforeach
                </div>
            @endif

            @if (session('status'))
                <div class="auth-alert auth-alert--success">
                    {{ session('status') }}
                </div>
            @endif

            <x-auth.recent-accounts :saved-accounts="$savedAccounts ?? collect()" portal="logistics" :show-saved-account-picker="$showSavedAccountPicker ?? false" />

            <form method="POST" action="{{ $loginRoute }}" class="auth-form" data-login-form @if($showSavedAccountPicker ?? false) hidden @endif>
                @csrf

                <div class="auth-selected-account" data-selected-account hidden>
                    <span class="auth-saved-account__avatar" data-selected-account-initial aria-hidden="true"></span>
                    <span class="auth-selected-account__details"><strong data-selected-account-name></strong><span data-selected-account-email></span></span>
                    <button type="button" class="auth-use-another-account" data-use-another-account>Use another account</button>
                </div>

                <div class="auth-field auth-email-field" data-login-email-field>
                    <label for="email">Email address</label>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">@</span>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email', request('email')) }}"
                            placeholder="you@example.com"
                            autocomplete="email"
                            required
                            autofocus
                        >
                    </div>
                </div>

                <div class="auth-field">
                    <div class="auth-field__row">
                        <label for="password">Password</label>
                    </div>

                    <div class="auth-field__wrap">
                        <span class="auth-input-icon">&#8226;</span>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            id="togglePassword"
                            class="auth-eye"
                            aria-label="Show password"
                        >
                            <svg id="eyeOpen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" />
                                <circle cx="12" cy="12" r="2.7" />
                            </svg>

                            <svg id="eyeClosed" class="hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="m3 3 18 18" />
                                <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8" />
                                <path d="M9.9 4.2A10.8 10.8 0 0 1 12 4c6 0 9.5 8 9.5 8a15.8 15.8 0 0 1-2.1 3.2" />
                                <path d="M6.2 6.2C3.7 8 2.5 12 2.5 12s3.5 8 9.5 8a9.8 9.8 0 0 0 4.2-.9" />
                            </svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="auth-submit">
                    <span>Sign in to {{ $accountLabel }}</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path d="M5 12h14"></path>
                        <path d="m13 6 6 6-6 6"></path>
                    </svg>
                </button>
            </form>

            <p class="auth-switch" style="margin-top: 10px;"><a href="{{ route('password.request', ['portal' => 'logistics']) }}">Forgot your password?</a></p>

            <p class="auth-switch">
                Need a {{ strtolower($accountLabel) }} account?
                <a href="{{ $registerRoute }}">Register here</a>
            </p>

            <p class="auth-switch" style="margin-top: 10px;">
                <a href="{{ route('login') }}">Return to marketplace login</a>
            </p>
        </div>
    </section>
</div>

</body>
</html>
