@extends('layouts.admin.app')
@section('title', 'Platform Settings')
@section('subtitle', 'Manage platform access, logistics fees, and published policies.')
@section('active', 'settings')
@section('content')
@php
    $transferType = old('transfer_commission_type', $settings['transfer_commission_type']);
    $registrationEnabled = old('registration_enabled', $settings['registration_enabled']);
@endphp

<div class="ad-platform-settings">
    <header class="ad-page-head">
        <div>
            <span class="ad-overline">System configuration</span>
            <h2>Platform Settings</h2>
            <p>Update registration and logistics fees. Platform commission is fixed by policy.</p>
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

    <form method="POST" action="{{ route('admin.settings.update') }}" class="ad-platform-settings__form">
        @csrf
        @method('PATCH')
        <input type="hidden" name="commission_rate" value="0.10">

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

        <section class="ad-card ad-platform-card" aria-labelledby="transfer-commission-title">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Logistics</span>
                    <h2 id="transfer-commission-title">Transfer commission</h2>
                    <p>Choose how much Logistics pays from each sealed manifest.</p>
                </div>
            </header>
            <div class="ad-platform-card__body">
                <label class="ad-field">
                    <span>Fee type</span>
                    <select id="transfer-commission-type" name="transfer_commission_type">
                        <option value="PERCENT" @selected($transferType === 'PERCENT')>Percentage of shipping fees</option>
                        <option value="FIXED" @selected($transferType === 'FIXED')>Fixed amount per parcel</option>
                    </select>
                </label>
                <label class="ad-field">
                    <span data-transfer-value-label>Commission rate</span>
                    <span class="ad-platform-money-input">
                        <input id="transfer-commission-value" type="number" name="transfer_commission_value" min="0" max="{{ $transferType === 'PERCENT' ? '100' : '10000' }}" step="0.01" value="{{ old('transfer_commission_value', $settings['transfer_commission_value']) }}" required>
                        <span data-transfer-value-unit>{{ $transferType === 'PERCENT' ? '%' : 'PHP' }}</span>
                    </span>
                    <small data-transfer-value-help>
                        @if($transferType === 'PERCENT')
                            This percentage is based on the shipping fees of parcels in the manifest.
                        @else
                            This amount is charged once for each parcel in the manifest.
                        @endif
                    </small>
                    @error('transfer_commission_value')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>
                <p class="ad-platform-note">The selected rule is saved with each manifest when it is sealed. Later setting changes do not alter sealed manifests.</p>
            </div>
        </section>

        <section class="ad-card ad-platform-card" aria-labelledby="regional-tiers-title">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Shipping</span>
                    <h2 id="regional-tiers-title">Regional shipping tiers</h2>
                    <p>Add a fee when a parcel travels between different regions.</p>
                </div>
            </header>
            <div class="ad-platform-card__body">
                <label class="ad-field">
                    <span>Additional fee per tier</span>
                    <span class="ad-platform-money-input ad-platform-money-input--prefix">
                        <span aria-hidden="true">PHP</span>
                        <input type="number" name="additional_region_tier_fee" min="0" max="1000000" step="0.01" value="{{ old('additional_region_tier_fee', $settings['additional_region_tier_fee']) }}" required>
                    </span>
                    <small>This amount is multiplied by the route’s tier and added to the base shipping fee.</small>
                    @error('additional_region_tier_fee')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="ad-field ad-platform-region-map">
                    <span>Region pair map</span>
                    <textarea name="shipping_region_tiers" rows="5" maxlength="10000" spellcheck="false" placeholder="National Capital Region | Central Luzon | 1&#10;Central Luzon | CALABARZON | 2">{{ old('shipping_region_tiers', $settings['shipping_region_tiers']) }}</textarea>
                    <small>One route per line: <code>Origin Region | Destination Region | Tier</code>. Use official region names and a tier from 1 to 20.</small>
                    @error('shipping_region_tiers')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>

                <div class="ad-platform-note ad-platform-note--soft">
                    <strong>How tiers work</strong>
                    <p>Same-region routes use the base fee. Cross-region pairs work in either direction. For example, PHP 6 per tier means a tier 2 route adds PHP 12. The system does not infer distance from region codes.</p>
                </div>
            </div>
        </section>

        <div class="ad-platform-settings__actions">
            <p>Changes apply to new checkouts or manifests. Existing snapshots remain as saved.</p>
            <button class="ad-btn ad-btn-primary">Save settings</button>
        </div>
    </form>

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
                <form method="POST" action="{{ route('admin.policies.store') }}" class="ad-platform-policy-form">
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
</div>
@endsection

@push('scripts')
<script>
    const transferType = document.getElementById('transfer-commission-type');
    const transferValue = document.getElementById('transfer-commission-value');
    const transferUnit = document.querySelector('[data-transfer-value-unit]');
    const transferValueLabel = document.querySelector('[data-transfer-value-label]');
    const transferValueHelp = document.querySelector('[data-transfer-value-help]');

    function updateTransferCommissionFields() {
        if (!transferType || !transferValue) return;
        const percentage = transferType.value === 'PERCENT';
        transferValue.max = percentage ? '100' : '10000';
        transferUnit.textContent = percentage ? '%' : 'PHP';
        transferValueLabel.textContent = percentage ? 'Commission rate' : 'Amount per parcel';
        transferValueHelp.textContent = percentage
            ? 'This percentage is based on the shipping fees of parcels in the manifest.'
            : 'This amount is charged once for each parcel in the manifest.';
    }

    transferType?.addEventListener('change', updateTransferCommissionFields);
    updateTransferCommissionFields();
</script>
@endpush
