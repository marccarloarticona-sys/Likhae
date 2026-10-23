<!DOCTYPE html>
<html lang="en">

<head>
    <x-shared.likhae-favicon />
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --lk-register-bg-image: url("{{ asset(in_array($preselectedRole ?? 'buyer', ['logistics', 'rider'], true) ? 'images/logistics-login-hero.png' : 'images/login-page-bg.jpg') }}");
        }
    </style>

    <title>Create Account — LIKHAE</title>

    @vite([
        'resources/css/auth/register.css',
        'resources/css/shared/mapbox.css',
        'resources/js/auth/register.js',
        'resources/js/shared/address-pin.js'
    ])
</head>

<body>

<div class="register-page">

    {{-- =========================================================
        LEFT PANEL
    ========================================================== --}}
    <aside class="register-sidebar" aria-label="Getting started with LIKHAE">
        <section class="register-side" aria-labelledby="register-hero-title">

            <div class="register-side__inner">

                <div class="register-side__content">

                    @if (in_array($preselectedRole ?? 'buyer', ['logistics', 'rider'], true))
                    <span class="register-eyebrow">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M3 10 12 3l9 7v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V10Z" />
                            <path d="M9 21v-7h6v7M3 10h18" />
                        </svg>
                        Join LIKHAE Logistics
                    </span>

                    <h1 id="register-hero-title">
                        Move every parcel.
                        <em>Make every delivery count.</em>
                    </h1>

                    <p>
                        Help local orders move safely from seller handover through sorting and delivery.
                    </p>
                    @else
                    <span class="register-eyebrow">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3Z" />
                        </svg>
                        Join the LIKHAE community
                    </span>

                    <h1 id="register-hero-title">
                        Good finds.
                        <em>Great beginnings.</em>
                    </h1>

                    <p>
                        Find something you love, or build a store others will.
                        Your LIKHAE journey starts here.
                    </p>
                    @endif

                </div>

                <div class="register-side__footer">
                    @if (in_array($preselectedRole ?? 'buyer', ['logistics', 'rider'], true))
                    <ul class="register-side__features" aria-label="Logistics and rider opportunities">
                        <li class="register-feature">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m12 3 9 5-9 5-9-5 9-5Z" />
                                <path d="m3 12 9 5 9-5M3 16l9 5 9-5M12 13v8" />
                            </svg>
                            <span class="register-feature__label">Logistics teams</span>
                            <strong>Coordinate parcels</strong>
                            <span>Keep each handover moving.</span>
                        </li>
                        <li class="register-feature">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M3 6h11v12H3zM14 10h4l3 4v4h-7" />
                                <circle cx="7" cy="18" r="2" />
                                <circle cx="18" cy="18" r="2" />
                            </svg>
                            <span class="register-feature__label">Riders</span>
                            <strong>Deliver with care</strong>
                            <span>Bring local orders to their door.</span>
                        </li>
                    </ul>
                    <p class="register-side__tagline">A dependable delivery, from pickup to doorstep.</p>
                    @else
                    <ul class="register-side__features" aria-label="Two ways to join LIKHAE">
                        <li class="register-feature">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M5 7h14l1 14H4L5 7Z" />
                                <path d="M9 9V6a3 3 0 0 1 6 0v3" />
                            </svg>
                            <span class="register-feature__label">For buyers</span>
                            <strong>Discover &amp; shop</strong>
                            <span>Find your everyday favorites.</span>
                        </li>
                        <li class="register-feature">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="m3 7 2-4h14l2 4v3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V7Z" />
                                <path d="M5 13v8h14v-8M9 21v-6h6v6M3 7h18" />
                            </svg>
                            <span class="register-feature__label">For sellers</span>
                            <strong>Create &amp; sell</strong>
                            <span>Make your store feel at home.</span>
                        </li>
                    </ul>
                    <p class="register-side__tagline">A place for buyers. A home for sellers.</p>
                    @endif
                </div>

            </div>

        </section>

        <section class="register-prep" aria-labelledby="register-prep-title">
            <header class="register-prep__head">
                <span class="register-prep__eyebrow">A smooth start</span>
                <h2 id="register-prep-title">Before you begin</h2>
                <p>{{ in_array($preselectedRole ?? 'buyer', ['logistics', 'rider'], true) ? 'Keep these handy for your logistics or rider application.' : 'Keep these handy as you create your account.' }}</p>
            </header>

            <ul class="register-prep__list">
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="m3 7 9 6 9-6" />
                    </svg>
                    <div>
                        <strong>Your contact details</strong>
                        <span>Email, mobile number, and home address.</span>
                    </div>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <rect x="2" y="4" width="20" height="16" rx="2" />
                        <circle cx="8" cy="10" r="2" />
                        <path d="M5 16a3 3 0 0 1 6 0M15 9h4M15 13h4" />
                    </svg>
                    <div>
                        <strong>A valid ID</strong>
                        <span>A clear JPG, PNG, or PDF copy for verification.</span>
                    </div>
                </li>
                <li>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="m3 7 2-4h14l2 4v3a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0V7Z" />
                        <path d="M5 13v8h14v-8M9 21v-6h6v6M3 7h18" />
                    </svg>
                    <div>
                        @if (in_array($preselectedRole ?? 'buyer', ['logistics', 'rider'], true))
                            <strong>Application documents</strong>
                            <span>Have any relevant vehicle or service details ready.</span>
                        @else
                            <strong>Planning to sell?</strong>
                            <span>Have your business details and permit ready.</span>
                        @endif
                    </div>
                </li>
            </ul>

            <p class="register-prep__note">Applications are reviewed before approval.</p>
        </section>
    </aside>


    {{-- =========================================================
        RIGHT PANEL
    ========================================================== --}}
    <main class="register-main">

        <div class="register-container">

            <a href="{{ url('/') }}" class="register-brand register-brand--main">
                <x-shared.likhae-logo context="Marketplace" class="likhae-logo--auth" />
            </a>

            {{-- =================================================
                HEADER
            ================================================== --}}
            <header class="register-header">

                <span class="register-header__eyebrow">
                    Account Registration
                </span>

                <h2>
                    Create your account
                </h2>

                <p>
                    Choose your account type and complete each
                    registration step.
                </p>

            </header>


            {{-- =================================================
                ERRORS
            ================================================== --}}
            @if ($errors->any())

                <div class="register-alert register-alert--error">

                    <strong>
                        Please check the following:
                    </strong>

                    <ul>

                        @foreach ($errors->all() as $error)

                            <li>
                                {{ $error }}
                            </li>

                        @endforeach

                    </ul>

                </div>

            @endif


            @if (session('status'))

                <div class="register-alert register-alert--success">
                    {{ session('status') }}
                </div>

            @endif


            {{-- =================================================
                ACCOUNT TYPE
            ================================================== --}}
            <section class="account-type-section">

                <div class="account-type-label">
                    Register as
                </div>


                <div
                    class="account-type-toggle"
                    role="radiogroup"
                    aria-label="Account type"
                >
                    {{-- BUYER --}}
                    <button
                        type="button"
                        class="account-type-option is-active"
                        data-account-type="buyer"
                        aria-pressed="true"
                    >

                        <span class="account-type-option__icon">
                            <svg
                                aria-hidden="true"
                                focusable="false"
                                viewBox="0 0 24 24"
                            >
                                <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"></path>
                                <path d="M3 6h18"></path>
                                <path d="M16 10a4 4 0 0 1-8 0"></path>
                            </svg>
                        </span>


                        <span class="account-type-option__content">

                            <strong>
                                Buyer
                            </strong>

                            <small>
                                Shop products and manage orders
                            </small>

                        </span>


                        <span class="account-type-option__check">
                            ✓
                        </span>

                    </button>


                    {{-- SELLER --}}
                    <button
                        type="button"
                        class="account-type-option"
                        data-account-type="seller"
                        aria-pressed="false"
                    >

                        <span class="account-type-option__icon">
                            <svg
                                aria-hidden="true"
                                focusable="false"
                                viewBox="0 0 24 24"
                            >
                                <path d="m2 7 4.4-4.4A2 2 0 0 1 7.8 2h8.4a2 2 0 0 1 1.4.6L22 7"></path>
                                <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path>
                                <path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"></path>
                                <path d="M2 7h20"></path>
                                <path d="M22 7v3a2 2 0 0 1-2 2 2.7 2.7 0 0 1-2.6-2 2.7 2.7 0 0 1-5.2 0 2.7 2.7 0 0 1-5.2 0A2.7 2.7 0 0 1 4.4 12H4a2 2 0 0 1-2-2V7"></path>
                            </svg>
                        </span>


                        <span class="account-type-option__content">

                            <strong>
                                Seller
                            </strong>

                            <small>
                                Open a store and sell products
                            </small>

                        </span>


                        <span class="account-type-option__check">
                            ✓
                        </span>

                    </button>


                    {{-- LOGISTICS --}}
                    <button
                        type="button"
                        class="account-type-option"
                        data-account-type="logistics"
                        aria-pressed="false"
                    >

                        <span class="account-type-option__icon">
                            <svg
                                aria-hidden="true"
                                focusable="false"
                                viewBox="0 0 24 24"
                            >
                                <path d="M3 21h18"></path>
                                <path d="M5 21V9l7-4 7 4v12"></path>
                                <path d="M9 21v-7h6v7"></path>
                                <path d="M9 11h.01"></path>
                                <path d="M12 11h.01"></path>
                                <path d="M15 11h.01"></path>
                            </svg>
                        </span>


                        <span class="account-type-option__content">

                            <strong>
                                Logistics
                            </strong>

                            <small>
                                Sorting center operations
                            </small>

                        </span>


                        <span class="account-type-option__check">
                            ✓
                        </span>

                    </button>


                    {{-- RIDER --}}
                    <button
                        type="button"
                        class="account-type-option"
                        data-account-type="rider"
                        aria-pressed="false"
                    >

                        <span class="account-type-option__icon">
                            <svg
                                aria-hidden="true"
                                focusable="false"
                                viewBox="0 0 24 24"
                            >
                                <circle cx="5.5" cy="17.5" r="3.5"></circle>
                                <circle cx="18.5" cy="17.5" r="3.5"></circle>
                                <path d="M15 6h2l2 5"></path>
                                <path d="M5.5 17.5 9 11l3 6.5"></path>
                                <path d="M9 11h4l2.5 6.5"></path>
                                <path d="M12 11l3-4"></path>
                            </svg>
                        </span>


                        <span class="account-type-option__content">

                            <strong>
                                Rider
                            </strong>

                            <small>
                                Delivery and pickup services
                            </small>

                        </span>


                        <span class="account-type-option__check">
                            ✓
                        </span>

                    </button>

                </div>

            </section>


            {{-- =================================================
                PROGRESS
            ================================================== --}}
            <section class="registration-progress">

                <div class="progress-top">

                    <div>

                        <span class="progress-label">
                            Registration progress
                        </span>

                        <strong data-progress-title>
                            Personal Information
                        </strong>

                    </div>


                    <span
                        class="progress-counter"
                        data-progress-counter
                    >
                        Step 1 of 4
                    </span>

                </div>


                <div class="progress-track">

                    <div
                        class="progress-fill"
                        data-progress-fill
                    ></div>

                </div>


                <div
                    class="progress-steps"
                    data-progress-steps
                >

                    <button
                        type="button"
                        class="progress-step is-active"
                        data-progress-step="0"
                    >
                        <span>1</span>
                        <small>Personal</small>
                    </button>


                    <button
                        type="button"
                        class="progress-step"
                        data-progress-step="1"
                    >
                        <span>2</span>
                        <small>Contact</small>
                    </button>


                    <button
                        type="button"
                        class="progress-step"
                        data-progress-step="2"
                    >
                        <span>3</span>
                        <small>Address</small>
                    </button>


                    <button
                        type="button"
                        class="progress-step seller-progress-step"
                        data-progress-step="3"
                        data-seller-progress-step
                        hidden
                    >
                        <span>4</span>
                        <small>Business</small>
                    </button>


                    <button
                        type="button"
                        class="progress-step"
                        data-progress-step="verification"
                        data-verification-progress-step
                    >
                        <span data-verification-step-number>
                            4
                        </span>

                        <small>
                            Verification
                        </small>
                    </button>

                </div>

            </section>


            {{-- =================================================
                REGISTRATION FORM
            ================================================== --}}
            @php
                $phpSizeInBytes = static function (string $setting): int {
                    $unit = strtolower(substr(trim($setting), -1));
                    $multiplier = match ($unit) {
                        'g' => 1024 ** 3,
                        'm' => 1024 ** 2,
                        'k' => 1024,
                        default => 1,
                    };

                    return (int) ((float) $setting * $multiplier);
                };
            @endphp
            <form
                method="POST"
                action="{{ route('register.store') }}"
                enctype="multipart/form-data"
                class="register-form"
                id="registrationForm"
                novalidate
                data-php-upload-max-bytes="{{ $phpSizeInBytes((string) ini_get('upload_max_filesize')) }}"
                data-php-post-max-bytes="{{ $phpSizeInBytes((string) ini_get('post_max_size')) }}"
                data-address-base="{{ \Illuminate\Support\Str::beforeLast(route('address.philippines.regions', [], false), '/regions') }}"
            >

                <script type="application/json" id="registration-validation-errors">@json($errors->messages())</script>

                @csrf

                <input type="hidden" id="registration_step" name="registration_step" value="{{ old('registration_step', '') }}">

                <input
                    type="hidden"
                    id="accountType"
                    name="account_type"
                    value="{{ old('account_type', $preselectedRole ?? 'buyer') }}"
                >


                {{-- =================================================
                    STEP 1 — PERSONAL INFORMATION
                ================================================== --}}
                <section
                    class="register-step is-active"
                    data-form-step
                    data-step="personal"
                >

                    <div class="register-section">

                        <div class="register-section__head">

                            <span class="register-section__number">
                                01
                            </span>

                            <div>

                                <h3>
                                    Personal Information
                                </h3>

                                <p>
                                    Tell us about yourself.
                                </p>

                            </div>

                        </div>


                        <div class="register-grid">

                            {{-- FIRST NAME --}}
                            <div class="register-field">

                                <label for="first_name">
                                    First Name
                                    <span>*</span>
                                </label>

                                <input
                                    id="first_name"
                                    name="first_name"
                                    type="text"
                                    minlength="2"
                                    maxlength="50"
                                    data-name-format
                                    value="{{ old('first_name', data_get($googleBuyerRegistration ?? null, 'first_name')) }}"
                                    placeholder="Juan"
                                    autocomplete="given-name"
                                    required
                                >

                            </div>


                            {{-- MIDDLE NAME --}}
                            <div class="register-field">

                                <label for="middle_initial">
                                    Middle Name
                                </label>

                                <input
                                    id="middle_initial"
                                    name="middle_initial"
                                    type="text"
                                    value="{{ old('middle_initial') }}"
                                    maxlength="10"
                                    placeholder="Santos"
                                    autocomplete="additional-name"
                                >

                            </div>


                            {{-- LAST NAME --}}
                            <div class="register-field">

                                <label for="last_name">
                                    Last Name
                                    <span>*</span>
                                </label>

                                <input
                                    id="last_name"
                                    name="last_name"
                                    type="text"
                                    minlength="2"
                                    maxlength="50"
                                    data-name-format
                                    value="{{ old('last_name', data_get($googleBuyerRegistration ?? null, 'last_name')) }}"
                                    placeholder="Dela Cruz"
                                    autocomplete="family-name"
                                    required
                                >

                            </div>


                            {{-- NAME EXTENSION --}}
                            <div class="register-field">

                                <label for="name_extension">
                                    Name Extension / Suffix
                                </label>

                                <input
                                    id="name_extension"
                                    name="name_extension"
                                    type="text"
                                    value="{{ old('name_extension') }}"
                                    maxlength="20"
                                    placeholder="Jr., Sr., III"
                                    autocomplete="honorific-suffix"
                                >

                            </div>


                            {{-- SEX --}}
                            <div class="register-field">

                                <label for="sex">
                                    Sex
                                    <span>*</span>
                                </label>

                                <select
                                    id="sex"
                                    name="sex"
                                    required
                                >

                                    <option value="">
                                        Select sex
                                    </option>

                                    <option
                                        value="male"
                                        {{ old('sex') === 'male' ? 'selected' : '' }}
                                    >
                                        Male
                                    </option>

                                    <option
                                        value="female"
                                        {{ old('sex') === 'female' ? 'selected' : '' }}
                                    >
                                        Female
                                    </option>

                                </select>

                            </div>


                            {{-- BIRTHDAY --}}
                            <div class="register-field">

                                <label for="birthday">
                                    Birthday
                                    <span>*</span>
                                </label>

                                <input
                                    id="birthday"
                                    name="birthday"
                                    type="date"
                                    max="{{ now()->subYears(18)->toDateString() }}"
                                    value="{{ old('birthday') }}"
                                    required
                                >

                            </div>


                            {{-- AGE --}}
                            <div class="register-field">

                                <label for="age">
                                    Age
                                    <span>*</span>
                                </label>

                                <input
                                    id="age"
                                    name="age"
                                    type="number"
                                    min="18"
                                    max="120"
                                    value="{{ old('age') }}"
                                    placeholder="Auto-generated"
                                    readonly
                                    required
                                >

                                <small>
                                    Minimum age: 18 years old. Age is automatically calculated from your birthday.
                                </small>

                            </div>

                        </div>

                    </div>

                </section>


                {{-- =================================================
                    STEP 2 — CONTACT INFORMATION
                ================================================== --}}
                <section
                    class="register-step"
                    data-form-step
                    data-step="contact"
                    hidden
                >

                    <div class="register-section">

                        <div class="register-section__head">

                            <span class="register-section__number">
                                02
                            </span>

                            <div>

                                <h3>
                                    Contact Information
                                </h3>

                                <p>
                                    We'll use these details for
                                    important account updates.
                                </p>

                            </div>

                        </div>


                        <div class="register-grid">

                            {{-- EMAIL --}}
                            @php
                                $registrationEmail = old('email', data_get($googleBuyerRegistration ?? null, 'email'));
                                $sessionEmailVerified = filled($registrationEmail)
                                    && mb_strtolower(trim((string) $registrationEmail)) === request()->session()->get('registration_email_verified');
                            @endphp
                            <div
                                class="register-field"
                                data-registration-email-verification
                                data-send-url="{{ route('register.email-verification.send') }}"
                                data-verify-url="{{ route('register.email-verification.verify') }}"
                            >

                                <label for="email">
                                    Email Address
                                    <span>*</span>
                                </label>

                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    maxlength="255"
                                    value="{{ $registrationEmail }}"
                                    @if(!empty($googleBuyerRegistration) || $sessionEmailVerified) readonly @endif
                                    placeholder="juan@email.com"
                                    autocomplete="email"
                                    required
                                >

                                @if (empty($googleBuyerRegistration) && ! $sessionEmailVerified)
                                    <div class="register-email-verification">
                                        <button type="button" class="register-email-verification__button" data-send-email-code>
                                            Send verification code
                                        </button>
                                        <label for="email_verification_code">Email verification code</label>
                                        <input
                                            id="email_verification_code"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="one-time-code"
                                            maxlength="6"
                                            pattern="[0-9]{6}"
                                            placeholder="6-digit code"
                                            data-email-verification-code
                                            disabled
                                            required
                                        >
                                        <button type="button" class="register-email-verification__button" data-verify-email-code disabled>
                                            Verify email
                                        </button>
                                        <small class="register-email-verification__status" data-email-verification-status aria-live="polite">
                                            Verify your email address before continuing.
                                        </small>
                                    </div>
                                @else
                                    <small class="register-email-verification__status is-success">
                                        {{ !empty($googleBuyerRegistration) ? 'This Google email address is already verified.' : 'Email verified. You can continue your registration.' }}
                                    </small>
                                @endif

                            </div>


                            {{-- CONTACT --}}
                            <div class="register-field">

                                <label for="contact_number">
                                    Contact Number
                                    <span>*</span>
                                </label>


                                <div class="phone-input">

                                    <span class="phone-input__prefix">
                                        +63
                                    </span>


                                    <input
                                        id="contact_number"
                                        name="contact_number"
                                        type="tel"
                                        value="{{ preg_replace('/^(?:\+63|0)/', '', old('contact_number', '')) }}"
                                        placeholder="917 123 4567"
                                        inputmode="numeric"
                                        autocomplete="tel"
                                        maxlength="10"
                                        pattern="9[0-9]{9}"
                                        title="Enter a 10-digit Philippine mobile number beginning with 9."
                                        required
                                    >

                                </div>

                            </div>

                        </div>

                    </div>

                </section>


                {{-- =================================================
                    STEP 3 — ADDRESS
                ================================================== --}}
                <section
                    class="register-step"
                    data-form-step
                    data-step="address"
                    hidden
                >

                    <div class="register-section">

                        <div class="register-section__head">

                            <span class="register-section__number">
                                03
                            </span>

                            <div>

                                <h3>
                                    Address
                                </h3>

                                <p>
                                    Select your Philippine location and
                                    enter your detailed address.
                                </p>

                            </div>

                        </div>


                        <div class="register-grid">

                            {{-- REGION --}}
                            <div class="register-field register-field--full">
                                <label for="region">Region <span>*</span></label>
                                <input type="hidden" name="region_code" value="{{ old('region_code') }}" data-address-region-code>
                                <select id="region" name="region" required data-address-region data-old-value="{{ old('region') }}" data-old-code="{{ old('region_code') }}">
                                    <option value="">Loading regions…</option>
                                </select>
                            </div>

                            {{-- PROVINCE --}}
                            <div class="register-field">
                                <label for="province">Province <span>*</span></label>
                                <input type="hidden" name="province_code" value="{{ old('province_code') }}" data-address-province-code>
                                <select id="province" name="province" required disabled data-address-province data-old-value="{{ old('province') }}" data-old-code="{{ old('province_code') }}">
                                    <option value="">Select province</option>
                                </select>
                            </div>

                            {{-- MUNICIPALITY --}}
                            <div class="register-field">
                                <label for="municipality">Municipality / City <span>*</span></label>
                                <input type="hidden" name="municipality_code" value="{{ old('municipality_code') }}" data-address-municipality-code>
                                <select id="municipality" name="municipality" required disabled data-address-municipality data-old-value="{{ old('municipality') }}" data-old-code="{{ old('municipality_code') }}">
                                    <option value="">Select municipality / city</option>
                                </select>
                            </div>

                            {{-- BARANGAY --}}
                            <div class="register-field">
                                <label for="barangay">Barangay <span>*</span></label>
                                <input type="hidden" name="barangay_code" value="{{ old('barangay_code') }}" data-address-barangay-code>
                                <select id="barangay" name="barangay" required disabled data-address-barangay data-old-value="{{ old('barangay') }}" data-old-code="{{ old('barangay_code') }}">
                                    <option value="">Select barangay</option>
                                </select>
                            </div>

                            {{-- STREET / PUROK --}}
                            <div class="register-field">
                                <label for="street">Street / Purok <span>*</span></label>
                                <input id="street" name="street" type="text"
                                    value="{{ old('street') }}" placeholder="e.g. Mahogany Street / Purok 3" required>
                            </div>

                            {{-- HOUSE NUMBER --}}
                            <div class="register-field">
                                <label for="house_number">House / Unit Number</label>
                                <input id="house_number" name="house_number" type="text"
                                    value="{{ old('house_number') }}" placeholder="e.g. Blk 12 Lot 3">
                            </div>

                            {{-- POSTAL --}}
                            <div class="register-field">
                                <label for="postal_code">Postal Code <span>*</span></label>
                                <input id="postal_code" name="postal_code" type="text" inputmode="numeric"
                                    maxlength="4" pattern="[0-9]{4}" title="Postal code must be exactly 4 digits."
                                    value="{{ old('postal_code') }}" placeholder="Auto-filled after barangay" readonly data-address-postal data-old-value="{{ old('postal_code') }}" required>
                            </div>

                            {{-- LANDMARK --}}
                            <div class="register-field">
                                <label for="landmark">Building / Subdivision, Landmark / Additional Details</label>
                                <input id="landmark" name="landmark" type="text"
                                    value="{{ old('landmark') }}" placeholder="e.g. Near barangay hall">
                            </div>

                            <div class="register-field register-field--full">
                                <x-shared.address-pin height="240px" />
                            </div>

                        </div>

                    </div>

                </section>


                {{-- =================================================
                    SELLER STEP — BUSINESS INFORMATION
                ================================================== --}}
                <section
                    class="register-step"
                    id="sellerSection"
                    data-form-step
                    data-step="business"
                    data-seller-step
                    hidden
                >

                    <div class="register-section seller-section">

                        <div class="register-section__head">

                            <span class="register-section__number">
                                04
                            </span>

                            <div>

                                <h3>
                                    Business Information
                                </h3>

                                <p>
                                    Tell us about your LIKHAE store.
                                </p>

                            </div>

                        </div>


                        <div class="register-grid">

                            {{-- BUSINESS NAME --}}
                            <div class="register-field">

                                <label for="business_name">
                                    Business Name
                                    <span>*</span>
                                </label>

                                <input
                                    id="business_name"
                                    name="business_name"
                                    type="text"
                                    maxlength="{{ old('account_type', $preselectedRole ?? 'buyer') === 'seller' ? 30 : 200 }}"
                                    value="{{ old('business_name') }}"
                                    placeholder="Juan's General Store"
                                    data-seller-required
                                >

                            </div>


                            {{-- BUSINESS CATEGORY --}}
                            <div class="register-field">

                                <label for="line_of_business">
                                    Line of Business
                                    <span>*</span>
                                </label>

                                <select
                                    id="line_of_business"
                                    name="line_of_business"
                                    data-seller-required
                                >

                                    <option value="">
                                        Select main business category
                                    </option>


                                    @foreach($sellerLineOfBusinessCategories ?? collect() as $category)

                                        <option
                                            value="{{ $category->id }}"
                                            {{ (string) old('line_of_business') === (string) $category->id ? 'selected' : '' }}
                                        >
                                            {{ $category->name }}
                                        </option>

                                    @endforeach

                                </select>

                            </div>

                        </div>
                        <div class="register-grid" style="margin-top: 14px;">
                            <div class="register-field" data-seller-type-field hidden>
                                <label for="seller_type">Seller Type <span>*</span></label>
                                <select id="seller_type" name="seller_type">
                                    <option value="">Select seller type</option>
                                    <option value="individual" @selected(old('seller_type') === 'individual')>Individual</option>
                                    <option value="sole_proprietorship" @selected(old('seller_type') === 'sole_proprietorship')>Sole Proprietorship</option>
                                    <option value="corporation" @selected(old('seller_type') === 'corporation')>Corporation</option>
                                </select>
                            </div>
                            <div class="register-field" data-business-id-field hidden>
                                <label for="tin">TIN <span>*</span></label>
                                <input id="tin" name="tin" value="{{ old('tin') }}" maxlength="15" placeholder="000-000-000-000" pattern="[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}" title="Enter 12 digits in 000-000-000-000 format." data-seller-required>
                            </div>
                            <div class="register-field" data-logistics-field hidden>
                                <label for="business_registration_number">SEC Registration Number <span>*</span></label>
                                <input id="business_registration_number" name="business_registration_number" value="{{ old('business_registration_number') }}" maxlength="11" pattern="[A-Za-z]{2}[0-9]{9}" title="Enter two letters followed by nine digits." data-logistics-required>
                            </div>
                        </div>

                    </div>

                </section>


                <section class="register-step" data-form-step data-step="rider-vehicle" data-rider-step hidden>
                    <div class="register-section">
                        <div class="register-section__head">
                            <span class="register-section__number">04</span>
                            <div><h3>Vehicle Information</h3><p>Enter the vehicle you will use for deliveries.</p></div>
                        </div>
                        <div class="register-grid">
                            <div class="register-field">
                                <label for="vehicle_type">Vehicle Type <span>*</span></label>
                                <select id="vehicle_type" name="vehicle_type" data-rider-required>
                                    <option value="">Select vehicle type</option>
                                    @foreach(\App\Support\RiderVehicleType::registrationOptions() as $value => $label)
                                        <option value="{{ $value }}" @selected(old('vehicle_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <small class="register-field__help">Motorcycle and Tricycle are for local barangay pickup and delivery. Car, Van, Closed Van, and Truck are for hub-to-hub transfers; transfer capacity must be approved by the logistics hub.</small>
                            </div>
                            <div class="register-field">
                                <label for="plate_number">Plate Number <span>*</span></label>
                                <input id="plate_number" name="plate_number" value="{{ old('plate_number') }}" maxlength="30" data-rider-required>
                            </div>
                            <div class="register-field">
                                <label for="target_logistics_center_id">Logistics / Sorting Center for your municipality <span>*</span></label>
                                <select id="target_logistics_center_id" name="target_logistics_center_id" aria-describedby="logistics-center-status" data-rider-required>
                                    <option value="">Choose logistics center</option>
                                    @foreach($logisticsCenters ?? collect() as $center)
                                        <option
                                            value="{{ $center->id }}"
                                            data-logistics-center-option="true"
                                            data-province-code="{{ $center->address?->province_code }}"
                                            data-municipality-code="{{ $center->address?->municipality_code }}"
                                            @selected((string) old('target_logistics_center_id') === (string) $center->id)
                                        >{{ $center->business_name }} ({{ $center->code }})</option>
                                    @endforeach
                                </select>
                                <small id="logistics-center-status" class="register-field__help" data-logistics-center-status aria-live="polite">Select your province and municipality to see eligible active logistics centers.</small>
                            </div>
                            <div class="register-field">
                                <label for="drivers_license_number">Driver's License Number <span>*</span></label>
                                <input id="drivers_license_number" name="drivers_license_number" value="{{ old('drivers_license_number') }}" maxlength="100" data-rider-required>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- =================================================
                    PROFILE PICTURE
                ================================================== --}}
                <section class="register-step" data-form-step data-step="profile-picture" hidden>
                    <div class="register-section">
                        <div class="register-section__head">
                            <span class="register-section__number">
                                <span data-profile-picture-number>05</span>
                            </span>
                            <div>
                                <h3>Profile Picture</h3>
                                <p>Add a profile picture to personalize your account (optional).</p>
                            </div>
                        </div>

                        <div class="profile-picture-container">
                            <div class="profile-picture-preview">
                                <img id="profilePicturePreview" @if(data_get($googleBuyerRegistration ?? null, 'profile_photo_url')) src="{{ data_get($googleBuyerRegistration, 'profile_photo_url') }}" @else src="#" @endif alt="Profile Preview" style="{{ data_get($googleBuyerRegistration ?? null, 'profile_photo_url') ? '' : 'display: none;' }}">
                                    <div id="profilePictureDefault" class="profile-picture-default" @if(data_get($googleBuyerRegistration ?? null, 'profile_photo_url')) style="display: none;" @endif>
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <circle cx="12" cy="8" r="4"/>
                                        <path d="M4 20c0-4 3.5-7 8-7s8 3 8 7"/>
                                    </svg>
                                </div>
                            </div>

                            <div class="upload-box profile-upload">
                                <input
                                    id="profile_picture"
                                    name="profile_picture"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    data-file-input
                                >
                                <label for="profile_picture" class="upload-box__label">
                                    <span class="upload-box__icon">↑</span>
                                    <strong>Upload Profile Picture</strong>
                                    <span>JPG, PNG or WEBP (Max 5 MB)</span>
                                    <small data-file-name>No file selected</small>
                                </label>
                            </div>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: center;">
                            <p style="color: #666; font-size: 14px; margin-bottom: 0.5rem;">
                                You can add your profile picture later from your account settings.
                            </p>
                        </div>
                        <div data-seller-branding hidden style="margin-top: 28px; padding-top: 22px; border-top: 1px solid #eadbce;">
                            <h3>Shop photos</h3>
                            <p>Add a shop icon and cover photo. You can adjust the crop later in your Shop settings.</p>
                            <div class="register-grid">
                                <div class="register-field"><label for="store_avatar">Shop profile picture</label><input id="store_avatar" type="file" name="store_avatar" accept="image/jpeg,image/png,image/webp" data-store-photo="avatar"><small>Square image · max 5 MB</small><img data-store-photo-preview="avatar" alt="Shop profile preview" hidden style="width:76px;height:76px;object-fit:cover;border-radius:16px"><label>Zoom <input type="range" min="100" max="200" value="100" data-store-photo-zoom="avatar"></label><label>Horizontal crop <input type="range" min="0" max="100" value="50" data-store-photo-x="avatar"></label><label>Vertical crop <input type="range" min="0" max="100" value="50" data-store-photo-y="avatar"></label></div>
                                <div class="register-field"><label for="store_banner">Shop cover photo</label><input id="store_banner" type="file" name="store_banner" accept="image/jpeg,image/png,image/webp" data-store-photo="banner"><small>Wide cover image · max 8 MB</small><img data-store-photo-preview="banner" alt="Shop cover preview" hidden style="width:100%;height:76px;object-fit:cover;border-radius:12px"><label>Zoom <input type="range" min="100" max="200" value="100" data-store-photo-zoom="banner"></label><label>Horizontal crop <input type="range" min="0" max="100" value="50" data-store-photo-x="banner"></label><label>Vertical crop <input type="range" min="0" max="100" value="50" data-store-photo-y="banner"></label></div>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- =================================================
                    FINAL STEP — VERIFICATION
                ================================================== --}}
                <section
                    class="register-step"
                    data-form-step
                    data-step="verification"
                    hidden
                >

                    <div class="register-section">

                        <div class="register-section__head">

                            <span class="register-section__number">
                                <span data-verification-number>
                                    04
                                </span>
                            </span>


                            <div>

                                <h3>
                                    Account Verification
                                </h3>

                                <p>
                                    Upload the documents required for
                                    your account type.
                                </p>

                            </div>

                        </div>


                        <div class="upload-grid">

                            {{-- VALID ID --}}
                            <div class="upload-box">

                                <input
                                    id="valid_id"
                                    name="valid_id"
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    required
                                    data-file-input
                                >


                                <label
                                    for="valid_id"
                                    class="upload-box__label"
                                >

                                    <span class="upload-box__icon">
                                        ↑
                                    </span>

                                    <strong>
                                        Upload Valid ID
                                    </strong>

                                    <span>
                                        JPG, JPEG, PNG, WEBP or PDF, up to 5 MB
                                    </span>

                                    <small data-file-name>
                                        No file selected
                                    </small>

                                </label>

                            </div>


                            {{-- SELLER BUSINESS PERMIT --}}
                            <div
                                class="upload-box seller-upload"
                                id="businessPermitUpload"
                                hidden
                            >

                                <input
                                    id="business_permit"
                                    name="business_permit"
                                    type="file"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    data-file-input
                                    data-logistics-required
                                >


                                <label
                                    for="business_permit"
                                    class="upload-box__label"
                                >

                                    <span class="upload-box__icon">
                                        ↑
                                    </span>

                                    <strong>
                                        Upload Business Permit
                                    </strong>

                                    <span>
                                        JPG, JPEG, PNG, WEBP or PDF, up to 5 MB
                                    </span>

                                    <small data-file-name>
                                        No file selected
                                    </small>

                                </label>

                            </div>

                        </div>

                        <div class="register-grid" data-seller-documents hidden>
                            @foreach(['dti_certificate' => 'DTI Certificate', 'sec_certificate' => 'SEC Certificate', 'bir_form_2303' => 'BIR Form 2303'] as $field => $label)
                                <div class="upload-box" data-seller-document="{{ $field }}">
                                    <input id="{{ $field }}" name="{{ $field }}" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" data-file-input>
                                    <label for="{{ $field }}" class="upload-box__label"><strong>Upload {{ $label }}</strong><span>JPG, PNG, WEBP or PDF, up to 5 MB</span><small data-file-name>No file selected</small></label>
                                </div>
                            @endforeach
                        </div>

                        <div class="register-grid" id="riderVehicleSection" hidden>
                            @foreach(['or_cr' => 'Vehicle OR / CR', 'drivers_license' => "Driver's License"] as $field => $label)
                                <div class="upload-box">
                                    <input id="{{ $field }}" name="{{ $field }}" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" data-file-input data-rider-required>
                                    <label for="{{ $field }}" class="upload-box__label">
                                        <strong>Upload {{ $label }}</strong>
                                        <span>JPG, JPEG, PNG, WEBP or PDF, up to 5 MB</span>
                                        <small data-file-name>No file selected</small>
                                    </label>
                                </div>
                            @endforeach
                        </div>

                        {{-- PASSWORD --}}
                        <div class="register-grid" style="margin-top:1.5rem;">

                            <div class="register-field">
                                <label for="password">
                                    Password <span>*</span>
                                </label>
                                <input
                                    id="password"
                                    name="password"
                                    type="password"
                                    minlength="8"
                                    maxlength="72"
                                    pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^A-Za-z0-9]).{8,72}"
                                    title="Use 8 to 72 characters with at least one uppercase letter, one lowercase letter, one number, and one symbol."
                                    placeholder="Min. 8 chars, uppercase, number, symbol"
                                    autocomplete="new-password"
                                    required
                                >
                                <small>Use 8–72 characters with uppercase and lowercase letters, a number, and a symbol.</small>
                            </div>

                            <div class="register-field">
                                <label for="password_confirmation">
                                    Confirm Password <span>*</span>
                                </label>
                                <input
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    type="password"
                                    placeholder="Re-enter your password"
                                    autocomplete="new-password"
                                    required
                                >
                            </div>

                        </div>

                        {{-- APPROVAL --}}
                        <div class="approval-notice">

                            <span class="approval-notice__icon">
                                i
                            </span>

                            <div>
                                <strong>
                                    Administrator approval required
                                </strong>
                                <p>
                                    After submitting your registration,
                                    please wait for the administrator's
                                    approval. Sign in after approval to access your account.
                                </p>
                            </div>

                        </div>

                    </div>

                </section>


                {{-- =================================================
                    VALIDATION MESSAGE
                ================================================== --}}
                <div
                    class="step-validation-message"
                    data-step-error
                    hidden
                >
                    Please complete the required fields before continuing.
                </div>


                {{-- =================================================
                    NAVIGATION
                ================================================== --}}
                <div class="register-navigation">

                    <div class="register-navigation__left">

                        <button
                            type="button"
                            class="register-back"
                            data-step-back
                            hidden
                        >
                            <span>
                                ←
                            </span>

                            Back
                        </button>


                        <a
                            href="{{ route('login') }}"
                            class="register-login-link"
                            data-login-link
                        >
                            Already registered?

                            <strong>
                                Sign in
                            </strong>
                        </a>

                    </div>


                    <button
                        type="button"
                        class="register-next"
                        data-step-next
                    >

                        Continue

                        <span>
                            →
                        </span>

                    </button>


                    <button
                        type="submit"
                        class="register-submit"
                        data-submit-button
                        hidden
                    >

                        <span data-submit-label>
                            Submit Buyer Application
                        </span>


                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 12h14"></path>
                            <path d="m13 6 6 6-6 6"></path>
                        </svg>

                    </button>

                </div>

