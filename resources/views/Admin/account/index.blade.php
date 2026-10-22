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

    <section class="ad-card">
        <div class="ad-card-head">
            <div><span class="ad-overline">Payment destination</span><h2>Admin payout details</h2><p>This is for any future platform withdrawal. Seller and Logistics transfers use the destination saved in their own account settings.</p></div>
            @if($payoutDestinationSaved)<span class="ad-status is-approved">Destination saved</span>@endif
        </div>
        <div class="ad-card-body">
            @if($payoutDestinationReady)
                @if($payoutDestinationSaved)
                    @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($adminUser))
                    <dl class="ad-admin-payout-summary">
                        <div><dt>Saved payment channel</dt><dd>{{ $adminUser->payout_method }}@if($adminUser->payout_bank_name) · {{ $adminUser->payout_bank_name }}@endif</dd></div>
                        <div><dt>Account holder</dt><dd>{{ $adminUser->payout_account_name }}</dd></div>
                        <div><dt>{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd>{{ \App\Support\PayoutAccountDetails::formatNumber($adminUser->payout_account_number, $savedPayoutType) }}</dd></div>
                    </dl>
                @endif
                @php($payoutAccountType = old('payout_account_type', \App\Support\PayoutAccountDetails::typeFor($adminUser)))
                <form class="ad-admin-payout-form" method="POST" action="{{ route('admin.account.payout-destination.update') }}" data-payout-destination-form>
                    @csrf @method('PUT')
                    <label class="ad-field"><span>Payment channel</span><select name="payout_method" required><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected(old('payout_method', $adminUser->payout_method) === $method)>{{ $method }}</option>@endforeach</select></label>
                    <label class="ad-field"><span>Bank name (for bank transfer)</span><input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $adminUser->payout_bank_name) }}" autocomplete="off"></label>
                    <label class="ad-field"><span>Account holder name</span><input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $adminUser->payout_account_name) }}" required autocomplete="name"></label>
                    <label class="ad-field"><span>Destination number type</span><select name="payout_account_type" data-payout-account-type required><option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option><option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option></select></label>
                    <label class="ad-field"><span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span><input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $adminUser->payout_account_number) }}" required autocomplete="off" aria-describedby="admin-payout-number-help"><small id="admin-payout-number-help" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small></label>
                    <p class="ad-admin-payout-note" style="grid-column:1/-1;margin:0;color:var(--ad-muted);font-size:11px;line-height:1.5">Account holder and number are encrypted at rest. This destination is not used to send Seller or Logistics payouts. Card numbers and security codes are not collected.</p>
                    <div><button class="ad-btn ad-btn-primary">Save payout details</button></div>
                </form>
            @else
                <p class="ad-empty">Admin payout details will be available after the additive payout destination migration is installed.</p>
            @endif
        </div>
    </section>
</div>
@endsection
