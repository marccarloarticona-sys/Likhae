@extends('layouts.buyer')

@section('title', 'Account')
@section('active', 'account')

@section('content')
@if(session('buyer_notice'))<div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs font-medium text-red-900">{{ session('buyer_notice') }}</div>@endif
@if($errors->any())<div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-900">{{ $errors->first() }}</div>@endif
@php
    $user = auth()->user();

    $buyerName = data_get($user, 'name', 'Buyer Name');
    $buyerFirstName = data_get($user, 'first_name', '');
    $buyerMiddleName = data_get($user, 'middle_initial', '');
    $buyerLastName = data_get($user, 'last_name', '');
    $buyerNameExtension = data_get($user, 'name_extension', '');
    $buyerEmail = data_get($user, 'email', 'buyer@example.com');
    $buyerPhone = data_get($user, 'contact_number', '');
    $buyerPhoneDisplay = filled($buyerPhone)
        ? \App\Support\PhilippineMobileNumber::format((string) $buyerPhone)
        : '';
    $savedAddresses = collect($addresses ?? []);
    $defaultAddressId = old(
        'address_id',
        $savedAddresses->firstWhere('is_default', true)?->id ?? $savedAddresses->first()?->id
    );
    $buyerBirthday = data_get($user, 'birthday', '');
    $buyerGender = data_get($user, 'sex', '');

    $profilePhoto = data_get($user, 'profile_photo_path')
        ?? data_get($user, 'profile_photo')
        ?? data_get($user, 'profile_picture')
        ?? null;

    $buyerInitial = strtoupper(mb_substr($buyerName, 0, 1));

    $allowedTabs = [
        'profile',
        'addresses',
        'reviews',
        'password',
        'danger',
    ];

    $requestedTab = request('tab', 'profile');

    $activeTab = in_array($requestedTab, $allowedTabs, true)
        ? $requestedTab
        : 'profile';

    $tabs = [
        'profile' => [
            'label' => 'Profile',
            'description' => 'Personal information',
        ],
        'addresses' => [
            'label' => 'Addresses',
            'description' => 'Delivery locations',
        ],
        'reviews' => [
            'label' => 'Reviews',
            'description' => 'Your product feedback',
        ],
        'password' => [
            'label' => 'Change Password',
            'description' => 'Account security',
        ],
        'danger' => [
            'label' => 'Danger Zone',
            'description' => 'Close account',
        ],
    ];

    $tabIcon = function (string $tab): string {
        return match ($tab) {
            'profile' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="7" r="4"/>
                    <path d="M20 21a8 8 0 0 0-16 0"/>
                </svg>
            ',

            'addresses' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M20 10c0 5-8 12-8 12S4 15 4 10a8 8 0 1 1 16 0Z"/>
                    <circle cx="12" cy="10" r="2.5"/>
                </svg>
            ',

            'reviews' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>
                </svg>
            ',

            'password' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="4" y="10" width="16" height="11" rx="2"/>
                    <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    <path d="M12 14v3"/>
                </svg>
            ',

            'privacy' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3 5 6v5c0 4.8 2.9 8.4 7 10 4.1-1.6 7-5.2 7-10V6z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
            ',

            'deletion' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 7h16"/>
                    <path d="M9 7V4h6v3"/>
                    <path d="m6 7 1 14h10l1-14"/>
                    <path d="M10 11v6M14 11v6"/>
                </svg>
            ',

            'danger' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 3 2.5 20h19L12 3Z"/>
                    <path d="M12 9v5M12 17h.01"/>
                </svg>
            ',

            'notifications' => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
                    <path d="M10 21h4"/>
                </svg>
            ',

            default => '
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                </svg>
            ',
        };
    };
@endphp