</form>

        </div>

    </main>

</div>

<script>
    window.likhaeRegistrationPolicies = {!! \Illuminate\Support\Js::from($registrationPoliciesByRole ?? []) !!};
    window.likhaePolicySchemaReady = {{ ($policySchemaReady ?? false) ? 'true' : 'false' }};
</script>
<style>
    .register-policy-backdrop{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:16px;background:rgba(31,22,20,.76);backdrop-filter:blur(5px)}.register-policy-backdrop[hidden]{display:none!important}
    .register-policy-dialog{display:flex;flex-direction:column;width:min(100%,780px);max-height:min(92vh,940px);overflow:hidden;border:1px solid #eadbd1;border-radius:22px;background:#fffdf9;color:#2d1b15;box-shadow:0 28px 90px rgba(0,0,0,.32)}
    .register-policy-header{padding:22px 26px 16px;border-bottom:1px solid #eee3dc}.register-policy-header small{color:#994638;font-size:10px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}.register-policy-header h2{margin:7px 0 4px;font-size:22px}.register-policy-header p{margin:0;color:#725d54;font-size:13px;line-height:1.5}
    .register-policy-content{overflow:auto;overscroll-behavior:contain;padding:6px 26px 18px}.register-policy-document{padding:15px 0;border-bottom:1px solid #eee3dc}.register-policy-document:last-child{border:0}.register-policy-document h3{margin:0;font-size:16px}.register-policy-meta{display:flex;flex-wrap:wrap;gap:8px 14px;margin:5px 0 12px;color:#806b61;font-size:11px;font-weight:700}.register-policy-body{white-space:pre-wrap;overflow-wrap:anywhere;color:#43322c;font-size:13px;line-height:1.68}
    .register-policy-footer{padding:16px 26px 19px;border-top:1px solid #eee3dc;background:#fffaf5}.register-policy-ack{display:flex;gap:10px;align-items:flex-start;margin:0 0 10px;color:#43322c;font-size:12px;line-height:1.5}.register-policy-ack input{width:16px;height:16px;margin:1px 0 0;accent-color:#963d31;flex:0 0 auto}.register-policy-error{margin:0 0 12px;color:#aa2e27;font-size:12px;line-height:1.5}.register-policy-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:15px}.register-policy-actions a{color:#795f54;font-size:12px;font-weight:700;text-underline-offset:3px}.register-policy-actions button{border:0;border-radius:11px;background:#963d31;color:#fff;padding:11px 16px;font-size:12px;font-weight:800;cursor:pointer}.register-policy-actions button:disabled{background:#d9c6bc;cursor:not-allowed}
    .dark .register-policy-dialog{border-color:#4b3831;background:#211916;color:#f8eee9}.dark .register-policy-header,.dark .register-policy-footer,.dark .register-policy-document{border-color:#49352d}.dark .register-policy-header p,.dark .register-policy-meta{color:#c2aba0}.dark .register-policy-body,.dark .register-policy-ack{color:#eadbd3}.dark .register-policy-footer{background:#261c18}
    @media(max-width:600px){.register-policy-backdrop{padding:9px}.register-policy-dialog{max-height:95vh;border-radius:17px}.register-policy-header{padding:18px 17px 14px}.register-policy-content{padding:5px 17px 14px}.register-policy-footer{padding:13px 17px 16px}.register-policy-actions{align-items:stretch;flex-direction:column-reverse}.register-policy-actions button{width:100%}.register-policy-actions a{align-self:center}}
</style>
<div class="register-policy-backdrop" data-registration-policy-modal hidden>
    <section class="register-policy-dialog" role="dialog" aria-modal="true" aria-labelledby="register-policy-title" aria-describedby="register-policy-description" tabindex="-1">
        <header class="register-policy-header">
            <small>Registration agreements</small>
            <h2 id="register-policy-title">Review Terms &amp; Conditions and Privacy Policy</h2>
            <p id="register-policy-description">Review the policies for your selected account type. Scroll to the end of each policy to enable its checkbox. Acknowledging both is the final step before submitting your registration.</p>
        </header>
        <div class="register-policy-content" data-registration-policy-content tabindex="0" aria-label="Scrollable policy contents">
            @foreach(($registrationPoliciesByRole[$preselectedRole ?? 'buyer'] ?? []) as $policy)
                <article class="register-policy-document" data-policy-document="{{ $policy['id'] }}" data-policy-type="{{ $policy['policy_type'] }}">
                    <h3>{{ $policy['title'] }}</h3>
                    <div class="register-policy-meta"><span>Version {{ $policy['version'] }}</span><span>Effective {{ $policy['effective_date'] ?? 'Immediately' }}</span></div>
                    <div class="register-policy-body">{{ $policy['body'] }}</div>
                </article>
            @endforeach
        </div>
        <footer class="register-policy-footer">
            @if($errors->has('policy_acceptances') || $errors->has('terms_acknowledged') || $errors->has('privacy_acknowledged'))
                <p class="register-policy-error" role="alert">{{ $errors->first('policy_acceptances') ?: ($errors->first('terms_acknowledged') ?: $errors->first('privacy_acknowledged')) }}</p>
            @endif
            <p class="register-policy-error" data-registration-policy-error @if(($policySchemaReady ?? false) && !empty($registrationPoliciesByRole[$preselectedRole ?? 'buyer'])) hidden @endif>
                @if(!($policySchemaReady ?? false)) Policy storage is not available yet. Registration can continue after the policy update is installed.
                @else Published Terms &amp; Conditions and Privacy Policy are required for this account type before registration can continue. @endif
            </p>
            <label class="register-policy-ack"><input type="checkbox" form="registrationForm" name="terms_acknowledged" value="1" required disabled data-registration-terms-ack data-policy-acknowledgement-type="TERMS"><span>I have read and agree to the applicable Terms &amp; Conditions.</span></label>
            <label class="register-policy-ack"><input type="checkbox" form="registrationForm" name="privacy_acknowledged" value="1" required disabled data-registration-privacy-ack data-policy-acknowledgement-type="PRIVACY"><span>I have read and acknowledge the applicable Privacy Policy.</span></label>
            <div data-registration-policy-hidden-inputs></div>
            <div class="register-policy-actions"><a href="{{ route('home') }}">Decline &amp; exit</a><button type="button" data-registration-policy-agree disabled>I agree</button></div>
        </footer>
    </section>
</div>
<script>
    (()=>{
        const backdrop=document.querySelector('[data-registration-policy-modal]');
        const dialog=backdrop?.querySelector('[role="dialog"]');
        const content=backdrop?.querySelector('[data-registration-policy-content]');
        const agree=backdrop?.querySelector('[data-registration-policy-agree]');
        const error=backdrop?.querySelector('[data-registration-policy-error]');
        const terms=backdrop?.querySelector('[data-registration-terms-ack]');
        const privacy=backdrop?.querySelector('[data-registration-privacy-ack]');
        if(!backdrop||!dialog||!content||!agree)return;
        window.likhaeRenderRegistrationPolicySet=function(role,docs){
            const list=Array.isArray(docs)?docs:[];
            content.replaceChildren();
            let hasTerms=false,hasPrivacy=false;
            list.forEach(policy=>{
                hasTerms ||= policy.policy_type==='TERMS';hasPrivacy ||= policy.policy_type==='PRIVACY';
                const article=document.createElement('article');article.className='register-policy-document';article.dataset.policyDocument=String(policy.id);article.dataset.policyType=policy.policy_type;
                const title=document.createElement('h3');title.textContent=policy.title;
                const meta=document.createElement('div');meta.className='register-policy-meta';
                const version=document.createElement('span');version.textContent=`Version ${policy.version}`;
                const date=document.createElement('span');date.textContent=`Effective ${policy.effective_date||'Immediately'}`;
                const body=document.createElement('div');body.className='register-policy-body';body.textContent=policy.body;
                meta.append(version,date);article.append(title,meta,body);content.append(article);
            });
            content.scrollTop=0;
            const ready=Boolean(window.likhaePolicySchemaReady&&hasTerms&&hasPrivacy);
            if(error){error.hidden=ready;if(!ready&&window.likhaePolicySchemaReady)error.textContent='Published Terms & Conditions and Privacy Policy are required for this account type before registration can continue.'}
            backdrop.dataset.activeRole=role;backdrop.dataset.policyReady=ready?'true':'false';
            if(terms){terms.checked=false;terms.disabled=true}if(privacy){privacy.checked=false;privacy.disabled=true}
            backdrop.querySelector('[data-registration-policy-hidden-inputs]')?.replaceChildren();
            window.likhaeRegistrationPolicyConsentedRole=null;
            updateAgree();
        };
        function updateAgree(){agree.disabled=backdrop.dataset.policyReady!=='true'||!terms||!privacy||terms.disabled||privacy.disabled||!terms.checked||!privacy.checked}
        function unlockReachedPolicies(){
            const contentBottom=content.getBoundingClientRect().bottom;
            [terms,privacy].forEach(input=>{
                const article=[...content.querySelectorAll('[data-policy-document]')].find(item=>item.dataset.policyType===input?.dataset.policyAcknowledgementType);
                if(input&&article&&article.getBoundingClientRect().bottom<=contentBottom+2)input.disabled=false;
            });
            updateAgree();
        }
        terms?.addEventListener('change',updateAgree);privacy?.addEventListener('change',updateAgree);
        content.addEventListener('scroll',unlockReachedPolicies,{passive:true});
        window.addEventListener('resize',unlockReachedPolicies);
        window.likhaeOpenRegistrationPolicyReview=function(){
            if(backdrop.dataset.policyReady!=='true')return false;
            backdrop.hidden=false;
            document.querySelectorAll('body > *:not([data-registration-policy-modal])').forEach(node=>node.inert=true);
            dialog.focus();
            requestAnimationFrame(unlockReachedPolicies);
            return true;
        };
        agree.addEventListener('click',()=>{
            if(agree.disabled)return;
            const role=backdrop.dataset.activeRole;const docs=window.likhaeRegistrationPolicies?.[role]||[];const fields=backdrop.querySelector('[data-registration-policy-hidden-inputs]');
            fields.replaceChildren();docs.forEach(policy=>{const input=document.createElement('input');input.type='hidden';input.name=`policy_acceptances[${policy.id}]`;input.value=policy.version;input.setAttribute('form','registrationForm');fields.append(input)});
            terms.disabled=false;privacy.disabled=false;terms.checked=true;privacy.checked=true;
            window.likhaeRegistrationPolicyConsentedRole=role;
            backdrop.hidden=true;document.querySelectorAll('body > *:not([data-registration-policy-modal])').forEach(node=>node.inert=false);
            const form=document.getElementById('registrationForm');const submitButton=form?.querySelector('[data-submit-button]');
            if(submitButton)form.requestSubmit(submitButton);else form?.requestSubmit();
        });
        document.addEventListener('keydown',event=>{
            if(backdrop.hidden)return;
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();return}
            if(event.key!=='Tab')return;
            const focusable=[...dialog.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),[tabindex="0"]')];
            if(!focusable.length)return;const first=focusable[0],last=focusable[focusable.length-1];
            if(event.shiftKey&&(document.activeElement===first||document.activeElement===dialog)){event.preventDefault();last.focus()}
            else if(!event.shiftKey&&(document.activeElement===last||document.activeElement===dialog)){event.preventDefault();first.focus()}
        },true);
        window.likhaeSyncRegistrationPolicyRole=function(role){
            if(backdrop.dataset.activeRole===role)return;
            const docs=window.likhaeRegistrationPolicies?.[role]||[];
            window.likhaeRenderRegistrationPolicySet(role,docs);
            if(!backdrop.hidden){unlockReachedPolicies();dialog.focus()}
        };
        const initialRole=document.getElementById('accountType')?.value||'buyer';
        window.likhaeRenderRegistrationPolicySet(initialRole,window.likhaeRegistrationPolicies?.[initialRole]||[]);
    })();
</script>

</body>
</html>
