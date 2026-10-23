@extends('layouts.admin.app')
@section('title', 'Admin Account')
@section('subtitle', 'Manage your profile, security, notification preferences, and payout destination.')
@section('active', 'account')
@section('content')
<div class="ad-admin-account" style="display:grid;gap:16px">
    @if(session('status') || session('success'))
        <div class="ad-note" role="status">{{ session('status') ?: session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="ad-note is-warning" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="ad-page-head">
        <div><span class="ad-overline">Account</span><h2>{{ $adminUser->name }}</h2><p>{{ $adminUser->email }}</p></div>
    </div>

    <div class="ad-admin-account-grid">
        <section class="ad-card">
            <div class="ad-card-head"><div><h2>Profile</h2></div></div>
            <div class="ad-card-body">
                <form style="display:grid;gap:12px" method="POST" action="{{ route('admin.account.update') }}">
                    @csrf @method('PATCH')
                    <label class="ad-field"><span>First name</span><input name="first_name" value="{{ old('first_name', $adminUser->first_name ?: str($adminUser->name)->before(' ')) }}" required></label>
                    <label class="ad-field"><span>Last name</span><input name="last_name" value="{{ old('last_name', $adminUser->last_name ?: str($adminUser->name)->after(' ')) }}" required></label>
                    <label class="ad-field"><span>Email</span><input type="email" name="email" value="{{ old('email', $adminUser->email) }}" required></label>
                    <label class="ad-field"><span>Contact number</span><input name="contact_number" value="{{ old('contact_number', $adminUser->contact_number) }}"></label>
                    <button class="ad-btn ad-btn-primary">Save profile</button>
                </form>
            </div>
        </section>

        <section class="ad-card">
            <div class="ad-card-head"><div><h2>Password</h2></div></div>
            <div class="ad-card-body">
                <form style="display:grid;gap:12px" method="POST" action="{{ route('admin.account.password') }}">
                    @csrf @method('PATCH')
                    <label class="ad-field"><span>Current password</span><input type="password" name="current_password" required></label>
                    <label class="ad-field"><span>New password</span><input type="password" name="password" required></label>
                    <label class="ad-field"><span>Confirm new password</span><input type="password" name="password_confirmation" required></label>
                    <button class="ad-btn ad-btn-primary">Change password</button>
                </form>
            </div>
        </section>

        <section class="ad-card">
            <div class="ad-card-head"><div><h2>Notifications</h2></div></div>
            <div class="ad-card-body">
                <form style="display:grid;gap:12px" method="POST" action="{{ route('admin.account.preferences') }}">
                    @csrf @method('PATCH')
                    @php($prefs = $preferences->notification_preferences ?? [])
                    @foreach(['registrations' => 'Registration updates', 'risk' => 'Product risk alerts', 'finance' => 'Finance updates', 'messages' => 'Messages'] as $key => $label)
                        <label><input type="checkbox" name="{{ $key }}" value="1" @checked($prefs[$key] ?? false)> {{ $label }}</label>
                    @endforeach
                    <button class="ad-btn ad-btn-primary">Save preferences</button>
                </form>
            </div>
        </section>
    </div>

        <section class="ad-card payout-section">
            <div class="ad-card-head payout-section-head">
            <div><span class="payout-overline">Payment destination</span><h2>Admin payout details</h2><p>This is for any future platform withdrawal. Seller and Logistics transfers use the destination saved in their own account settings.</p></div>
            @if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif
        </div>
        <div class="ad-card-body payout-section-body">
            @if($payoutDestinationReady)
                @if($payoutMultipleReady)
                    @include('components.shared.payout-destination-manager', [
                        'payoutDestinationRoute' => 'admin.account.payout-destination.update',
                        'payoutChangeDescription' => 'Changes apply to future platform withdrawals.',
                        'payoutPurpose' => 'platform withdrawals',
                        'payoutSecurityCopy' => 'This destination is for future platform withdrawals. Seller and Logistics transfers use their own saved default details.',
                        'payoutHelpId' => 'admin-multiple-payout-number-help',
                        'payoutButtonClass' => 'ad-btn ad-btn-primary',
                    ])
                @else
                @if($payoutDestinationSaved)
                    @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($adminUser))
                    <dl class="payout-summary">
                        <div><dt>Saved payment channel</dt><dd>{{ $adminUser->payout_method }}@if($adminUser->payout_bank_name) · {{ $adminUser->payout_bank_name }}@endif</dd></div>
                        <div><dt>Account holder</dt><dd>{{ $adminUser->payout_account_name }}</dd></div>
                        <div><dt>{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd>{{ \App\Support\PayoutAccountDetails::formatNumber($adminUser->payout_account_number, $savedPayoutType) }}</dd></div>
                    </dl>
                @endif
                @php($payoutAccountType = old('payout_account_type', \App\Support\PayoutAccountDetails::typeFor($adminUser)))
                @php($payoutMethod = old('payout_method', $adminUser->payout_method))
                <div class="payout-editor">
                <div class="payout-editor__head"><div><span class="payout-editor__eyebrow">Payout settings</span><h3>{{ $payoutDestinationSaved ? 'Update destination' : 'Add a destination' }}</h3></div><p>Changes apply to future platform withdrawals.</p></div>
                <form class="ad-admin-payout-form payout-form payout-fields-grid" method="POST" action="{{ route('admin.account.payout-destination.update') }}" data-payout-destination-form>
                    @csrf @method('PUT')
                    <label class="ad-field payout-form-field"><span>Payment channel</span><select name="payout_method" required><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($payoutMethod === $method)>{{ $method }}</option>@endforeach</select><small>Choose the service receiving this withdrawal.</small></label>
                    <label class="ad-field payout-form-field payout-bank-field" data-payout-bank-field @if($payoutMethod !== 'Bank Transfer') hidden @endif><span>Bank name</span><input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $adminUser->payout_bank_name) }}" autocomplete="organization" placeholder="e.g. BPI"></label>
                    <label class="ad-field payout-form-field is-wide"><span>Account holder name</span><input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $adminUser->payout_account_name) }}" required autocomplete="name" placeholder="Name on the account"></label>
                    <label class="ad-field payout-form-field"><span>Number type</span><select name="payout_account_type" data-payout-account-type required><option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option><option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option></select></label>
                    <label class="ad-field payout-form-field"><span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span><input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $adminUser->payout_account_number) }}" required autocomplete="off" aria-describedby="admin-payout-number-help"><small id="admin-payout-number-help" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small></label>
                    <div class="payout-security-note is-wide"><span class="payout-security-note__icon"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M6.5 9V6a3.5 3.5 0 0 1 7 0v3"/></svg></span><div><strong>Private and protected</strong><p>Your account holder name and number are encrypted. This destination is for future platform withdrawals only; Seller and Logistics payouts use their own saved details.</p></div></div>
                    <div class="payout-form-actions is-wide"><small>Card numbers and security codes are never collected.</small><button type="submit" class="ad-btn ad-btn-primary">Save payout details</button></div>
                </form>
                </div>
                @endif
            @else
                <p class="payout-empty-state">Admin payout details are temporarily unavailable while the payout setup is being updated.</p>
            @endif
        </div>
    </section>
</div>
@endsection
