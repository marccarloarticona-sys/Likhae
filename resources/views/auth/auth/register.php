<!DOCTYPE html>
<html lang="en">

<head>
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
            --lk-register-bg-image: url("{{ asset('images/login-page-bg.jpg') }}");
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

                </div>

                <div class="register-side__footer">
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
                </div>

            </div>

        </section>

        <section class="register-prep" aria-labelledby="register-prep-title">
            <header class="register-prep__head">
                <span class="register-prep__eyebrow">A smooth start</span>
                <h2 id="register-prep-title">Before you begin</h2>
                <p>Keep these handy as you create your account.</p>
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
                        <strong>Planning to sell?</strong>
                        <span>Have your business details and permit ready.</span>
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
            <form
                method="POST"
                action="{{ route('register.store') }}"
                enctype="multipart/form-data"
                class="register-form"
                id="registrationForm"
                novalidate
                data-address-base="{{ url()->to('/address/philippines', [], false) }}"
            >

                @csrf


                <input
                    type="hidden"
                    name="account_type"
                    id="accountType"
                    value="{{ old('account_type', ($preselectedRole ?? 'buyer') === 'courier' ? 'rider' : ($preselectedRole ?? 'buyer')) }}"
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
                                    maxlength="80"
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
                                        value="Male"
                                        {{ old('sex') === 'Male' ? 'selected' : '' }}
                                    >
                                        Male
                                    </option>

                                    <option
                                        value="Female"
                                        {{ old('sex') === 'Female' ? 'selected' : '' }}
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
                                    value="{{ old('age') }}"
                                    placeholder="Auto-generated"
                                    readonly
                                    required
                                >

                                <small>
                                    Automatically calculated from
                                    your birthday.
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
                            <div class="register-field">

                                <label for="email">
                                    Email Address
                                    <span>*</span>
                                </label>

                                <input
                                    id="email"
                                    name="email"
                                    type="email"
                                    value="{{ old('email', data_get($googleBuyerRegistration ?? null, 'email')) }}"
                                    @if(!empty($googleBuyerRegistration)) readonly @endif
                                    placeholder="juan@email.com"
                                    autocomplete="email"
                                    required
                                >

                            </div>


                            {{-- CONTACT --}}
                            <div class="register-field">

                                <label for="contact_no">
                                    Contact Number
                                    <span>*</span>
                                </label>


                                <div class="phone-input">

                                    <span class="phone-input__prefix">
                                        +63
                                    </span>


                                    <input
                                        id="contact_no"
                                        name="contact_no"
                                        type="tel"
                                        value="{{ old('contact_no') }}"
                                        placeholder="917 123 4567"
                                        inputmode="numeric"
                                        autocomplete="tel"
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
                                <label for="postal_code">Postal Code</label>
                                <input id="postal_code" name="postal_code" type="text" inputmode="numeric"
                                    value="{{ old('postal_code') }}" placeholder="Auto-filled after barangay" readonly data-address-postal data-old-value="{{ old('postal_code') }}">
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
                                        Select category
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
                                    @foreach(['motorcycle' => 'Motorcycle', 'car' => 'Car', 'van' => 'Van', 'truck' => 'Truck'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('vehicle_type') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="register-field">
                                <label for="plate_number">Plate Number <span>*</span></label>
                                <input id="plate_number" name="plate_number" value="{{ old('plate_number') }}" maxlength="30" data-rider-required>
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
                                    accept=".jpg,.jpeg,.png,.pdf"
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
                                        JPG, JPEG, PNG or PDF
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
                                    accept=".jpg,.jpeg,.png,.pdf"
                                    data-file-input
                                    data-seller-required
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
                                        JPG, JPEG, PNG or PDF
                                    </span>

                                    <small data-file-name>
                                        No file selected
                                    </small>

                                </label>

                            </div>

                        </div>


                        <div class="register-grid" id="riderVehicleSection" hidden>
                            @foreach(['or_cr' => 'Vehicle OR / CR', 'drivers_license' => "Driver's License"] as $field => $label)
                                <div class="upload-box">
                                    <input id="{{ $field }}" name="{{ $field }}" type="file" accept=".jpg,.jpeg,.png,.pdf" data-file-input data-rider-required>
                                    <label for="{{ $field }}" class="upload-box__label">
                                        <strong>Upload {{ $label }}</strong>
                                        <span>JPG, JPEG, PNG or PDF, up to 5 MB</span>
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
                                    pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,72}"
                                    title="Use 8–72 characters with uppercase and lowercase letters and a number."
                                    placeholder="Min. 8 chars, uppercase, number"
                                    autocomplete="new-password"
                                    required
                                >
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
                                    Application review required
                                </strong>
                                <p>
                                    Your application will be reviewed before account access is enabled. Sign in to check its status and replace any document that needs correction.
                                </p>
                            </div>

                        </div>

                        {{-- TERMS --}}
                        <label class="register-terms">

                            <input
                                type="checkbox"
                                name="terms"
                                value="1"
                                required
                            >

                            <span>
                                I confirm that the information provided
                                is correct and I agree to LIKHAE's terms
                                and marketplace policies.
                            </span>

                        </label>

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
                    Please complete all required fields before continuing.
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

</body>
</html>
