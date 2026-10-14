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
    $buyerBirthday = data_get($user, 'birthday', '');
    $buyerGender = data_get($user, 'sex', '');

    $profilePhoto = data_get($user, 'profile_photo_path')
        ?? data_get($user, 'profile_photo')
        ?? data_get($user, 'profile_picture')
        ?? null;

    $profilePhotoUrl = $profilePhoto
        ? (\Illuminate\Support\Str::startsWith($profilePhoto, ['http://', 'https://'])
            ? $profilePhoto
            : '/storage/'.ltrim($profilePhoto, '/'))
        : null;

    $buyerInitial = strtoupper(mb_substr($buyerName, 0, 1));

    $allowedTabs = [
        'profile',
        'addresses',
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
                        @if ($profilePhotoUrl)
                            <img
                                src="{{ $profilePhotoUrl }}"
                                alt="{{ $buyerName }}"
                                class="h-full w-full object-cover"
                            >
                        @else
                            {{ $buyerInitial }}
                        @endif
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
                        <form id="buyerProfileForm" method="POST" enctype="multipart/form-data" action="{{ route('buyer.account.profile.update') }}">
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
                                    <label for="profile-email" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                        Email address
                                    </label>

                                    <input
                                        id="profile-email"
                                        type="email"
                                        name="email"
                                        value="{{ old('email', $buyerEmail) }}"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
                                </div>

                                <div>
                                    <label for="profile-phone" class="mb-1.5 block text-xs font-semibold text-stone-700">
                                        Mobile number
                                    </label>

                                    <input
                                        id="profile-phone"
                                        type="tel"
                                        name="phone"
                                        value="{{ old('phone', $buyerPhone) }}"
                                        placeholder="09XX XXX XXXX"
                                        class="w-full rounded-xl border border-stone-300 bg-white px-3.5 py-2.5 text-sm text-stone-900 outline-none transition placeholder:text-stone-400 focus:border-red-800 focus:ring-4 focus:ring-red-100"
                                    >
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
                        </form>
                    </div>
                </section>
            @endif

            @if ($activeTab === 'addresses')
                <section aria-labelledby="addresses-heading">
                    <div class="border-b border-stone-100 px-5 py-4 sm:px-6">
                        <h2 id="addresses-heading" class="text-base font-semibold text-stone-900">Delivery Addresses</h2>
                        <p class="mt-1 text-xs text-stone-500">Manage delivery destinations. Changes apply to future orders; existing orders keep their original address.</p>
                    </div>
                    <div class="space-y-4 p-5 sm:p-6">
                        @forelse(collect($addresses ?? []) as $address)
                            <article class="rounded-2xl border {{ $address->is_default ? 'border-red-300 bg-red-50/40' : 'border-stone-200 bg-white' }} p-4 sm:p-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-stone-900">{{ $address->label }} <span class="text-stone-400">·</span> {{ $address->recipient_name }}</h3>
                                            @if($address->is_default)<span class="rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold text-red-900">Default address</span>@endif
                                        </div>
                                        <p class="mt-2 text-xs font-medium text-stone-600">{{ $address->contact_number }}</p>
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
                                                    <input required name="contact_number" value="{{ old('contact_number', $address->contact_number) }}" maxlength="30" inputmode="tel" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal">
                                                </label>
                                                <x-shared.philippine-address-fields :address="$address" wrapper-class="contents" />
                                                <x-shared.address-pin :address="$address" class="sm:col-span-2" />
                                                <label class="flex items-center gap-2 text-xs font-semibold text-stone-700 sm:col-span-2"><input type="checkbox" name="is_default" value="1" @checked($address->is_default)> Use as my default address</label>
                                                <div class="flex justify-end sm:col-span-2"><button type="submit" class="rounded-xl bg-red-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100">Save address</button></div>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('buyer.account.addresses.destroy', ['address' => $address->id]) }}" data-confirm-title="Delete saved address?" data-confirm-message="This saved address will be removed from your account." data-confirm-label="Delete address" data-confirm-tone="danger">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="rounded-lg px-2 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 focus:outline-none focus:ring-4 focus:ring-red-100">Delete</button>
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

                        <form method="POST" action="{{ route('buyer.account.addresses.store') }}" class="grid gap-4 rounded-2xl border border-stone-200 bg-stone-50 p-4 sm:p-5 sm:grid-cols-2">
                            @csrf
                            <div class="sm:col-span-2"><h3 class="text-sm font-semibold text-stone-900">Add delivery address</h3><p class="mt-1 text-xs text-stone-500">Choose a precise location so the courier can find you.</p></div>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Address label<input required name="label" value="{{ old('label','Home') }}" maxlength="50" placeholder="Home, Work" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Recipient name<input required name="recipient_name" value="{{ old('recipient_name',$buyerName) }}" maxlength="200" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <label class="grid gap-1 text-xs font-semibold text-stone-700">Contact number<input required name="contact_number" value="{{ old('contact_number',$buyerPhone) }}" maxlength="30" inputmode="tel" class="rounded-xl border border-stone-300 px-3 py-2.5 text-sm font-normal"></label>
                            <x-shared.philippine-address-fields wrapper-class="contents" />
                            <x-shared.address-pin class="sm:col-span-2" />
                            <label class="flex items-center gap-2 text-xs font-semibold text-stone-700 sm:col-span-2"><input type="checkbox" name="is_default" value="1" @checked(old('is_default', collect($addresses ?? [])->isEmpty()))> Use as my default address</label>
                            <div class="sm:col-span-2"><button type="submit" class="rounded-xl bg-red-900 px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950 focus:outline-none focus:ring-4 focus:ring-red-100">Save address</button></div>
                        </form>
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
                                Your password should contain at least eight characters,
                                one uppercase letter, one number, and one special character.
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
@endsection