<div class="mx-auto w-full max-w-[1500px] px-4 py-6 sm:px-6 lg:px-8" data-account-page>
    {{-- Page heading --}}
    <header class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-red-800">
                Buyer Center
            </span>

            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-stone-900">
                Account Management
            </h1>

            <p class="mt-1 text-sm text-stone-500">
                Manage your profile, addresses, security, and account preferences.
            </p>
        </div>

        <span class="inline-flex w-fit items-center gap-2 rounded-full border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700">
            <span class="h-2 w-2 rounded-full bg-red-500"></span>
            Account active
        </span>
    </header>

    <div class="grid items-start gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
        {{-- Account navigation --}}
        <aside class="overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="border-b border-stone-100 p-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-red-100 text-sm font-bold text-red-900">
                        <x-shared.profile-image :path="$profilePhoto" role="buyer" :alt="$buyerName" class="h-full w-full object-cover" />
                    </div>

                    <div class="min-w-0">
                        <strong class="block truncate text-sm font-semibold text-stone-900">
                            {{ $buyerName }}
                        </strong>

                        <span class="block truncate text-xs text-stone-500">
                            {{ $buyerEmail }}
                        </span>
                    </div>
                </div>
            </div>

            <nav class="flex gap-2 overflow-x-auto p-2 lg:block lg:space-y-1" aria-label="Account settings">
                @foreach ($tabs as $tabKey => $tab)
                    <a
                        href="{{ route('buyer.account', ['tab' => $tabKey]) }}"
                        class="group flex min-w-max items-center gap-3 rounded-xl px-3 py-2.5 transition
                            {{ $activeTab === $tabKey
                                ? 'bg-red-50 text-red-900'
                                : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}"
                        @if ($activeTab === $tabKey) aria-current="page" @endif
                    >
                        <span class="h-5 w-5 shrink-0 [&>svg]:h-full [&>svg]:w-full [&>svg]:fill-none [&>svg]:stroke-current [&>svg]:stroke-[1.8] [&>svg]:stroke-linecap-round [&>svg]:stroke-linejoin-round">
                            {!! $tabIcon($tabKey) !!}
                        </span>

                        <span>
                            <strong class="block text-xs font-semibold">
                                {{ $tab['label'] }}
                            </strong>

                            <small class="mt-0.5 hidden text-[10px] font-normal text-stone-400 lg:block">
                                {{ $tab['description'] }}
                            </small>
                        </span>
                    </a>
                @endforeach
            </nav>
        </aside>

        {{-- Account content --}}
        <main class="min-w-0 rounded-2xl border border-stone-200 bg-white shadow-sm">
            @if ($activeTab === 'profile')
                <section aria-labelledby="profile-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="profile-heading" class="text-base font-semibold text-stone-900">
                            Profile Information
                        </h2>

                        <p class="mt-1 text-xs text-stone-500">
                            Update your personal details.
                        </p>
                    </div>

                    <div class="p-5 sm:p-6">
                        <form id="buyerProfileForm" method="POST" enctype="multipart/form-data" action="{{ route('buyer.account.profile.update') }}" data-original-email="{{ $buyerEmail }}" data-original-phone="{{ $buyerPhoneDisplay }}">
                            @csrf
                            @method('PUT')
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div class="sm:col-span-2 rounded-2xl border border-stone-200 bg-stone-50 p-4">
                                    <x-profile-photo-upload :user="$user" id="buyer-profile-photo">
                                        Profile photo
                                    </x-profile-photo-upload>
                                </div>

                                <div class="sm:col-span-2">
                                    <p class="mb-2 text-xs font-semibold text-stone-700">Name</p>
                                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                        <label for="profile-first-name" class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-stone-700">First name</span>
                                            <input id="profile-first-name" type="text" name="first_name" value="{{ old('first_name', $buyerFirstName) }}" required class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                        </label>
                                        <label for="profile-middle-name" class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-stone-700">Middle name</span>
                                            <input id="profile-middle-name" type="text" name="middle_initial" value="{{ old('middle_initial', $buyerMiddleName) }}" maxlength="10" class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                        </label>
                                        <label for="profile-last-name" class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-stone-700">Last name</span>
                                            <input id="profile-last-name" type="text" name="last_name" value="{{ old('last_name', $buyerLastName) }}" required class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                        </label>
                                        <label for="profile-name-extension" class="block">
                                            <span class="mb-1.5 block text-xs font-semibold text-stone-700">Extension / suffix</span>
                                            <input id="profile-name-extension" type="text" name="name_extension" value="{{ old('name_extension', $buyerNameExtension) }}" maxlength="20" placeholder="Jr., Sr., III" class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                        </label>
                                    </div>
                                </div>

                                <div>
                                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                        <label for="profile-email" class="text-xs font-semibold text-stone-700">Email address</label>
                                        @if($user->email_verified_at)
                                            <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-800">Verified</span>
                                        @else
                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-800">Verification required</span>
                                        @endif
                                    </div>

                                    <input
                                        id="profile-email"
                                        type="email"
                                        name="email"
                                        value="{{ filled($pendingEmail) ? $pendingEmail : old('email', $buyerEmail) }}"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
                                </div>

                                <div>
                                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                                        <label for="profile-phone" class="text-xs font-semibold text-stone-700">Mobile number</label>
                                        @if($user->email_verified_at)
                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-800">Email confirmation required</span>
                                        @else
                                            <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-800">Verify email first</span>
                                        @endif
                                    </div>

                                    <input
                                        id="profile-phone"
                                        type="tel"
                                        name="phone"
                                        value="{{ old('phone', filled($pendingPhone) ? \App\Support\PhilippineMobileNumber::format((string) $pendingPhone) : $buyerPhoneDisplay) }}"
                                        placeholder="09XX XXX XXXX"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
                                    @if($user->email_verified_at)
                                        <p class="mt-1.5 text-[11px] leading-5 text-stone-500">Changing this number sends a confirmation code to your verified email address.</p>
                                    @else
                                        <p class="mt-1.5 text-[11px] leading-5 text-stone-500">Verify your account email before changing your mobile number.</p>
                                    @endif
                                </div>

                                <div>
                                    <label for="profile-birthday" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                        Date of birth
                                    </label>

                                    <input
                                        id="profile-birthday"
                                        type="date"
                                        name="birthday"
                                        value="{{ old('birthday', $buyerBirthday) }}"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
                                </div>

                                <div>
                                    <label for="profile-gender" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                        Gender
                                    </label>

                                    <select
                                        id="profile-gender"
                                        name="gender"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
                                        <option value="">Prefer not to say</option>
                                        <option value="male" @selected(old('gender', strtolower($buyerGender)) === 'male')>Male</option>
                                        <option value="female" @selected(old('gender', strtolower($buyerGender)) === 'female')>Female</option>
                                        <option value="other" @selected(old('gender', strtolower($buyerGender)) === 'other')>Other</option>
                                    </select>
                                </div>
                            </div>

                            <div class="mt-6 flex justify-end border-t border-stone-100 pt-5">
                                <button
                                    type="submit"
                                    class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100"
                                >
                                    Save Changes
                                </button>
                            </div>
                            <p data-profile-form-status role="status" aria-live="polite" class="mt-3 text-right text-xs text-stone-600"></p>

                            @php
                                $profileOtpPurpose = session('profile_mobile_otp_pending') || filled($pendingPhone) || filled(old('mobile_change_code')) ? 'mobile_change' : 'email_change';
                                $showProfileOtp = session('profile_email_otp_pending') || session('profile_mobile_otp_pending') || filled($pendingEmail) || filled($pendingPhone) || $errors->has('code');
                                $profileOtpTarget = $profileOtpPurpose === 'mobile_change'
                                    ? $buyerEmail
                                    : ($pendingEmail ?: old('email', $buyerEmail));
                            @endphp
                            <div
                                data-profile-otp-dialog
                                data-profile-otp-purpose="{{ $profileOtpPurpose }}"
                                role="dialog"
                                aria-modal="true"
                                aria-labelledby="profile-otp-title"
                                class="fixed inset-0 z-50 flex items-center justify-center bg-stone-950/50 p-4"
                                @unless($showProfileOtp) style="display:none" @endunless
                            >
                                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <h3 id="profile-otp-title" data-profile-otp-title class="text-base font-semibold text-stone-900">{{ $profileOtpPurpose === 'mobile_change' ? 'Confirm your mobile number change' : 'Verify your email change' }}</h3>
                                            <p class="mt-1 text-xs leading-5 text-stone-600"><span data-profile-otp-description>{{ $profileOtpPurpose === 'mobile_change' ? 'Enter the six-digit code sent to your verified email address' : 'Enter the six-digit code sent to' }}</span> <strong data-profile-otp-target>{{ $profileOtpTarget }}</strong>. Your profile changes will be saved after verification.</p>
                                        </div>
                                        <button type="button" data-profile-otp-close aria-label="Close" class="rounded-lg px-2 py-1 text-lg leading-none text-stone-500 hover:bg-stone-100">&times;</button>
                                    </div>
                                    <label for="profile-change-code" class="mt-5 grid gap-1.5 text-xs font-semibold text-stone-700">
                                        Six-digit verification code
                                        <input id="profile-change-code" name="{{ $profileOtpPurpose === 'mobile_change' ? 'mobile_change_code' : 'email_change_code' }}" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" value="" data-profile-otp-code class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-3 text-center text-lg tracking-[0.35em] outline-none focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                    </label>
                                    <p data-profile-otp-error role="alert" class="mt-2 text-xs text-red-700"></p>
                                    <p data-profile-otp-status role="status" aria-live="polite" class="mt-2 text-xs text-stone-600">Codes expire after 5 minutes.</p>
                                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                                        <button type="button" data-profile-otp-resend class="text-xs font-semibold text-red-900 underline decoration-red-300 underline-offset-4 hover:text-red-950">Send a new code</button>
                                        <button type="submit" data-profile-otp-submit class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950">Verify and Save Changes</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </section>
            @endif

            @if ($activeTab === 'addresses')
                <section aria-labelledby="addresses-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="addresses-heading" class="text-base font-semibold text-stone-900">Delivery Addresses</h2>
                        <p class="mt-1 text-xs text-stone-500">Manage delivery destinations. Changes apply to future orders; existing orders keep their original address.</p>
                        <p class="mt-2 text-xs font-medium text-stone-600">{{ $addressCount ?? collect($addresses ?? [])->count() }} of {{ $addressLimit ?? 10 }} saved addresses</p>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        @if($savedAddresses->isNotEmpty())
                            <div class="grid gap-4 rounded-2xl border border-red-100 bg-red-50/60 p-4 sm:grid-cols-[minmax(0,1fr)_minmax(300px,1.2fr)] sm:items-center sm:p-5">
                                <div>
                                    <h3 class="text-sm font-semibold text-stone-900">Default delivery address</h3>
                                    <p class="mt-1 text-xs leading-5 text-stone-600">Choose the address to preselect at checkout. You can still choose a different address for each order.</p>
                                </div>
                                <form method="POST" action="{{ route('buyer.account.addresses.default') }}" class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                    @csrf @method('PATCH')
                                    <label class="grid min-w-0 flex-1 gap-1.5 text-xs font-semibold text-stone-700">
                                        Choose a saved address
                                        <select name="address_id" required class="w-full rounded-xl border border-stone-300 bg-white px-3 py-2.5 text-sm font-normal text-stone-800 outline-none focus:border-red-800 focus:ring-4 focus:ring-red-100">
                                            @foreach($savedAddresses as $address)
                                                <option value="{{ $address->id }}" @selected((string) $defaultAddressId === (string) $address->id)>{{ $address->label ?: 'Address' }} — {{ $address->recipient_name }} — {{ \Illuminate\Support\Str::limit($address->formatted(), 58) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit" class="shrink-0 rounded-xl bg-red-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100">Save default</button>
                                </form>
                            </div>
                        @endif

                        @forelse(collect($addresses ?? []) as $address)
                            <article class="rounded-2xl border {{ $address->is_default ? 'border-red-300 bg-red-50/40' : 'border-stone-200 bg-white' }} p-4 sm:p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-stone-900">{{ $address->label }} <span class="text-stone-400">·</span> {{ $address->recipient_name }}</h3>
                                            @if($address->is_default)<span class="rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold text-red-900">Default address</span>@endif
                                        </div>
                                        <p class="mt-2 text-xs font-medium text-stone-600">{{ \App\Support\PhilippineMobileNumber::format((string) $address->contact_number) }}</p>
                                        <p class="mt-2 text-sm leading-6 text-stone-600">{{ $address->formatted() }}</p>
                                        @if(filled($address->latitude) && filled($address->longitude))
                                            <p class="mt-2 inline-flex items-center gap-1.5 text-[11px] font-medium text-emerald-700"><span aria-hidden="true">✓</span> Delivery pin confirmed</p>
                                        @else
                                            <p class="mt-2 inline-flex items-center gap-1.5 text-[11px] font-medium text-amber-700"><span aria-hidden="true">!</span> Confirm a map pin before checkout</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-3">
                                        <details class="group">
                                            <summary class="cursor-pointer list-none rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold text-stone-700 hover:border-red-300 hover:text-red-900 focus:outline-none focus:ring-4 focus:ring-red-100">Edit address</summary>
                                            <form method="POST" action="{{ route('buyer.account.addresses.update', ['address' => $address->id]) }}" class="mt-3 grid gap-4 rounded-xl border border-stone-200 bg-white p-4 shadow-sm sm:grid-cols-2">
                                                @csrf @method('PUT')
                                                <label class="grid gap-1 text-xs font-semibold text-stone-700">
                                                    Address label
                                                    <input required name="label" value="{{ old('label', $address->label) }}" maxlength="50" placeholder="Home, Work" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal">
                                                </label>
                                                <label class="grid gap-1 text-xs font-semibold text-stone-700">
                                                    Recipient name
                                                    <input required name="recipient_name" value="{{ old('recipient_name', $address->recipient_name) }}" maxlength="200" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal">
                                                </label>
                                                <label class="grid gap-1 text-xs font-semibold text-stone-700">
                                                    Contact number
                                                    <input required name="contact_number" value="{{ old('contact_number', \App\Support\PhilippineMobileNumber::format((string) $address->contact_number)) }}" maxlength="30" inputmode="tel" placeholder="09XX XXX XXXX" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal">
                                                </label>
                                                <x-shared.philippine-address-fields :address="$address" wrapper-class="contents" />
                                                <x-shared.address-pin :address="$address" class="sm:col-span-2" />
                                                <label class="flex items-center gap-2 text-xs font-semibold text-stone-700 sm:col-span-2"><input type="checkbox" name="is_default" value="1" @checked($address->is_default)> Use as my default address</label>
                                                <div class="flex justify-end sm:col-span-2"><button type="submit" class="rounded-xl bg-red-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100">Save address</button></div>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('buyer.account.addresses.destroy', ['address' => $address->id]) }}" data-confirm-title="Delete saved address?" data-confirm-message="This saved address will be removed from your account." data-confirm-label="Delete address" data-confirm-tone="danger">
                                            @csrf @method('DELETE')
                                            @if($address->is_default && ($addressCount ?? collect($addresses ?? [])->count()) > 1)
                                                <p class="mb-1 max-w-44 text-right text-[10px] leading-4 text-amber-800">Set another address as default before deleting this address.</p>
                                            @endif
                                            <button type="submit" @disabled($address->is_default && ($addressCount ?? collect($addresses ?? [])->count()) > 1) class="rounded-lg px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-100 disabled:cursor-not-allowed disabled:opacity-50">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        @empty
                            <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 px-4 py-6 text-center">
                                <h3 class="text-sm font-semibold text-stone-800">No saved addresses yet</h3>
                                <p class="mt-1 text-xs text-stone-500">Add a delivery address below to use it at checkout.</p>
                            </div>
                        @endforelse

                        @if(($addressCount ?? collect($addresses ?? [])->count()) >= ($addressLimit ?? 10))
                            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-medium text-amber-900">You can save up to 10 delivery addresses.</div>
                        @else
                        <form method="POST" action="{{ route('buyer.account.addresses.store') }}" class="grid gap-4 rounded-2xl border border-stone-200 bg-stone-50 p-4 sm:p-5 sm:grid-cols-2">
                            @csrf
                            <div class="sm:col-span-2"><h3 class="text-sm font-semibold text-stone-900">Add delivery address</h3><p class="mt-1 text-xs text-stone-500">Choose a precise location so the courier can find you.</p></div>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Address label<input required name="label" value="{{ old('label','Home') }}" maxlength="50" placeholder="Home, Work" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Recipient name<input required name="recipient_name" value="{{ old('recipient_name',$buyerName) }}" maxlength="200" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Recipient contact number<input required name="contact_number" value="{{ old('contact_number',$buyerPhoneDisplay) }}" maxlength="30" inputmode="tel" placeholder="09XX XXX XXXX" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <x-shared.philippine-address-fields wrapper-class="contents" />
                            <x-shared.address-pin class="sm:col-span-2" />
                            <label class="flex items-center gap-2 text-xs font-semibold text-stone-700 sm:col-span-2"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', collect($addresses ?? [])->isEmpty()))> Use as my default address</label>
                            <div class="sm:col-span-2"><button type="submit" class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100">Save address</button></div>
                        </form>
                        @endif
                    </div>
                </section>
            @endif

            @if ($activeTab === 'reviews')
                <section aria-labelledby="reviews-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="reviews-heading" class="text-base font-semibold text-stone-900">Your Product Reviews</h2>
                        <p class="mt-1 text-xs text-stone-500">Read your order reviews and any Seller responses.</p>
                    </div>
                    <div class="space-y-3 p-5 sm:p-6">
                        @forelse($reviews ?? [] as $review)
                            <article class="rounded-xl border border-stone-200 bg-white p-4">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <h3 class="font-semibold text-stone-900">{{ $review->orderItem?->product_name ?? 'Purchased product' }}</h3>
                                        <p class="mt-1 text-xs text-stone-500">Variant: {{ $review->orderItem?->variant_description ?: 'Standard' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <strong class="text-amber-600" aria-label="{{ $review->rating }} out of 5 stars">{{ str_repeat('★', (int) $review->rating) }}{{ str_repeat('☆', 5 - (int) $review->rating) }}</strong>
                                        <time class="mt-1 block text-xs text-stone-500">{{ $review->created_at?->format('M j, Y') }}</time>
                                    </div>
                                </div>
                                @if(filled($review->comment))<p class="mt-3 whitespace-pre-line text-sm text-stone-700">{{ $review->comment }}</p>@endif
                                @if(filled($review->seller_reply))
                                    <div class="mt-4 rounded-lg border border-stone-200 bg-stone-50 p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <strong class="text-sm text-stone-900">Seller Response</strong>
                                            @if($review->seller_replied_at)<time class="text-xs text-stone-500">{{ $review->seller_replied_at->format('M j, Y') }}</time>@endif
                                        </div>
                                        <p class="mt-2 whitespace-pre-line text-sm text-stone-700">{{ $review->seller_reply }}</p>
                                    </div>
                                @endif
                            </article>
                        @empty
                            <div class="rounded-xl border border-dashed border-stone-300 bg-stone-50 px-4 py-8 text-center">
                                <h3 class="font-semibold text-stone-800">No product reviews yet</h3>
                                <p class="mt-1 text-sm text-stone-500">Reviews you leave on completed orders will appear here.</p>
                                <a class="mt-4 inline-flex rounded-xl bg-red-900 px-4 py-2 text-xs font-semibold text-white hover:bg-red-950" href="{{ route('buyer.orders') }}">View My Orders</a>
                            </div>
                        @endforelse
                        @if($reviews->hasPages())<div class="pt-2">{{ $reviews->links() }}</div>@endif
                    </div>
                </section>
            @endif

            @if ($activeTab === 'password')
                <section aria-labelledby="password-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="password-heading" class="text-base font-semibold text-stone-900">
                            Change Password
                        </h2>

                        <p class="mt-1 text-xs text-stone-500">
                            Use a strong password that you do not use elsewhere.
                        </p>
                    </div>

                    <div class="p-5 sm:p-6">
                        <form method="POST" action="{{ route('buyer.account.password.update') }}" class="max-w-xl space-y-5">
                            @csrf
                            @method('PUT')
                            <div>
                                <label for="current-password" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                    Current password
                                </label>

                                <input
                                    id="current-password"
                                    type="password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                >
                            </div>

                            <div>
                                <label for="new-password" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                    New password
                                </label>

                                <input
                                    id="new-password"
                                    type="password"
                                    name="new_password"
                                    autocomplete="new-password"
                                    class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                >
                            </div>

                            <div>
                                <label for="confirm-password" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                    Confirm new password
                                </label>

                                <input
                                    id="confirm-password"
                                    type="password"
                                    name="new_password_confirmation"
                                    autocomplete="new-password"
                                    class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                >
                            </div>

                            <div class="rounded-xl border border-stone-200 bg-stone-50 p-4 text-xs leading-5 text-stone-700">
                                Use at least eight characters, including uppercase and lowercase letters, a number, and a symbol. You cannot reuse your current password.
                            </div>

                            <button
                                type="submit"
                                class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950"
                            >
                                Update Password
                            </button>
                        </form>
                    </div>
                </section>
            @endif

            @if ($activeTab === 'privacy')
                <section aria-labelledby="privacy-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="privacy-heading" class="text-base font-semibold text-stone-900">
                            Privacy Settings
                        </h2>

                        <p class="mt-1 text-xs text-stone-500">
                            Control how your information is used within LIKHAE.
                        </p>
                    </div>

                    <div class="divide-y divide-stone-100 p-5 sm:p-6">
                        @php
                            $privacyOptions = [
                                [
                                    'name' => 'Personalized recommendations',
                                    'description' => 'Use your shopping activity to improve product recommendations.',
                                    'checked' => true,
                                ],
                                [
                                    'name' => 'Activity visibility',
                                    'description' => 'Allow sellers to see when you have read their messages.',
                                    'checked' => true,
                                ],
                                [
                                    'name' => 'Shopping analytics',
                                    'description' => 'Share anonymous usage information to help improve LIKHAE.',
                                    'checked' => false,
                                ],
                            ];
                        @endphp

                        @foreach ($privacyOptions as $option)
                            <label class="flex cursor-pointer items-start justify-between gap-5 py-4 first:pt-0 last:pb-0">
                                <span>
                                    <strong class="block text-sm font-semibold text-stone-800">
                                        {{ $option['name'] }}
                                    </strong>

                                    <span class="mt-1 block max-w-xl text-xs leading-5 text-stone-500">
                                        {{ $option['description'] }}
                                    </span>
                                </span>

                                <span class="relative mt-0.5 inline-flex shrink-0">
                                    <input
                                        type="checkbox"
                                        class="peer sr-only"
                                        @checked($option['checked'])
                                    >

                                    <span class="h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-red-900 peer-focus-visible:ring-4 peer-focus-visible:ring-red-100"></span>

                                    <span class="pointer-events-none absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"></span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex justify-end border-t border-stone-100 px-5 py-4 sm:px-6">
                        <button
                            type="button"
                            class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950"
                        >
                            Save Privacy Settings
                        </button>
                    </div>
                </section>
            @endif

            @if ($activeTab === 'danger')
                <section aria-labelledby="deletion-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="deletion-heading" class="text-base font-semibold text-stone-900">
                            Account Deletion
                        </h2>

                        <p class="mt-1 text-xs text-stone-500">
                            Close your LIKHAE Buyer account.
                        </p>
                    </div>

                    <div class="p-5 sm:p-6">
                        <div class="max-w-2xl rounded-xl border border-red-200 bg-red-50 p-4">
                            <h3 class="text-sm font-semibold text-red-800">
                                This action cannot be undone
                            </h3>

                            <p class="mt-2 text-xs leading-5 text-red-700">
                                This securely deactivates your account and signs you out.
                                Historical orders are retained to protect fulfilment and payment records.
                                Active orders must be completed or cancelled first.
                            </p>
                        </div>

                        <form method="POST" action="{{ route('buyer.account.destroy') }}" class="mt-6 max-w-xl space-y-5" data-buyer-delete-form data-confirm-title="Deactivate your account?" data-confirm-message="Your account will be deactivated and you will be signed out. Historical orders will be retained." data-confirm-label="Deactivate account" data-confirm-tone="danger">
                            @csrf
                            @method('DELETE')

                            <div>
                                <label for="deletion-password" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                    Confirm your password
                                </label>

                                <input
                                    id="deletion-password"
                                    type="password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm outline-none transition focus:border-red-500 focus:ring-4 focus:ring-red-100"
                                >
                            </div>

                            <div><label for="delete-confirmation" class="mb-1.5 block text-xs font-semibold text-stone-700">Type DELETE to confirm</label><input id="delete-confirmation" type="text" name="delete_confirmation" autocomplete="off" class="w-full rounded-xl border border-stone-300 px-3.5 py-2.5 text-sm uppercase outline-none transition focus:border-red-500 focus:ring-4 focus:ring-red-100" data-buyer-delete-confirmation></div>

                            <button
                                type="submit"
                                disabled
                                class="rounded-xl bg-red-600 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                                data-buyer-delete-submit
                            >
                                Close My Account
                            </button>
                        </form>
                    </div>
                </section>
            @endif

            @if ($activeTab === 'notifications')
                <section aria-labelledby="notifications-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="notifications-heading" class="text-base font-semibold text-stone-900">
                            Notification Settings
                        </h2>

                        <p class="mt-1 text-xs text-stone-500">
                            Choose which updates you want to receive.
                        </p>
                    </div>

                    <div class="divide-y divide-stone-100 p-5 sm:p-6">
                        @php
                            $notificationOptions = [
                                [
                                    'name' => 'Order updates',
                                    'description' => 'Payment, shipping, delivery, and return updates.',
                                    'checked' => true,
                                ],
                                [
                                    'name' => 'Messages',
                                    'description' => 'New messages from sellers and customer support.',
                                    'checked' => true,
                                ],
                                [
                                    'name' => 'Rewards and vouchers',
                                    'description' => 'Voucher availability, points, and cashback alerts.',
                                    'checked' => true,
                                ],
                                [
                                    'name' => 'Promotions and recommendations',
                                    'description' => 'Product suggestions, deals, and marketplace campaigns.',
                                    'checked' => false,
                                ],
                                [
                                    'name' => 'Security alerts',
                                    'description' => 'Sign-ins, password changes, and important account activity.',
                                    'checked' => true,
                                ],
                            ];
                        @endphp

                        @foreach ($notificationOptions as $option)
                            <label class="flex cursor-pointer items-start justify-between gap-5 py-4 first:pt-0 last:pb-0">
                                <span>
                                    <strong class="block text-sm font-semibold text-stone-800">
                                        {{ $option['name'] }}
                                    </strong>

                                    <span class="mt-1 block max-w-xl text-xs leading-5 text-stone-500">
                                        {{ $option['description'] }}
                                    </span>
                                </span>

                                <span class="relative mt-0.5 inline-flex shrink-0">
                                    <input
                                        type="checkbox"
                                        class="peer sr-only"
                                        @checked($option['checked'])
                                    >

                                    <span class="h-6 w-11 rounded-full bg-stone-300 transition peer-checked:bg-red-900 peer-focus-visible:ring-4 peer-focus-visible:ring-red-100"></span>

                                    <span class="pointer-events-none absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow-sm transition peer-checked:translate-x-5"></span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="flex justify-end border-t border-stone-100 px-5 py-4 sm:px-6">
                        <button
                            type="button"
                            class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950"
                        >
                            Save Notification Settings
                        </button>
                    </div>
                </section>
            @endif
        </main>
    </div>
</div>
@if($activeTab === 'danger')
<script>
(() => {
    const form = document.querySelector('[data-buyer-delete-form]');
    const password = document.getElementById('deletion-password');
    const confirmation = document.querySelector('[data-buyer-delete-confirmation]');
    const submit = document.querySelector('[data-buyer-delete-submit]');
    if (!form || !password || !confirmation || !submit) return;
    const sync = () => { submit.disabled = !password.value || confirmation.value.trim() !== 'DELETE'; };
    password.addEventListener('input', sync); confirmation.addEventListener('input', sync);
})();
</script>
@endif
@if($activeTab === 'profile')
<script>
(() => {
    const form = document.getElementById('buyerProfileForm');
    const email = document.getElementById('profile-email');
    const phone = document.getElementById('profile-phone');
    const dialog = document.querySelector('[data-profile-otp-dialog]');
    const code = document.querySelector('[data-profile-otp-code]');
    const target = document.querySelector('[data-profile-otp-target]');
    const title = document.querySelector('[data-profile-otp-title]');
    const description = document.querySelector('[data-profile-otp-description]');
    const error = document.querySelector('[data-profile-otp-error]');
    const status = document.querySelector('[data-profile-otp-status]');
    const formStatus = document.querySelector('[data-profile-form-status]');
    const resend = document.querySelector('[data-profile-otp-resend]');
    if (!form || !email || !phone || !dialog || !code) return;

    const originalEmail = (form.dataset.originalEmail || '').trim().toLowerCase();
    const originalPhone = (form.dataset.originalPhone || '').trim();
    const emailChanged = () => email.value.trim().toLowerCase() !== originalEmail;
    const phoneChanged = () => phone.value.trim() !== originalPhone;
    let activePurpose = dialog.dataset.profileOtpPurpose || 'email_change';
    let isSubmitting = false;
    const setPurpose = (purpose, recipient = '') => {
        activePurpose = purpose === 'mobile_change' ? 'mobile_change' : 'email_change';
        dialog.dataset.profileOtpPurpose = activePurpose;
        code.name = activePurpose === 'mobile_change' ? 'mobile_change_code' : 'email_change_code';
        if (title) title.textContent = activePurpose === 'mobile_change' ? 'Confirm your mobile number change' : 'Verify your email change';
        if (description) description.textContent = activePurpose === 'mobile_change'
            ? 'Enter the six-digit code sent to your verified email address'
            : 'Enter the six-digit code sent to';
        if (target && recipient) target.textContent = recipient;
    };
    const lockPurposeField = () => {
        email.readOnly = activePurpose === 'email_change';
        phone.readOnly = activePurpose === 'mobile_change';
    };
    const openDialog = (purpose = activePurpose, message = '', recipient = '') => {
        setPurpose(purpose, recipient || (purpose === 'mobile_change' ? originalEmail : email.value.trim()));
        dialog.style.display = 'flex';
        lockPurposeField();
        if (error) error.textContent = '';
        if (status && message) status.textContent = message;
        code.focus();
    };
    const closeDialog = () => {
        dialog.style.display = 'none';
        email.readOnly = false;
        phone.readOnly = false;
        code.value = '';
    };
    if (dialog.style.display !== 'none') lockPurposeField();
    const setBusy = (busy) => {
        const submit = dialog.querySelector('[data-profile-otp-submit]');
        if (submit) {
            submit.disabled = busy;
            submit.textContent = busy ? 'Please wait…' : 'Verify and Save Changes';
        }
        if (resend) resend.disabled = busy;
    };
    const postForm = async (body) => fetch(form.action, {
        method: 'POST',
        body,
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });

    form.addEventListener('submit', async (event) => {
        const changingEmail = emailChanged();
        const changingPhone = phoneChanged();
        if (!changingEmail && !changingPhone) return;
        event.preventDefault();
        if (isSubmitting) return;

        const dialogIsOpen = dialog.style.display !== 'none';
        if (dialogIsOpen && !code.value.trim()) {
            if (error) error.textContent = 'Enter the six-digit code to save your changes.';
            code.focus();
            return;
        }

        const body = new FormData(form);
        const verifying = code.value.trim().length > 0;
        if (error) error.textContent = '';
        if (formStatus) formStatus.textContent = '';
        isSubmitting = true;
        setBusy(true);
        try {
            const response = await postForm(body);
            const result = await response.json();
            if (result.requires_verification) {
                openDialog(result.verification_for || (changingPhone ? 'mobile_change' : 'email_change'), result.message || 'A verification code was sent.', result.target || '');
                return;
            }
            if (result.saved && result.redirect) {
                window.location.assign(result.redirect);
                return;
            }

            const firstError = result.errors ? Object.values(result.errors).flat()[0] : null;
            const message = firstError || result.message || 'The profile could not be saved. Please review your details and try again.';
            if (verifying) {
                openDialog(activePurpose);
                if (error) error.textContent = message;
            } else if (formStatus) {
                formStatus.textContent = message;
            }
        } catch (exception) {
            const message = 'Could not reach the server. Check your connection and try again.';
            if (code.value.trim()) {
                openDialog(activePurpose);
                if (error) error.textContent = message;
            } else if (formStatus) {
                formStatus.textContent = message;
            }
        } finally {
            setBusy(false);
            isSubmitting = false;
        }
    });

    const close = document.querySelector('[data-profile-otp-close]');
    if (close) close.addEventListener('click', closeDialog);
    if (resend) resend.addEventListener('click', async () => {
        if (error) error.textContent = '';
        if (status) status.textContent = 'Sending a new code…';
        resend.disabled = true;
        try {
            const body = new FormData();
            body.append('_token', form.querySelector('input[name="_token"]').value);
            const resendUrl = activePurpose === 'mobile_change'
                ? @json(route('buyer.account.mobile.resend'))
                : @json(route('buyer.account.email.resend'));
            const response = await fetch(resendUrl, {
                method: 'POST', body,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const result = await response.json();
            const firstError = result.errors ? Object.values(result.errors).flat()[0] : null;
            if (!response.ok) throw new Error(firstError || result.message || 'A new code could not be sent yet.');
            if (status) status.textContent = result.message || 'A new code was sent.';
        } catch (exception) {
            if (status) status.textContent = exception.message || 'A new code could not be sent yet.';
        } finally {
            resend.disabled = false;
        }
    });
})();
</script>
@endif
@endsection
