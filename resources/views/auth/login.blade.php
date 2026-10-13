<!DOCTYPE html>
<html lang="en">
<head>
    <x-likhae-favicon />
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --lk-auth-bg-image: url("{{ asset('images/login-page-bg.jpg') }}");
        }
    </style>

    <title>Sign In — LIKHAE</title>

    @vite([
        'resources/css/auth/login.css',
        'resources/js/auth/login.js'
    ])
</head>

<body>

    <div class="auth-shell">

        {{-- LEFT PANEL --}}
        <section class="auth-hero" aria-labelledby="auth-hero-title">

            <div class="auth-hero__inner">

                <div class="auth-hero__content">

                    <div class="auth-hero__copy">
                        <span class="auth-eyebrow">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M12 21s7-6 7-12a7 7 0 1 0-14 0c0 6 7 12 7 12Z" />
                                <circle cx="12" cy="9" r="2.5" />
                            </svg>
                            Your Philippine marketplace
                        </span>

                        <h1 id="auth-hero-title">
                            Your local marketplace,
                            <em>reimagined.</em>
                        </h1>

                        <p>
                            Everyday essentials. Unexpected finds.
                            Discover a little more to love from local sellers.
                        </p>
                    </div>

                </div>


                <div class="auth-hero__footer">
                    <ul class="auth-hero__benefits" aria-label="Why shop with LIKHAE">
                        <li>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m3 7 2-4h14l2 4v3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V7Z" />
                                <path d="M5 13v8h14v-8M9 21v-6h6v6M3 7h18" />
                            </svg>
                            <strong>Local finds</strong>
                            <span>Everyday favorites</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M14 17H9M3 17H2V5h12v12h1M14 9h4l4 4v4h-2M14 13h8" />
                                <circle cx="6.5" cy="17.5" r="2.5" />
                                <circle cx="17.5" cy="17.5" r="2.5" />
                            </svg>
                            <strong>Nationwide delivery</strong>
                            <span>Closer to your doorstep</span>
                        </li>
                        <li>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z" />
                                <path d="m8.5 12 2.5 2.5 4.5-5" />
                            </svg>
                            <strong>Secure checkout</strong>
                            <span>Shop with confidence</span>
                        </li>
                    </ul>
                    <p class="auth-hero__tagline">Crafted for everyday needs.</p>
                </div>

            </div>

        </section>


        {{-- RIGHT PANEL --}}
        <section class="auth-panel">

            <div class="auth-panel__inner">

                <div class="auth-panel-brand">

                    <a href="{{ url('/') }}" class="auth-brand auth-brand--panel">
                        <x-likhae-logo context="Marketplace" class="likhae-logo--auth likhae-logo--auth-panel" />

                    </a>

                </div>


                <header class="auth-panel__head">

                    <span class="auth-panel__eyebrow">
                        
                    </span>

                    <h2>
                        Welcome back
                    </h2>

                    <p>
                        Sign in to continue shopping on LIKHAE.
                    </p>

                </header>


                @if ($errors->any())

                    <div class="auth-alert auth-alert--error">

                        @foreach ($errors->all() as $error)

                            <span>
                                {{ $error }}
                            </span>

                        @endforeach

                    </div>

                @endif


                @if (session('status'))

                    <div class="auth-alert auth-alert--success">
                        {{ session('status') }}
                    </div>

                @endif


                <form
                    method="POST"
                    action="{{ route('login.post') }}"
                    class="auth-form"
                >

                    @csrf


                    <div class="auth-field">

                        <label for="email">
                            Email address
                        </label>

                        <div class="auth-input-wrap">

                            <span class="auth-input-icon">
                                @
                            </span>

                            <input
                                id="email"
                                name="email"
                                type="email"
                                value="{{ old('email', request('email')) }}"
                                placeholder="juan@email.com"
                                autocomplete="email"
                                required
                                autofocus
                            >

                        </div>

                    </div>


                    <div class="auth-field">

                        <div class="auth-field__row">

                            <label for="password">
                                Password
                            </label>

                            <a
                                href="{{ route('password.request') }}"
                                class="auth-forgot"
                            >
                                Forgot password?
                            </a>

                        </div>


                        <div class="auth-field__wrap">

                            <span class="auth-input-icon">
                                •
                            </span>

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
                                aria-pressed="false"
                            >

                                <svg
                                    id="eyeOpen"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                >
                                    <path
                                        d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"
                                    />

                                    <circle
                                        cx="12"
                                        cy="12"
                                        r="2.7"
                                    />
                                </svg>


                                <svg
                                    id="eyeClosed"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="hidden"
                                >
                                    <path
                                        d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"
                                    />

                                    <path
                                        d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"
                                    />

                                    <line
                                        x1="1"
                                        y1="1"
                                        x2="23"
                                        y2="23"
                                    />
                                </svg>

                            </button>

                        </div>

                    </div>


                    <div class="auth-form-options">

                        <label class="auth-remember">

                            <input
                                type="checkbox"
                                name="remember"
                                value="1"
                                @checked(old('remember'))
                            >

                            <span>
                                Remember me
                            </span>

                        </label>


                        <span class="auth-security-text">
                            Secure login
                        </span>

                    </div>


                    <button
                        type="submit"
                        class="auth-submit"
                    >

                        <span>
                            Sign In
                        </span>

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path d="M5 12h14" />
                            <path d="M12 5l7 7-7 7" />
                        </svg>

                    </button>

                </form>


                <div class="auth-divider">

                    <span>
                        or continue with
                    </span>

                </div>


                <div class="auth-socials">

                    <a
                        href="{{ url('/auth/google') }}"
                        class="auth-social"
                    >

                        <svg
                            viewBox="0 0 24 24"
                            width="18"
                            height="18"
                        >
                            <path
                                fill="#4285F4"
                                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                            />

                            <path
                                fill="#34A853"
                                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                            />

                            <path
                                fill="#FBBC05"
                                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"
                            />

                            <path
                                fill="#EA4335"
                                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                            />
                        </svg>

                        Google

                    </a>


                </div>


                <p class="auth-switch">

                    Don't have an account?

                    <a href="{{ route('register') }}">
                        Create one — it's free
                    </a>

                </p>

                <p class="auth-switch" style="margin-top: 10px;">
                    <a href="{{ route('logistics.home') }}">Logistics or Rider? Go to the Logistics Portal</a>
                </p>


                <div class="auth-trust">

                    <span>
                        🔒 Secure
                    </span>

                    <span>
                        ✓ Buyer protected
                    </span>

                    <span>
                        🇵🇭 Local marketplace
                    </span>

                </div>

            </div>

        </section>

    </div>

</body>
</html>
