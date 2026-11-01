@extends('layouts.logistics.app')

@section('title', 'Logistics Account - LIKHAE')

@push('styles')
    <style>
        .logi-account-page {
            --account-border: var(--lk-border);
            --account-muted: var(--lk-muted);
            --account-soft: var(--lk-page);
            --account-active: #fbefed;
            --account-shadow: 0 1px 2px rgba(55, 32, 25, .06), 0 8px 24px rgba(55, 32, 25, .035);
            color: var(--lk-text);
        }

        .logi-account-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 20px;
        }

        .logi-account-eyebrow {
            margin: 0 0 5px;
            color: var(--lk-primary);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .logi-account-heading h1 {
            margin: 0;
            color: var(--lk-text);
            font-size: clamp(24px, 3vw, 30px);
            font-weight: 750;
            letter-spacing: -.04em;
            line-height: 1.2;
        }

        .logi-account-heading p:last-child {
            margin: 7px 0 0;
            color: var(--account-muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .logi-account-status {
            display: inline-flex;
            min-height: 30px;
            flex: 0 0 auto;
            align-items: center;
            gap: 8px;
            padding: 0 12px;
            border: 1px solid var(--lk-border-strong);
            border-radius: 999px;
            color: var(--lk-text-secondary);
            background: var(--lk-surface);
            font-size: 11px;
            font-weight: 700;
        }

        .logi-account-status::before {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--lk-primary);
            content: "";
        }

        .logi-account-alert {
            margin-bottom: 12px;
            padding: 12px 15px;
            border: 1px solid var(--account-border);
            border-radius: 10px;
            background: var(--lk-surface);
            font-size: 13px;
        }

        .logi-account-alert--success {
            border-color: #c9e2d1;
            color: #256f4a;
            background: #eaf7ef;
        }

        .logi-account-alert--error {
            border-color: #f1c8c3;
            color: #9d2c27;
            background: #fcebe9;
        }

        .logi-account-layout {
            display: grid;
            grid-template-columns: 218px minmax(0, 1fr);
            align-items: start;
            gap: 16px;
        }

        .logi-account-nav,
        .logi-account-section {
            overflow: hidden;
            border: 1px solid var(--account-border);
            border-radius: 15px;
            background: var(--lk-surface);
            box-shadow: var(--account-shadow);
        }

        .logi-account-nav {
            position: sticky;
            top: 78px;
            padding: 9px;
        }

        .logi-account-identity {
            display: flex;
            min-width: 0;
            align-items: center;
            gap: 10px;
            padding: 8px 7px 13px;
            border-bottom: 1px solid var(--account-border);
        }

        .logi-account-avatar {
            display: grid;
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            place-items: center;
            overflow: hidden;
            border: 1px solid var(--account-border);
            border-radius: 50%;
            background: var(--lk-page-secondary);
        }

        .logi-account-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logi-account-identity-copy {
            display: grid;
            min-width: 0;
            gap: 3px;
        }

        .logi-account-identity-copy strong,
        .logi-account-identity-copy small {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .logi-account-identity-copy strong {
            color: var(--lk-text);
            font-size: 11px;
            font-weight: 750;
        }

        .logi-account-identity-copy small {
            color: var(--account-muted);
            font-size: 9px;
        }

        .logi-account-tab-list {
            display: grid;
            gap: 3px;
            padding-top: 8px;
        }

        .logi-account-tab {
            display: flex;
            min-height: 42px;
            align-items: center;
            gap: 10px;
            padding: 7px 9px;
            border-radius: 9px;
            color: var(--lk-text-secondary);
            text-decoration: none;
            transition: color .16s ease, background-color .16s ease;
        }

        .logi-account-tab:hover,
        .logi-account-tab:focus-visible,
        .logi-account-tab.is-active {
            color: var(--lk-primary);
            background: var(--account-active);
        }

        .logi-account-tab:focus-visible {
            outline: 2px solid var(--lk-primary);
            outline-offset: 2px;
        }

        .logi-account-tab-icon {
            display: grid;
            width: 18px;
            height: 18px;
            flex: 0 0 18px;
            place-items: center;
        }

        .logi-account-tab-icon svg {
            width: 16px;
            height: 16px;
            fill: none;
            stroke: currentColor;
            stroke-linecap: round;
            stroke-linejoin: round;
            stroke-width: 1.6;
        }

        .logi-account-tab-copy {
            display: grid;
            gap: 2px;
        }

        .logi-account-tab-copy strong {
            color: inherit;
            font-size: 10px;
            font-weight: 750;
            line-height: 1.2;
        }

        .logi-account-tab-copy small {
            color: var(--account-muted);
            font-size: 8px;
            line-height: 1.2;
        }

        .logi-account-nav-logout {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--account-border);
        }

        .logi-account-nav-logout button {
            display: flex;
            width: 100%;
            min-height: 38px;
            align-items: center;
            gap: 10px;
            padding: 7px 9px;
            border: 0;
            border-radius: 9px;
            color: #9d2c27;
            background: transparent;
            font: inherit;
            font-size: 10px;
            font-weight: 700;
            text-align: left;
        }

        .logi-account-nav-logout button:hover {
            background: #fcebe9;
        }

        .logi-account-sections {
            display: grid;
            min-width: 0;
            gap: 15px;
        }

        .logi-account-section {
            scroll-margin-top: 78px;
        }

        .logi-account-section[hidden] {
            display: none !important;
        }

        .logi-account-section-head {
            display: flex;
            min-height: 64px;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 15px 20px;
            border-bottom: 1px solid var(--account-border);
        }

        .logi-account-section-head h2 {
            margin: 0;
            color: var(--lk-text);
            font-size: 14px;
            font-weight: 750;
            line-height: 1.35;
        }

        .logi-account-section-head p {
            margin: 4px 0 0;
            color: var(--account-muted);
            font-size: 11px;
            line-height: 1.45;
        }

        .logi-account-section-body {
            display: grid;
            gap: 16px;
            padding: 18px 20px 20px;
        }

        .logi-account-photo {
            padding: 12px 14px;
            border: 1px solid var(--account-border);
            border-radius: 12px;
            background: var(--account-soft);
        }

        .logi-account-fields {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 13px 15px;
        }

        .logi-account-label {
            display: grid;
            min-width: 0;
            gap: 6px;
            color: var(--lk-text);
            font-size: 10px;
            font-weight: 700;
        }

        .logi-account-label input,
        .logi-account-password-form input {
            width: 100%;
            min-width: 0;
            min-height: 41px;
            padding: 9px 12px;
            border: 1px solid var(--lk-border-strong);
            border-radius: 8px;
            outline: none;
            color: var(--lk-text);
            background: var(--lk-surface);
            font-size: 12px;
            font-weight: 450;
            transition: border-color .15s ease, box-shadow .15s ease;
        }

        .logi-account-label input:focus,
        .logi-account-password-form input:focus {
            border-color: #a96f5e;
            box-shadow: 0 0 0 3px rgba(86, 28, 23, .1);
        }

        .logi-account-form-actions {
            display: flex;
            justify-content: flex-end;
            padding-top: 13px;
            border-top: 1px solid var(--account-border);
        }

        .logi-account-primary-button {
            display: inline-flex;
            min-height: 40px;
            align-items: center;
            justify-content: center;
            padding: 0 17px;
            border: 1px solid var(--lk-primary);
            border-radius: 9px;
            color: #fff;
            background: var(--lk-primary);
            font-size: 11px;
            font-weight: 750;
            transition: background-color .15s ease, transform .15s ease;
        }

        .logi-account-primary-button:hover {
            background: var(--lk-primary-hover);
            transform: translateY(-1px);
        }

        .logi-account-details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            margin: 0;
        }

        .logi-account-details-grid > div {
            min-width: 0;
            padding: 12px 13px;
            border: 1px solid var(--account-border);
            border-radius: 10px;
            background: var(--account-soft);
        }

        .logi-account-details-grid dt {
            color: var(--account-muted);
            font-size: 9px;
            font-weight: 750;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .logi-account-details-grid dd {
            margin: 5px 0 0;
            overflow-wrap: anywhere;
            color: var(--lk-text);
            font-size: 12px;
            font-weight: 650;
            line-height: 1.45;
        }

        .logi-account-documents {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .logi-account-document {
            min-width: 0;
            padding: 13px;
            border: 1px solid var(--account-border);
            border-radius: 11px;
            background: var(--account-soft);
        }

        .logi-account-document-form {
            display: grid;
            gap: 13px;
            padding-top: 16px;
            border-top: 1px solid var(--account-border);
        }

        .logi-account-document-form label {
            display: grid;
            min-width: 0;
            gap: 7px;
            color: var(--lk-text);
            font-size: 10px;
            font-weight: 700;
        }

        .logi-account-document-form input[type="file"] {
            width: 100%;
            min-width: 0;
            padding: 9px;
            border: 1px solid var(--account-border);
            border-radius: 8px;
            color: var(--lk-text-secondary);
            background: var(--lk-surface);
            font-size: 10px;
        }

        .logi-account-document-form small {
            color: var(--account-muted);
            font-size: 9px;
            font-weight: 450;
            line-height: 1.45;
        }

        .logi-account-password-form {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            padding: 18px 20px 20px;
        }

        .logi-account-password-form h3 {
            grid-column: 1 / -1;
            margin: 0;
            color: var(--lk-text);
            font-size: 12px;
        }

        .logi-account-password-form button {
            grid-column: 1 / -1;
            justify-self: end;
        }

        .logi-account-address-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            padding: 18px 20px 20px;
        }

        .logi-account-address-form > div:first-child,
        .logi-account-address-form > .md\:col-span-2 {
            grid-column: 1 / -1;
        }

        .logi-account-address-form label {
            color: var(--lk-text);
            font-size: 10px;
            font-weight: 700;
        }

        .logi-account-address-form label input,
        .logi-account-address-form label select {
            width: 100%;
            min-width: 0;
            min-height: 41px;
            border-radius: 8px;
            color: var(--lk-text);
            background: var(--lk-surface);
            font-size: 12px;
        }

        .logi-account-address-form > .md\:col-span-2 > button {
            display: inline-flex;
            min-height: 40px;
            align-items: center;
            justify-content: center;
            padding: 0 17px;
            border: 1px solid var(--lk-primary);
            border-radius: 9px;
            color: #fff;
            background: var(--lk-primary);
            font-size: 11px;
            font-weight: 750;
        }

        html.dark .logi-account-page {
            --account-active: #351e1a;
            --account-shadow: 0 1px 2px rgba(0, 0, 0, .14), 0 8px 24px rgba(0, 0, 0, .10);
        }

        html.dark .logi-account-alert--success {
            border-color: #36513e;
            color: #b4d9bf;
            background: #1d2b21;
        }

        html.dark .logi-account-alert--error {
            border-color: #633b37;
            color: #f3b3a9;
            background: #321d1a;
        }

        html.dark .logi-account-status {
            color: var(--lk-text-secondary);
        }

        html.dark .logi-account-nav-logout button:hover {
            background: #321d1a;
        }

        @media (max-width: 900px) {
            .logi-account-layout {
                grid-template-columns: 1fr;
            }

            .logi-account-nav {
                position: static;
            }

            .logi-account-tab-list {
                display: flex;
                overflow-x: auto;
                gap: 5px;
                padding: 8px 0 1px;
                scrollbar-width: thin;
            }

            .logi-account-tab {
                min-width: 126px;
                flex: 0 0 auto;
            }

        }

        @media (max-width: 600px) {
            .logi-account-heading {
                align-items: flex-start;
                flex-direction: column;
                gap: 12px;
            }

            .logi-account-layout {
                gap: 12px;
            }

            .logi-account-section-head {
                align-items: flex-start;
                padding: 14px 15px;
            }

            .logi-account-section-body,
            .logi-account-address-form,
            .logi-account-password-form {
                padding: 15px;
            }

            .logi-account-fields,
            .logi-account-details-grid,
            .logi-account-documents,
            .logi-account-address-form,
            .logi-account-password-form {
                grid-template-columns: 1fr;
            }

            .logi-account-password-form h3,
            .logi-account-password-form button,
            .logi-account-address-form > div:first-child,
            .logi-account-address-form > .md\:col-span-2 {
                grid-column: auto;
            }

            .logi-account-form-actions {
                padding-top: 12px;
            }

            .logi-account-primary-button,
            .logi-account-address-form > .md\:col-span-2 > button {
                width: 100%;
            }

        }
    </style>
@endpush

@section('content')
    @php
        $accountErrorKeys = $errors->keys();
        $hasAccountError = static fn (array $fields): bool => count(array_intersect($accountErrorKeys, $fields)) > 0;
        $hasDocumentError = count(array_filter($accountErrorKeys, static fn (string $key): bool => str_starts_with($key, 'documents.'))) > 0;
        $activeAccountTab = match (true) {
            $hasAccountError(['current_password', 'password', 'password_confirmation']) => 'account-security',
            $hasAccountError(['region_code', 'region_name', 'province_code', 'province_name', 'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name', 'postal_code', 'street_address', 'house_number', 'landmark', 'latitude', 'longitude']) => 'center-address',
            $hasAccountError(['payout', 'payout_method', 'payout_bank_name', 'payout_account_name', 'payout_account_type', 'payout_account_number', 'destination_id', 'destination_action', 'make_default']) => 'payout-details',
            $hasDocumentError => 'verification-documents',
            $hasAccountError(['first_name', 'last_name', 'email', 'contact_number', 'profile_photo', 'profile_photo_path']) => 'owner-profile',
            default => 'owner-profile',
        };
    @endphp
    <div class="logi-account-page">
        <header class="logi-account-heading">
            <div>
                <p class="logi-account-eyebrow">Logistics Center</p>
                <h1>Account Management</h1>
                <p>Manage owner details, center information, verification, and payout preferences.</p>
            </div>
            <span class="logi-account-status">{{ str($center->status)->headline() }}</span>
        </header>

        @if(session('status'))
            <div class="logi-account-alert logi-account-alert--success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="logi-account-alert logi-account-alert--error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="logi-account-layout">
            <nav class="logi-account-nav" aria-label="Account sections">
                <div class="logi-account-identity">
                    <span class="logi-account-avatar">
                        <x-shared.profile-image :path="$user->profile_photo_path" role="buyer" :alt="$user->name" />
                    </span>
                    <span class="logi-account-identity-copy">
                        <strong>{{ $user->name }}</strong>
                        <small>{{ $user->email }}</small>
                    </span>
                </div>

                <div class="logi-account-tab-list" role="tablist" aria-label="Account sections" aria-orientation="vertical">
                    <a id="tab-owner-profile" href="#owner-profile" class="logi-account-tab{{ $activeAccountTab === 'owner-profile' ? ' is-active' : '' }}" role="tab" aria-controls="owner-profile" aria-selected="{{ $activeAccountTab === 'owner-profile' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'owner-profile' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Profile</strong><small>Owner information</small></span>
                    </a>
                    <a id="tab-center-information" href="#center-information" class="logi-account-tab{{ $activeAccountTab === 'center-information' ? ' is-active' : '' }}" role="tab" aria-controls="center-information" aria-selected="{{ $activeAccountTab === 'center-information' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'center-information' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 20V8l9-5 9 5v12H3Z"/><path d="M8 20v-6h8v6M7 9h.01M12 9h.01M17 9h.01"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Center</strong><small>Registered details</small></span>
                    </a>
                    <a id="tab-verification-documents" href="#verification-documents" class="logi-account-tab{{ $activeAccountTab === 'verification-documents' ? ' is-active' : '' }}" role="tab" aria-controls="verification-documents" aria-selected="{{ $activeAccountTab === 'verification-documents' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'verification-documents' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3h7l5 5v13H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M14 3v6h5M9 14h6M9 17h6"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Verification</strong><small>Required documents</small></span>
                    </a>
                    <a id="tab-payout-details" href="#payout-details" class="logi-account-tab{{ $activeAccountTab === 'payout-details' ? ' is-active' : '' }}" role="tab" aria-controls="payout-details" aria-selected="{{ $activeAccountTab === 'payout-details' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'payout-details' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Payouts</strong><small>Payment destination</small></span>
                    </a>
                    <a id="tab-center-address" href="#center-address" class="logi-account-tab{{ $activeAccountTab === 'center-address' ? ' is-active' : '' }}" role="tab" aria-controls="center-address" aria-selected="{{ $activeAccountTab === 'center-address' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'center-address' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.3"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Address</strong><small>Operating location</small></span>
                    </a>
                    <a id="tab-account-security" href="#account-security" class="logi-account-tab{{ $activeAccountTab === 'account-security' ? ' is-active' : '' }}" role="tab" aria-controls="account-security" aria-selected="{{ $activeAccountTab === 'account-security' ? 'true' : 'false' }}" tabindex="{{ $activeAccountTab === 'account-security' ? '0' : '-1' }}">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg></span>
                        <span class="logi-account-tab-copy"><strong>Security</strong><small>Password and sign out</small></span>
                    </a>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="logi-account-nav-logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
                    @csrf
                    <button type="submit">
                        <span class="logi-account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M15 16l5-4-5-4M20 12H9"/></svg></span>
                        <span>Logout</span>
                    </button>
                </form>
            </nav>

            <div class="logi-account-sections">
                <section class="logi-account-section" id="owner-profile" role="tabpanel" aria-labelledby="tab-owner-profile" tabindex="0" @if($activeAccountTab !== 'owner-profile') hidden @endif>
                    <header class="logi-account-section-head">
                        <div><h2>Profile Information</h2><p>Update the personal details for the center owner account.</p></div>
                    </header>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.account.profile.update') }}" class="logi-account-section-body logi-account-profile-form">
                        @csrf
                        @method('PATCH')
                        <div class="logi-account-photo"><x-shared.profile-photo-upload :user="$user" id="logistics-profile-photo" /></div>
                        <div class="logi-account-fields">
                            <label class="logi-account-label">First name<input name="first_name" value="{{ old('first_name', $user->first_name) }}" required></label>
                            <label class="logi-account-label">Last name<input name="last_name" value="{{ old('last_name', $user->last_name) }}" required></label>
                            <label class="logi-account-label">Email<input type="email" name="email" value="{{ old('email', $user->email) }}" required></label>
                            <label class="logi-account-label">Contact number<input name="contact_number" value="{{ old('contact_number', $user->contact_number) }}" required></label>
                        </div>
                        <div class="logi-account-form-actions"><button type="submit" class="logi-account-primary-button">Save Profile</button></div>
                    </form>
                </section>

                <section class="logi-account-section" id="center-information" role="tabpanel" aria-labelledby="tab-center-information" tabindex="0" @if($activeAccountTab !== 'center-information') hidden @endif>
                    <header class="logi-account-section-head">
                        <div><h2>Center Information</h2><p>Registered business information for this logistics center.</p></div>
                    </header>
                    <div class="logi-account-section-body">
                        <dl class="logi-account-details-grid">
                            <div><dt>Center code</dt><dd>{{ $center->code }}</dd></div>
                            <div><dt>Business name</dt><dd>{{ $center->business_name }}</dd></div>
                            <div><dt>Registered address</dt><dd>{{ $center->address?->formatted() }}</dd></div>
                            <div><dt>Account status</dt><dd>{{ str($center->status)->headline() }}</dd></div>
                        </dl>
                    </div>
                </section>

                <section class="logi-account-section" id="verification-documents" role="tabpanel" aria-labelledby="tab-verification-documents" tabindex="0" @if($activeAccountTab !== 'verification-documents') hidden @endif>
                    <header class="logi-account-section-head">
                        <div><h2>Verification Documents</h2><p>Review current requirements and submit documents requested by Admin.</p></div>
                    </header>
                    <div class="logi-account-section-body">
                        <p class="text-sm leading-relaxed text-muted">Each new submission is reviewed before approval. JPG, PNG, WebP, or PDF, up to 5 MB.</p>
                        <div class="logi-account-documents">
                            @foreach($verificationDocumentTypes as $documentType)
                                @php
                                    $document = $verificationDocuments->get($documentType);
                                    $documentStatus = strtoupper((string) $document?->verification_status);
                                    $adminRequestedUpdate = $document && isset($adminRequestedDocumentUpdateIds[$document->id]);
                                    $missingRequest = ! $document ? ($adminMissingDocumentRequestsByType[$documentType] ?? null) : null;
                                    $documentLabel = \App\Models\Auth\ApplicationDocument::DOCUMENT_LABELS[$documentType];
                                @endphp
                                <article class="logi-account-document">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div><strong class="text-sm">{{ $documentLabel }}</strong><small class="mt-1 block text-muted">{{ $document?->original_name ?: 'Not submitted' }}</small></div>
                                        <span class="rounded-full border px-2 py-1 text-xs font-semibold {{ $documentStatus === 'VERIFIED' ? 'border-green-200 bg-green-50 text-green-800' : (($documentStatus === 'PENDING' || $missingRequest) ? 'border-amber-200 bg-amber-50 text-amber-800' : ($documentStatus === 'REJECTED' ? 'border-red-200 bg-red-50 text-red-800' : 'border-line text-muted')) }}">{{ $documentStatus === 'VERIFIED' ? 'Verified' : ($documentStatus === 'PENDING' ? 'Pending Admin review' : ($documentStatus === 'REJECTED' ? ($adminRequestedUpdate ? 'Update requested' : 'Needs replacement') : ($missingRequest ? 'Requested by Admin' : 'Missing'))) }}</span>
                                    </div>
                                    @if($missingRequest)
                                        <p class="mt-2 text-sm text-amber-900">Admin requested this document: {{ data_get($missingRequest->new_values, 'reason') }}</p>
                                    @elseif($documentStatus === 'REJECTED' && $document?->rejection_reason)
                                        <p class="mt-2 text-sm text-red-800">{{ $adminRequestedUpdate ? 'Admin requested an update:' : 'Adminâ€™s reason:' }} {{ $document->rejection_reason }}</p>
                                    @elseif($documentStatus === 'PENDING')
                                        <p class="mt-2 text-sm text-muted">Your current approved document remains on file while Admin reviews this submission.</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                        <form method="POST" enctype="multipart/form-data" action="{{ route('logistics.profile.verification-documents.store') }}" class="logi-account-document-form md:grid-cols-2">
                            @csrf
                            @foreach(\App\Models\Auth\ApplicationDocument::LOGISTICS_UPLOAD_FIELDS as $field => $documentType)
                                @php($document = $verificationDocuments->get($documentType))
                                @php($isPending = strtoupper((string) $document?->verification_status) === 'PENDING')
                                @php($missingRequest = ! $document ? ($adminMissingDocumentRequestsByType[$documentType] ?? null) : null)
                                <label>{{ \App\Models\Auth\ApplicationDocument::DOCUMENT_LABELS[$documentType] }}
                                    <input type="file" name="documents[{{ $field }}]" accept=".jpg,.jpeg,.png,.webp,.pdf" @disabled($isPending)>
                                    <small>{{ $isPending ? 'Already waiting for Admin review.' : ($missingRequest ? 'Admin requested this requirement. Upload the document for review.' : 'Optional. Submit a new document or replacement for Admin review.') }}</small>
                                </label>
                            @endforeach
                            <div class="flex flex-wrap items-center justify-between gap-3 md:col-span-2"><small class="text-muted">Choose at least one document. Maximum file size is 5 MB per document.</small><button type="submit" class="logi-account-primary-button">Submit documents for review</button></div>
                        </form>
                    </div>
                </section>

                <section class="logi-account-section payout-section" id="payout-details" role="tabpanel" aria-labelledby="tab-payout-details" tabindex="0" @if($activeAccountTab !== 'payout-details') hidden @endif>
                    <header class="payout-section-head"><div><span class="payout-overline">Payout destination</span><h2>Payment Details</h2><p>Set where Admin should send approved Logistics payouts outside Likhae. The transfer reference and proof will appear in your wallet.</p></div>@if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif</header>
                    <div class="payout-section-body">
                        @if($payoutDestinationReady)
                            @if($payoutMultipleReady)
                                @include('components.shared.payout-destination-manager', [
                                    'payoutDestinationRoute' => 'logistics.wallet.payout-destination.update',
                                    'payoutChangeDescription' => 'Changes apply to future Logistics payouts.',
                                    'payoutPurpose' => 'Logistics payouts',
                                    'payoutSecurityCopy' => 'Admin uses your default destination for approved Logistics payouts and records each transfer in your wallet.',
                                    'payoutHelpId' => 'logistics-multiple-payout-number-help',
                                    'payoutButtonClass' => 'logi-account-primary-button',
                                ])
                            @else
                                @if($payoutDestinationSaved)
                                    @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($center))
                                    <dl class="payout-summary">
                                        <div><dt>Saved payment channel</dt><dd>{{ $center->payout_method }}@if($center->payout_bank_name) · {{ $center->payout_bank_name }}@endif</dd></div>
                                        <div><dt>Account holder</dt><dd>{{ $center->payout_account_name }}</dd></div>
                                        <div><dt>{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd>{{ \App\Support\PayoutAccountDetails::formatNumber($center->payout_account_number, $savedPayoutType) }}</dd></div>
                                    </dl>
                                @endif
                                @php($payoutAccountType = old('payout_account_type', \App\Support\PayoutAccountDetails::typeFor($center)))
                                @php($payoutMethod = old('payout_method', $center->payout_method))
                                <div class="payout-editor">
                                    <div class="payout-editor__head"><div><span class="payout-editor__eyebrow">Payout settings</span><h3>{{ $payoutDestinationSaved ? 'Update destination' : 'Add a destination' }}</h3></div><p>Changes apply to future payouts.</p></div>
                                    <form method="POST" action="{{ route('logistics.wallet.payout-destination.update') }}" class="payout-form payout-fields-grid" data-payout-destination-form>
                                        @csrf @method('PUT')
                                        <label class="grid gap-1 text-sm font-medium">Payment channel<select name="payout_method" required class="border border-line px-3 py-2"><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($payoutMethod === $method)>{{ $method }}</option>@endforeach</select></label>
                                        <label class="grid gap-1 text-sm font-medium payout-bank-field" data-payout-bank-field @if($payoutMethod !== 'Bank Transfer') hidden @endif>Bank name<input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $center->payout_bank_name) }}" autocomplete="organization" class="border border-line px-3 py-2"></label>
                                        <label class="grid gap-1 text-sm font-medium">Account holder name<input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $center->payout_account_name) }}" required autocomplete="name" class="border border-line px-3 py-2"></label>
                                        <label class="grid gap-1 text-sm font-medium">Destination number type<select name="payout_account_type" data-payout-account-type required class="border border-line px-3 py-2"><option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option><option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option></select></label>
                                        <label class="grid gap-1 text-sm font-medium"><span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span><input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $center->payout_account_number) }}" required autocomplete="off" aria-describedby="logistics-payout-number-help" class="border border-line px-3 py-2"><small id="logistics-payout-number-help" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small></label>
                                        <div class="payout-security-note is-wide"><span class="payout-security-note__icon"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M6.5 9V6a3.5 3.5 0 0 1 7 0v3"/></svg></span><div><strong>Protected payout details</strong><p>Your account holder name and number are encrypted at rest. Admin transfers approved payouts outside Likhae and adds the reference and proof to your wallet. Card numbers and security codes are not collected.</p></div></div>
                                        <div class="payout-form-actions is-wide"><small>Check the number carefully before saving.</small><button type="submit" class="logi-account-primary-button">Save payout details</button></div>
                                    </form>
                                </div>
                            @endif
                        @else
                            <p class="payout-empty-state">Payout details are temporarily unavailable while the payout setup is being updated.</p>
                        @endif
                    </div>
                </section>

                <form method="POST" action="{{ route('logistics.account.address.update') }}" class="logi-account-section logi-account-address-form" id="center-address" role="tabpanel" aria-labelledby="tab-center-address" tabindex="0" @if($activeAccountTab !== 'center-address') hidden @endif>
                    @csrf
                    @method('PATCH')
                    <div>
                        <h2 class="m-0 text-sm font-bold text-ink">Registered Sorting Center Address</h2>
                        <p class="mt-1 text-xs text-muted">Use the Philippine address list for the center’s operating address.</p>
                    </div>
                    <x-shared.philippine-address-fields :address="$center->address" field-class="border border-line px-3 py-2" wrapper-class="contents" />
                    <x-shared.address-pin :address="$center->address" class="md:col-span-2" />
                    <div class="md:col-span-2"><button type="submit">Save Center Address</button></div>
                </form>

                <section class="logi-account-section" id="account-security" role="tabpanel" aria-labelledby="tab-account-security" tabindex="0" @if($activeAccountTab !== 'account-security') hidden @endif>
                    <header class="logi-account-section-head">
                        <div><h2>Security</h2><p>Keep your account access up to date.</p></div>
                    </header>
                    <form method="POST" action="{{ route('logistics.account.password.update') }}" class="logi-account-password-form">
                        @csrf
                        @method('PATCH')
                        <h3>Change Password</h3>
                        <input name="current_password" type="password" placeholder="Current password" required aria-label="Current password" autocomplete="current-password">
                        <input name="password" type="password" placeholder="New password" required aria-label="New password" autocomplete="new-password">
                        <input name="password_confirmation" type="password" placeholder="Confirm password" required aria-label="Confirm password" autocomplete="new-password">
                        <button type="submit" class="logi-account-primary-button">Update Password</button>
                    </form>
                </section>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const page = document.querySelector('.logi-account-page');
            if (!page) return;

            const tabList = page.querySelector('[role="tablist"]');
            const tabs = [...page.querySelectorAll('[role="tab"]')];
            const panels = tabs.map((tab) => page.querySelector(`#${tab.getAttribute('aria-controls')}`));
            const defaultTab = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0];

            const tabForHash = () => tabs.find((tab) => tab.hash === window.location.hash);
            const activate = (tab, { focus = false, urlMode = 'none' } = {}) => {
                if (!tab) return;

                tabs.forEach((item, index) => {
                    const selected = item === tab;
                    item.classList.toggle('is-active', selected);
                    item.setAttribute('aria-selected', selected ? 'true' : 'false');
                    item.tabIndex = selected ? 0 : -1;
                    const panel = panels[index];
                    if (panel) panel.hidden = !selected;
                });

                if (urlMode === 'push') window.history.pushState({ accountTab: tab.hash }, '', tab.hash);
                if (urlMode === 'replace') window.history.replaceState({ accountTab: tab.hash }, '', tab.hash);
                if (focus) tab.focus();
            };

            tabs.forEach((tab) => tab.addEventListener('click', (event) => {
                event.preventDefault();
                activate(tab, { urlMode: 'push' });
            }));

            tabList?.addEventListener('keydown', (event) => {
                const currentTab = event.target.closest('[role="tab"]');
                const currentIndex = tabs.indexOf(currentTab);
                if (currentIndex < 0) return;

                let nextIndex = currentIndex;
                if (event.key === 'ArrowDown' || event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabs.length;
                else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') nextIndex = 0;
                else if (event.key === 'End') nextIndex = tabs.length - 1;
                else return;

                event.preventDefault();
                activate(tabs[nextIndex], { focus: true, urlMode: 'replace' });
            });

            const syncOrientation = () => tabList?.setAttribute(
                'aria-orientation',
                window.matchMedia('(max-width: 900px)').matches ? 'horizontal' : 'vertical'
            );
            syncOrientation();
            window.matchMedia('(max-width: 900px)').addEventListener?.('change', syncOrientation);

            activate(tabForHash() || defaultTab);
            window.addEventListener('popstate', () => activate(tabForHash() || defaultTab));
            window.addEventListener('hashchange', () => activate(tabForHash() || defaultTab));
        })();
    </script>
@endpush
