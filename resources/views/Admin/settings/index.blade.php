@extends('layouts.admin.app')
@section('title', 'Platform Settings')
@section('subtitle', 'Manage platform access and published policies.')
@section('active', 'settings')
@section('content')
@php
    $registrationEnabled = old('registration_enabled', $settings['registration_enabled']);
@endphp

<div class="ad-platform-settings">
    <header class="ad-page-head">
        <div>
            <span class="ad-overline">System configuration</span>
            <h2>Platform Settings</h2>
            <p>Update marketplace access and published policies. Platform commission is fixed by policy.</p>
        </div>
    </header>

    @if(session('status'))
        <div class="ad-platform-flash" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="ad-platform-flash ad-platform-flash--error" role="alert" aria-labelledby="platform-settings-error-title">
            <strong id="platform-settings-error-title">Please review these settings:</strong>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <nav class="ad-platform-tabs" aria-label="System configuration sections">
        <a href="{{ route('admin.settings', ['tab' => 'platform']) }}" class="{{ $tab === 'platform' ? 'is-active' : '' }}" @if($tab === 'platform') aria-current="page" @endif>Platform</a>
        <a href="{{ route('admin.settings', ['tab' => 'policies']) }}" class="{{ $tab === 'policies' ? 'is-active' : '' }}" @if($tab === 'policies') aria-current="page" @endif>Policies</a>
    </nav>

    @if($tab === 'platform')
    <form method="POST" action="{{ route('admin.settings.update', ['tab' => 'platform']) }}" class="ad-platform-settings__form">
        @csrf
        @method('PATCH')
        <section class="ad-card ad-platform-card ad-platform-card--basics" aria-labelledby="platform-basics-title">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Platform</span>
                    <h2 id="platform-basics-title">Marketplace basics</h2>
                    <p>Platform identity and the fixed commission policy.</p>
                </div>
            </header>
            <div class="ad-platform-basics">
                <div class="ad-platform-facts">
                    <div class="ad-platform-fact">
                        <span>Platform name</span>
                        <strong>{{ $settings['platform_name'] }}</strong>
                    </div>
                    <div class="ad-platform-fact">
                        <span>Support email</span>
                        <strong>{{ $settings['support_email'] ?: 'Not configured' }}</strong>
                        <small>{{ $settings['support_email_from_account'] ? 'Using this Admin account email' : 'Configured platform support contact' }}</small>
                    </div>
                    <div class="ad-platform-fact ad-platform-fact--fixed">
                        <span>Platform commission</span>
                        <strong>10%</strong>
                        <small>Fixed by the approved platform policy</small>
                    </div>
                </div>

                <input type="hidden" name="registration_enabled" value="0">
                <div class="ad-platform-registration">
                    <div>
                        <strong id="registration-setting-title">New account registration</strong>
                        <small>Allow people to create new accounts on the platform.</small>
                    </div>
                    <label class="ad-platform-switch" for="registration-enabled">
                        <input id="registration-enabled" type="checkbox" name="registration_enabled" value="1" @checked($registrationEnabled)>
                        <span aria-hidden="true"></span>
                        <span class="ad-sr-only">Enable new account registration</span>
                    </label>
                </div>
            </div>
        </section>

        <div class="ad-platform-settings__actions">
            <p>Registration changes apply to new account sign-ups.</p>
            <button class="ad-btn ad-btn-primary">Save platform settings</button>
        </div>
    </form>
    @else
    <section class="ad-card ad-platform-card ad-platform-policies" aria-labelledby="platform-policies-title">
        <header class="ad-card-head">
            <div>
                <span class="ad-overline">Policies</span>
                <h2 id="platform-policies-title">Publish a platform policy</h2>
                <p>Published versions are immutable. A future effective date keeps the current version in force until then.</p>
            </div>
        </header>
        <div class="ad-platform-card__body">
            @if($policySchemaReady)
                <form method="POST" action="{{ route('admin.policies.store', ['tab' => 'policies']) }}" class="ad-platform-policy-form">
                    @csrf
                    <label class="ad-field">
                        <span>Policy title</span>
                        <input name="title" value="{{ old('title') }}" maxlength="255" placeholder="For example: Terms & Conditions" required>
                        @error('title')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Policy type</span>
                        <select name="policy_type" required>
                            <option value="TERMS" @selected(old('policy_type', 'TERMS') === 'TERMS')>Terms & Conditions</option>
                            <option value="PRIVACY" @selected(old('policy_type') === 'PRIVACY')>Privacy Policy</option>
                            <option value="PLATFORM" @selected(old('policy_type') === 'PLATFORM')>Platform Policy</option>
                            <option value="CUSTOM" @selected(old('policy_type') === 'CUSTOM')>Other mandatory policy</option>
                        </select>
                        @error('policy_type')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Applies to</span>
                        <select name="audience" required>
                            @foreach(['ALL' => 'All account types', 'BUYER' => 'Buyers', 'SELLER' => 'Sellers', 'LOGISTICS' => 'Logistics Providers', 'RIDER' => 'Riders'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('audience', 'ALL') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('audience')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Version</span>
                        <input name="version" value="{{ old('version') }}" maxlength="50" placeholder="For example: 1.0" required>
                        @error('version')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Effective date <small>Blank means immediately</small></span>
                        <input type="datetime-local" name="effective_at" value="{{ old('effective_at') }}">
                        @error('effective_at')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field ad-platform-policy-form__body">
                        <span>Complete approved policy text</span>
                        <textarea name="body" rows="8" placeholder="Paste the complete policy text approved for publication." required>{{ old('body') }}</textarea>
                        @error('body')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                    </label>
                    <div class="ad-platform-policy-form__actions">
                        <p>Existing versions remain in history. A new version requires affected users to sign in again when it takes effect.</p>
                        <button class="ad-btn ad-btn-primary">Publish version</button>
                    </div>
                </form>
            @else
                <div class="ad-platform-empty" role="status">Policy scope storage is not installed yet. Run the additive policy migration before publishing.</div>
            @endif

            <div class="ad-platform-published">
                <div class="ad-platform-published__heading">
                    <h3>Published policies</h3>
                    <span>{{ $policies->count() }}</span>
                </div>
                @forelse($policies as $policy)
                    <details class="ad-platform-policy">
                        <summary>
                            <span><strong>{{ $policy->title }}</strong><small>{{ str_replace('_', ' ', $policy->policy_type ?? 'CUSTOM') }} · {{ $policy->audience ?? 'ALL' }} · Version {{ $policy->version }}@if($policy->effective_at) · Effective {{ $policy->effective_at->format('M j, Y') }}@endif</small></span>
                            <span class="ad-status {{ $policy->is_active ? 'is-active' : '' }}">{{ $policy->is_active ? 'Published' : 'Inactive' }}</span>
                        </summary>
                        <div class="ad-platform-policy__content">{{ $policy->body }}</div>
                    </details>
                @empty
                    <div class="ad-platform-empty">No policies have been published yet.</div>
                @endforelse
            </div>
        </div>
    </section>
    @endif
</div>
@endsection
