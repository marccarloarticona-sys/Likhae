@extends('layouts.seller.app')

@php
    $currentTab = $tab ?? request('tab','profile');
    $initials = collect(explode(' ', $seller->name))->filter()->take(2)->map(fn($w)=>mb_strtoupper(mb_substr($w,0,1)))->implode('');
@endphp

@section('title','Account')
@section('active','account')
@section('subtitle','Manage your seller identity, business records, security, and preferences.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Account Management</span><h2>Seller Account</h2><p>Profile and business updates now save to your account.</p></div><span class="sl-account-status"><i></i> {{ str($seller->status ?? 'active')->headline() }}</span></div>
    <div class="sl-account-layout">
        <aside class="sl-card sl-account-nav"><div class="sl-account-person"><span class="sl-avatar">{{ $initials }}</span><div><strong>{{ $seller->name }}</strong><small>Seller ID: SLR-{{ str_pad((string)$seller->id,5,'0',STR_PAD_LEFT) }}</small></div></div><nav>@foreach(['profile'=>'Profile','business'=>'Business Information','payouts'=>'Payout Details','verification'=>'Verification','security'=>'Security','notifications'=>'Notification Settings'] as $key=>$label)<a href="{{ route('seller.account',['tab'=>$key]) }}" class="{{ $currentTab===$key?'is-active':'' }}"><span>{{ $loop->iteration }}</span>{{ $label }}<i>›</i></a>@endforeach</nav></aside>
        <main class="sl-account-content">
            @if(session('status'))<div class="sl-card" role="status" style="padding:12px 15px;color:#276749">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="sl-card" role="alert" style="padding:12px 15px;color:#9B2C2C">{{ $errors->first() }}</div>@endif
            @if($currentTab === 'business')
                <form class="sl-card sl-form-section" method="POST" action="{{ route('seller.account.business') }}">@csrf @method('PUT')<header class="sl-card-head"><div><h2>Business Information</h2><p>Legal information used for marketplace compliance.</p></div></header><div class="sl-form-grid">
                    <label class="sl-field"><span>Registered business name</span><input value="{{ $storeProfile->business_name ?? '' }}" disabled aria-describedby="business-name-help"><small id="business-name-help">Verified business names cannot be changed here.</small></label><label class="sl-field"><span>Business type</span><input value="{{ $storeProfile->business_type ?? '' }}" disabled aria-describedby="business-type-help"><small id="business-type-help">Your verified Seller category is managed by LIKHAE.</small></label><label class="sl-field"><span>DTI/SEC registration number</span><input name="dti_sec_number" value="{{ old('dti_sec_number',$storeProfile->dti_sec_number ?? '') }}"></label><label class="sl-field"><span>TIN</span><input name="tin" value="{{ old('tin',$storeProfile->tin ?? '') }}"></label><label class="sl-field"><span>Operational pickup contact number</span><input name="contact_number" value="{{ old('contact_number',$businessAddress?->contact_number ?: $seller->contact_number) }}"></label><div class="contents"><x-shared.philippine-address-fields :address="$businessAddress" field-class="sl-field" wrapper-class="contents" /></div><x-shared.address-pin :address="$businessAddress" class="sl-span-2" />
                </div><div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Save Business Information</button></div></form>
            @elseif($currentTab === 'payouts')
                <section class="sl-card sl-form-section">
                    <header class="sl-card-head"><div><h2>Payout Details</h2><p>Choose where Admin should send approved Seller payouts.</p></div>@if($payoutDestinationSaved)<span class="sl-status is-success">Destination saved</span>@endif</header>
                    @if($payoutDestinationReady)
                        @if($payoutDestinationSaved)
                            @php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($payoutProfile))
                            <dl class="mb-4 grid gap-3 rounded-xl border border-stone-200 bg-stone-50 p-4 text-sm sm:grid-cols-2">
                                <div><dt class="text-xs text-muted">Saved payment channel</dt><dd class="font-semibold">{{ $payoutProfile->payout_method }}@if($payoutProfile->payout_bank_name) · {{ $payoutProfile->payout_bank_name }}@endif</dd></div>
                                <div><dt class="text-xs text-muted">Account holder</dt><dd class="font-semibold">{{ $payoutProfile->payout_account_name }}</dd></div>
                                <div><dt class="text-xs text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold">{{ \App\Support\PayoutAccountDetails::formatNumber($payoutProfile->payout_account_number, $savedPayoutType) }}</dd></div>
                            </dl>
                        @endif
                        @php($payoutAccountType = old('payout_account_type', \App\Support\PayoutAccountDetails::typeFor($payoutProfile)))
                        <form method="POST" action="{{ route('seller.settlements.payout-destination.update') }}" data-payout-destination-form>
                            @csrf @method('PUT')
                            <div class="sl-form-grid">
                                <label class="sl-field"><span>Payment channel</span><select name="payout_method" required><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected(old('payout_method', $payoutProfile->payout_method) === $method)>{{ $method }}</option>@endforeach</select></label>
                                <label class="sl-field"><span>Bank name (for bank transfer)</span><input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $payoutProfile->payout_bank_name) }}" autocomplete="off"></label>
                                <label class="sl-field"><span>Account holder name</span><input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $payoutProfile->payout_account_name) }}" required autocomplete="name"></label>
                                <label class="sl-field"><span>Destination number type</span><select name="payout_account_type" data-payout-account-type required><option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option><option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option></select></label>
                                <label class="sl-field"><span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span><input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $payoutProfile->payout_account_number) }}" required autocomplete="off" aria-describedby="seller-payout-number-help"><small id="seller-payout-number-help" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small></label>
                            </div>
                            <div class="sl-help-card"><span>i</span><div><strong>Secure payout setup</strong><p>Account holder and number are encrypted at rest. Admin uses these details to send funds outside Likhae, then records a transfer reference and proof in your wallet. Card numbers and security codes are not collected.</p></div></div>
                            <div class="sl-form-actions"><button class="sl-btn sl-btn-primary">Save payout details</button></div>
                        </form>
                    @else
                        <p class="sl-empty-state">Payout details will be available after the additive payout destination migration is installed.</p>
                    @endif
                </section>
            @elseif($currentTab === 'verification')
                <section class="sl-card sl-verification-card"><header class="sl-card-head"><div><h2>Seller Verification</h2><p>Documents already submitted with the seller account.</p></div><span class="sl-status {{ ($seller->status??'active')==='active'?'is-success':'is-warning' }}">{{ str($seller->status ?? 'active')->headline() }}</span></header><div class="sl-document-list">
                    @foreach([['Valid Government ID',$seller->valid_id_path],['Business Permit',$seller->business_permit_path]] as $doc)<article><span class="sl-file-icon">DOC</span><div><strong>{{ $doc[0] }}</strong><small>{{ $doc[1] ? basename($doc[1]) : 'No document path saved' }}</small></div><span class="sl-status {{ $doc[1]?'is-success':'is-neutral' }}">{{ $doc[1]?'Submitted':'Missing' }}</span></article>@endforeach
                </div><div class="sl-help-card"><span>i</span><div><strong>Need to update a document?</strong><p>Contact Seller Support for document replacement and re-verification.</p></div><a href="{{ route('seller.messages') }}" class="sl-btn sl-btn-soft sl-btn-sm">Contact Support</a></div></section>
            @elseif($currentTab === 'security')
                <section class="sl-card sl-form-section"><header class="sl-card-head"><div><h2>Security</h2><p>Change the password used for this Seller Center account.</p></div></header><div class="sl-security-list"><article><span class="sl-security-icon">••</span><div><strong>Password</strong><p>Use at least 8 characters and keep it private.</p></div><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-modal-open="passwordModal">Change</button></article></div></section>
            @elseif($currentTab === 'notifications')
                <section class="sl-card sl-form-section"><header class="sl-card-head"><div><h2>Notification Settings</h2><p>Manage Seller Center notification categories, sound cues, and AI preferences in one place.</p></div></header><div class="sl-help-card"><span>i</span><div><strong>Seller Center alerts</strong><p>Email, push, and SMS delivery controls are coming soon. In-app order and review alerts are available now.</p></div><a href="{{ route('seller.settings') }}" class="sl-btn sl-btn-primary sl-btn-sm">Open System Settings</a></div></section>
            @elseif($currentTab === 'notifications')
                @php
                    $settings = [
                        'new_order' => ['New order','Receive an alert when a buyer places an order'],
                        'order_cancellation' => ['Order cancellation','Know when an order is cancelled'],
                        'pickup_shipping' => ['Pickup and shipping','Rider assignment and shipment status changes'],
                        'inventory_alerts' => ['Inventory alerts','Low-stock and out-of-stock reminders'],
                        'buyer_messages' => ['Buyer messages','New chat messages and support concerns'],
                        'finance_payouts' => ['Finance and payouts','Balance updates and payout confirmations'],
                        'marketing_updates' => ['Marketing updates','Campaign invitations and promotional tips'],
                    ];
                @endphp
                <form class="sl-card sl-form-section" method="POST" action="{{ route('seller.account.notifications') }}">@csrf @method('PUT')<header class="sl-card-head"><div><h2>Notification Settings</h2><p>Choose which Seller Center alerts you want to receive.</p></div></header><div class="sl-notification-settings"><div class="sl-notification-setting-head"><span>Notification Type</span><span>Email</span><span>Push</span><span>SMS</span></div>
                    @foreach($settings as $key=>$setting)<div class="sl-notification-setting"><div><strong>{{ $setting[0] }}</strong><small>{{ $setting[1] }}</small></div>@foreach(['email','push','sms'] as $channel)<label class="sl-check"><input type="checkbox" name="preferences[{{ $key }}][{{ $channel }}]" value="1" @checked(data_get($preferences,"$key.$channel",$channel!=='sms'))><span>✓</span></label>@endforeach</div>@endforeach
                </div><div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Save Preferences</button></div></form>
            @else
                <form class="sl-card sl-form-section" method="POST" enctype="multipart/form-data" action="{{ route('seller.account.profile') }}">@csrf @method('PUT')<header class="sl-card-head"><div><h2>Profile Information</h2><p>Your private account identity and contact information. Manage your shop logo in <a href="{{ route('seller.store') }}" class="underline">Your Shop</a>.</p></div></header><div class="sl-form-grid"><div class="sl-span-2"><x-shared.profile-photo-upload :user="$seller" id="seller-profile-photo" /></div><label class="sl-field"><span>First name</span><input name="first_name" value="{{ old('first_name',$seller->first_name ?: str($seller->name)->before(' ')) }}" required></label><label class="sl-field"><span>Last name</span><input name="last_name" value="{{ old('last_name',$seller->last_name ?: str($seller->name)->after(' ')) }}" required></label><label class="sl-field"><span>Email address</span><input name="email" type="email" value="{{ old('email',$seller->email) }}" required><small>Changing your email requires verification before Seller Center access continues.</small></label><label class="sl-field"><span>Mobile number (private account contact)</span><input name="contact_number" value="{{ old('contact_number',$seller->contact_number) }}"><small>Pickup contact details are managed separately under Business Information.</small></label><label class="sl-field"><span>Role</span><input value="Store Owner" disabled></label><label class="sl-field"><span>Seller ID</span><input value="SLR-{{ str_pad((string)$seller->id,5,'0',STR_PAD_LEFT) }}" disabled></label></div><div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Save Profile</button></div></form>
            @endif
        </main>
    </div>
</div>

<div class="sl-modal" data-modal="passwordModal" hidden><div class="sl-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="passwordTitle"><header><div><span class="sl-eyebrow">Account Security</span><h2 id="passwordTitle">Change Password</h2></div><button type="button" class="sl-icon-btn" data-modal-close aria-label="Close">×</button></header><form method="POST" action="{{ route('seller.account.password') }}">@csrf @method('PUT')<div class="sl-form-grid"><label class="sl-field sl-span-2"><span>Current password</span><input name="current_password" type="password" required></label><label class="sl-field sl-span-2"><span>New password</span><input name="password" type="password" minlength="8" required></label><label class="sl-field sl-span-2"><span>Confirm new password</span><input name="password_confirmation" type="password" minlength="8" required></label></div><footer><button type="button" class="sl-btn sl-btn-ghost" data-modal-close>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Update Password</button></footer></form></div></div>
@endsection
