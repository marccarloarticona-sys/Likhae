@extends('layouts.logistics.app')
@section('title','Logistics Account - LIKHAE')
@section('content')
<div class="space-y-6"><header><p class="text-xs font-bold uppercase text-primary">Account Management</p><h1 class="mt-2 text-2xl font-bold">{{ $center->business_name }}</h1><p class="mt-2 text-sm text-muted">Approved logistics center and owner account information.</p></header>
	@if(session('status'))<div class="border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
	@if($errors->any())<div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
	<section class="grid gap-4 md:grid-cols-2">
		<form method="POST" enctype="multipart/form-data" action="{{ route('logistics.account.profile.update') }}" class="border border-line bg-surface p-5">@csrf @method('PATCH')<h2 class="font-semibold">Owner Account</h2><div class="my-4"><x-shared.profile-photo-upload :user="$user" id="logistics-profile-photo" /></div><div class="mt-4 grid gap-3"><label class="grid gap-1 text-sm">First name<input class="border border-line px-3 py-2" name="first_name" value="{{ old('first_name', $user->first_name) }}" required></label><label class="grid gap-1 text-sm">Last name<input class="border border-line px-3 py-2" name="last_name" value="{{ old('last_name', $user->last_name) }}" required></label><label class="grid gap-1 text-sm">Email<input class="border border-line px-3 py-2" type="email" name="email" value="{{ old('email', $user->email) }}" required></label><label class="grid gap-1 text-sm">Contact number<input class="border border-line px-3 py-2" name="contact_number" value="{{ old('contact_number', $user->contact_number) }}" required></label><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Save Profile</button></div></form>
		<article class="border border-line bg-surface p-5"><h2 class="font-semibold">Center</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-muted">Code</dt><dd>{{ $center->code }}</dd></div><div><dt class="text-muted">Business</dt><dd>{{ $center->business_name }}</dd></div><div><dt class="text-muted">Address</dt><dd>{{ $center->address?->formatted() }}</dd></div><div><dt class="text-muted">Status</dt><dd>{{ str($center->status)->headline() }}</dd></div></dl></article>
	</section>
	<section class="payout-section rounded-xl border border-line bg-surface">
		<header class="payout-section-head"><div><span class="payout-overline">Payout destination</span><h2 class="font-semibold">Payment details</h2><p>Set where Admin should send approved Logistics payouts outside Likhae. The transfer reference and proof will appear in your wallet.</p></div>@if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif</header>
		<div class="payout-section-body">
		@if($payoutDestinationReady)
			@if($payoutDestinationSaved)
				@php($savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($center))
				<dl class="payout-summary">
					<div><dt class="text-xs text-muted">Saved payment channel</dt><dd class="font-semibold">{{ $center->payout_method }}@if($center->payout_bank_name) · {{ $center->payout_bank_name }}@endif</dd></div>
					<div><dt class="text-xs text-muted">Account holder</dt><dd class="font-semibold">{{ $center->payout_account_name }}</dd></div>
					<div><dt class="text-xs text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold">{{ \App\Support\PayoutAccountDetails::formatNumber($center->payout_account_number, $savedPayoutType) }}</dd></div>
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
				<div class="payout-form-actions is-wide"><small>Check the number carefully before saving.</small><button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">Save payout details</button></div>
			</form>
			</div>
		@else
			<p class="payout-empty-state">Payout details are temporarily unavailable while the payout setup is being updated.</p>
		@endif
		</div>
	</section>
	<form method="POST" action="{{ route('logistics.account.address.update') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-2">@csrf @method('PATCH')<div class="md:col-span-2"><h2 class="font-semibold">Registered Sorting Center Address</h2><p class="mt-1 text-sm text-muted">Use the Philippine address list for the center's operating address.</p></div><x-shared.philippine-address-fields :address="$center->address" field-class="border border-line px-3 py-2" wrapper-class="contents" /><x-shared.address-pin :address="$center->address" class="md:col-span-2" /><div class="md:col-span-2"><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Save Center Address</button></div></form>
	<form method="POST" action="{{ route('logistics.account.password.update') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-4">@csrf @method('PATCH')<h2 class="font-semibold md:col-span-4">Change Password</h2><input class="border border-line px-3 py-2" name="current_password" type="password" placeholder="Current password" required><input class="border border-line px-3 py-2" name="password" type="password" placeholder="New password" required><input class="border border-line px-3 py-2" name="password_confirmation" type="password" placeholder="Confirm password" required><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Update Password</button></form>
	<form method="POST" action="{{ route('logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">@csrf<button class="border border-red-200 px-4 py-2 font-semibold text-red-700">Logout</button></form>
</div>
@endsection
