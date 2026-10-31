@extends('layouts.seller.app')

@php
    $currentTab = $tab ?? request('tab','profile');
@endphp

@section('title','Account')
@section('active','account')
@section('subtitle','Manage your seller identity, business records, security, and preferences.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Account Management</span><h2>Seller Account</h2><p>Profile and business updates now save to your account.</p></div><span class="sl-account-status"><i></i> {{ str($seller->status ?? 'active')->headline() }}</span></div>
    <div class="sl-account-layout">
        <aside class="sl-card sl-account-nav"><div class="sl-account-person"><span class="sl-avatar"><x-shared.profile-image :path="$storeProfile->avatar_path" role="seller" :alt="$storeProfile->shop_name ?: 'Shop picture'" /></span><div><strong>{{ $seller->name }}</strong><small>Seller ID: SLR-{{ str_pad((string)$seller->id,5,'0',STR_PAD_LEFT) }}</small></div></div><nav>@foreach(['profile'=>'Profile','business'=>'Business Information','pickup-addresses'=>'Pickup Addresses','payouts'=>'Payout Details','verification'=>'Verification','security'=>'Security'] as $key=>$label)<a href="{{ route('seller.account',['tab'=>$key]) }}" class="{{ $currentTab===$key?'is-active':'' }}"><span>{{ $loop->iteration }}</span>{{ $label }}<i>›</i></a>@endforeach</nav></aside>
        <main class="sl-account-content">
            @if(session('status'))<div class="sl-card" role="status" style="padding:12px 15px;color:#276749">{{ session('status') }}</div>@endif
            @if($errors->any())<div class="sl-card" role="alert" style="padding:12px 15px;color:#9B2C2C">{{ $errors->first() }}</div>@endif
            @if($currentTab === 'business')
                <form class="sl-card sl-form-section" method="POST" action="{{ route('seller.account.business') }}">@csrf @method('PUT')<header class="sl-card-head"><div><h2>Business Information</h2><p>Legal information used for marketplace compliance. Pickup locations are managed separately.</p></div></header><div class="sl-form-grid">
                    <label class="sl-field"><span>Registered business name</span><input value="{{ $storeProfile->business_name ?? '' }}" disabled aria-describedby="business-name-help"><small id="business-name-help">Verified business names cannot be changed here.</small></label><label class="sl-field"><span>Business type</span><input value="{{ $storeProfile->business_type ?? '' }}" disabled aria-describedby="business-type-help"><small id="business-type-help">Your verified Seller category is managed by LIKHAE.</small></label><label class="sl-field"><span>DTI/SEC registration number</span><input name="dti_sec_number" value="{{ old('dti_sec_number',$storeProfile->dti_sec_number ?? '') }}"></label><label class="sl-field"><span>TIN</span><input name="tin" value="{{ old('tin',$storeProfile->tin ?? '') }}"></label>
                </div><div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Save Business Information</button></div></form>
            @elseif($currentTab === 'pickup-addresses')
                @php
                    $editingPickupAddress = $pickupAddressToEdit;
                @endphp
                <section class="sl-card sl-form-section">
                    <header class="sl-card-head"><div><h2>Pickup Addresses</h2><p>Save up to 3 locations and choose which one Logistics should use for each pickup request.</p></div><span class="sl-status {{ $pickupAddresses->count() ? 'is-success' : 'is-warning' }}">{{ $pickupAddresses->count() }}/3 saved</span></header>
                    <p class="px-5 pb-2 text-sm text-muted">@if($businessAddress)Your current Seller origin address is listed here.@elseAdd a pickup address and set a default to establish your Seller origin location.@endif Pickup requests save the selected address, so changing your default later does not rewrite an existing request. Existing orders keep their current route.</p>
                    @if(!$editingPickupAddress && $pickupAddresses->count() >= 3)
                        <p class="mx-5 mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">You have reached the 3-address limit. Remove an address that has not been used for an order or pickup before adding another.</p>
                    @else
                        <form class="sl-form-grid" method="POST" action="{{ $editingPickupAddress ? route('seller.account.pickup-addresses.update', $editingPickupAddress) : route('seller.account.pickup-addresses.store') }}">
                            @csrf @if($editingPickupAddress) @method('PUT') @endif
                            <label class="sl-field"><span>Address label</span><input name="label" required maxlength="50" value="{{ old('label', $editingPickupAddress?->label ?? '') }}" placeholder="Main shop, warehouse, branch"></label>
                            <label class="sl-field"><span>Contact person</span><input name="recipient_name" required maxlength="200" value="{{ old('recipient_name', $editingPickupAddress?->recipient_name ?? $seller->name) }}"></label>
                            <label class="sl-field"><span>Mobile number</span><input name="contact_number" required maxlength="30" value="{{ old('contact_number', $editingPickupAddress?->contact_number ?? $seller->contact_number) }}" placeholder="+63 9XX XXX XXXX"></label>
                            <div class="contents"><x-shared.philippine-address-fields :address="$editingPickupAddress" field-class="" label-class="sl-field" wrapper-class="contents" /></div>
                            <x-shared.address-pin :address="$editingPickupAddress" class="sl-span-2" />
                            <div class="sl-form-actions sl-span-2"><a class="sl-btn sl-btn-ghost" href="{{ route('seller.account',['tab'=>'pickup-addresses']) }}">{{ $editingPickupAddress ? 'Cancel edit' : 'Clear form' }}</a><button type="submit" class="sl-btn sl-btn-primary">{{ $editingPickupAddress ? 'Save address changes' : 'Add pickup address' }}</button></div>
                        </form>
                    @endif
                </section>

                <section class="space-y-3" aria-label="Saved pickup addresses">
                    @forelse($pickupAddresses as $address)
                        @php
                            $isEffectiveDefault = $defaultPickupAddress && (int) $defaultPickupAddress->id === (int) $address->id;
                        @endphp
                        <article class="sl-card p-5">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2"><h3 class="font-semibold text-ink">{{ $address->label ?: 'Pickup address' }}</h3>
                                        @if($isEffectiveDefault)<span class="sl-status is-success">{{ $address->is_default ? 'Default pickup' : 'Business Address fallback' }}</span>@endif
                                    @if($businessAddress && (int)$businessAddress->id === (int)$address->id)<span class="sl-status">Seller origin route</span>@endif
                                    </div>
                                    <p class="mt-2 text-sm text-ink">{{ $address->formatted() }}</p>
                                    <p class="mt-1 text-sm text-muted">{{ $address->recipient_name }} · {{ $address->contact_number }}</p>
                                    @if(is_numeric($address->latitude) && is_numeric($address->longitude))<p class="mt-1 text-xs text-muted">Pickup pin confirmed</p>@elseif(in_array((int)$address->id, $pickupAddressEditProtectedIds, true))<p class="mt-1 text-xs text-amber-800">Pickup pin missing. This address is locked to preserve existing routes; add another address with a confirmed map pin.</p>@else<p class="mt-1 text-xs text-amber-800">Pickup pin missing. Edit this address and confirm its map pin.</p>@endif
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if(!$address->is_default)
                                        <form method="POST" action="{{ route('seller.account.pickup-addresses.default', $address) }}">@csrf<button class="sl-btn sl-btn-ghost">Set as default</button></form>
                                    @endif
                                    @if(!in_array((int)$address->id, $pickupAddressEditProtectedIds, true))
                                        <a class="sl-btn sl-btn-ghost" href="{{ route('seller.account',['tab'=>'pickup-addresses','pickup_edit'=>$address->id]) }}">Edit</a>
                                    @else
                                        <span class="text-xs text-muted">Locked to preserve existing routes</span>
                                    @endif
                                    @if(!in_array((int)$address->id, $pickupAddressDeleteProtectedIds, true) && $pickupAddresses->count() > 1)
                                        <form method="POST" action="{{ route('seller.account.pickup-addresses.destroy', $address) }}" data-confirm-title="Remove this pickup address?" data-confirm-message="Addresses used by orders or pickup requests cannot be removed." data-confirm-label="Remove address">@csrf @method('DELETE')<button class="sl-btn sl-btn-ghost">Remove</button></form>
                                    @endif
                                </div>
                            </div>
                        </article>
                    @empty
                        <article class="sl-card p-5 text-sm text-muted">No pickup address is saved yet. Add one above before requesting a Logistics pickup.</article>
                    @endforelse
                </section>
            @elseif($currentTab === 'payouts')
                <section class="sl-card sl-form-section payout-section">
                    <header class="sl-card-head payout-section-head"><div><span class="payout-overline">Payout destination</span><h2>Payment details</h2><p>Choose where Admin should send approved Seller payouts.</p></div>@if($payoutDestinationSaved)<span class="payout-saved-badge"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Destination saved</span>@endif</header>
                    <div class="payout-section-body">
                    @if($payoutDestinationReady)
                        @if($payoutMultipleReady)
                            @include('components.shared.payout-destination-manager', [
                                'payoutDestinationRoute' => 'seller.settlements.payout-destination.update',
                                'payoutChangeDescription' => 'Changes apply to future Seller payouts.',
                                'payoutPurpose' => 'Seller payouts',
                                'payoutSecurityCopy' => 'Admin uses your default destination for approved Seller payouts and records each transfer in your wallet.',
                                'payoutHelpId' => 'seller-multiple-payout-number-help',
                                'payoutButtonClass' => 'sl-btn sl-btn-primary',
                            ])
                        @else
                        @if($payoutDestinationSaved)
                            @php
                                $savedPayoutType = \App\Support\PayoutAccountDetails::typeFor($payoutProfile);
                            @endphp
                            <dl class="payout-summary">
                                <div><dt class="text-xs text-muted">Saved payment channel</dt><dd class="font-semibold">{{ $payoutProfile->payout_method }}@if($payoutProfile->payout_bank_name) · {{ $payoutProfile->payout_bank_name }}@endif</dd></div>
                                <div><dt class="text-xs text-muted">Account holder</dt><dd class="font-semibold">{{ $payoutProfile->payout_account_name }}</dd></div>
                                <div><dt class="text-xs text-muted">{{ \App\Support\PayoutAccountDetails::typeLabel($savedPayoutType) }}</dt><dd class="font-semibold">{{ \App\Support\PayoutAccountDetails::formatNumber($payoutProfile->payout_account_number, $savedPayoutType) }}</dd></div>
                            </dl>
                        @endif
                        @php
                            $payoutMethod = old('payout_method', $payoutProfile->payout_method);
                            $payoutAccountType = old('payout_account_type', \App\Support\PayoutAccountDetails::typeFor($payoutProfile));
                        @endphp
                        <div class="payout-editor">
                        <div class="payout-editor__head"><div><span class="payout-editor__eyebrow">Payout settings</span><h3>{{ $payoutDestinationSaved ? 'Update destination' : 'Add a destination' }}</h3></div><p>Changes apply to future payouts.</p></div>
                        <form class="payout-form" method="POST" action="{{ route('seller.settlements.payout-destination.update') }}" data-payout-destination-form>
                            @csrf @method('PUT')
                            <div class="sl-form-grid payout-fields-grid">
                                <label class="sl-field">
                                    <span>Payment channel</span>
                                    <select name="payout_method" required>
                                        <option value="">Select a channel</option>
                                        @foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)
                                            <option value="{{ $method }}" @selected($payoutMethod === $method)>{{ $method }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label class="sl-field payout-bank-field" data-payout-bank-field @if($payoutMethod !== 'Bank Transfer') hidden @endif>
                                    <span>Bank name</span>
                                    <input name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $payoutProfile->payout_bank_name) }}" autocomplete="organization">
                                </label>
                                <label class="sl-field">
                                    <span>Account holder name</span>
                                    <input name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $payoutProfile->payout_account_name) }}" required autocomplete="name">
                                </label>
                                <label class="sl-field">
                                    <span>Destination number type</span>
                                    <select name="payout_account_type" data-payout-account-type required>
                                        <option value="MOBILE_NUMBER" @selected($payoutAccountType === 'MOBILE_NUMBER')>Mobile number</option>
                                        <option value="ACCOUNT_NUMBER" @selected($payoutAccountType === 'ACCOUNT_NUMBER')>Account number</option>
                                    </select>
                                </label>
                                <label class="sl-field payout-number-field">
                                    <span data-payout-number-label>{{ \App\Support\PayoutAccountDetails::typeLabel($payoutAccountType) }}</span>
                                    <input type="text" inputmode="numeric" data-payout-account-number name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $payoutProfile->payout_account_number) }}" required autocomplete="off" aria-describedby="seller-payout-number-help">
                                    <small id="seller-payout-number-help" data-payout-number-help>{{ \App\Support\PayoutAccountDetails::formatHint($payoutAccountType) }}</small>
                                </label>
                            </div>
                            <div class="sl-help-card payout-security-note"><span class="payout-security-note__icon"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="4" y="9" width="12" height="8" rx="2"/><path d="M6.5 9V6a3.5 3.5 0 0 1 7 0v3"/></svg></span><div><strong>Protected payout details</strong><p>Your account holder name and payout number are encrypted. Admin sends approved payouts outside Likhae and records the transfer reference and proof in your wallet. Card numbers and security codes are not collected.</p></div></div>
                            <div class="sl-form-actions payout-form-actions"><small>Check the number carefully before saving.</small><button type="submit" class="sl-btn sl-btn-primary">Save payout details</button></div>
                        </form>
                        </div>
                        @endif
                    @else
                        <p class="payout-empty-state">Payout details are temporarily unavailable while the payout setup is being updated.</p>
                    @endif
                    </div>
                </section>
            @elseif($currentTab === 'verification')
                @php
                    $sellerDocumentInputs = \App\Models\Auth\ApplicationDocument::SELLER_UPLOAD_FIELDS;
                @endphp
                <section class="sl-card sl-verification-card">
                    <header class="sl-card-head"><div><h2>Seller Verification</h2><p>Review current verification documents and submit updates to Admin.</p></div><span class="sl-status {{ ($seller->status??'active')==='active'?'is-success':'is-warning' }}">{{ str($seller->status ?? 'active')->headline() }}</span></header>
                    <div class="sl-document-list">
                        @foreach($sellerDocumentInputs as $field => $type)
                            @php
                                $document = $sellerDocuments->get($type);
                                $documentStatus = strtoupper((string) ($document?->verification_status ?? 'MISSING'));
                                $adminRequestedUpdate = $document && isset($sellerUpdateRequestDocumentIds[$document->id]);
                                $documentLabel = \App\Models\Auth\ApplicationDocument::DOCUMENT_LABELS[$type];
                            @endphp
                            <article>
                                <span class="sl-file-icon">DOC</span>
                                <div>
                                    <strong>{{ $documentLabel }}</strong>
                                    <small>{{ $document?->original_name ?: 'No document submitted yet' }}</small>
                                    @if($documentStatus === 'REJECTED' && $document?->rejection_reason)
                                        <small class="sl-document-rejection">{{ $adminRequestedUpdate ? 'Admin requested this update:' : "Admin's reason:" }} {{ $document->rejection_reason }}</small>
                                    @elseif($documentStatus === 'PENDING')
                                        <small>Update submitted. Your current approved document remains on file during review.</small>
                                    @endif
                                </div>
                                <span class="sl-status {{ $documentStatus === 'VERIFIED' ? 'is-success' : ($documentStatus === 'PENDING' ? 'is-warning' : ($documentStatus === 'REJECTED' ? 'is-danger' : 'is-neutral')) }}">{{ $documentStatus === 'VERIFIED' ? 'Verified' : ($documentStatus === 'PENDING' ? 'Update pending' : ($documentStatus === 'REJECTED' ? ($adminRequestedUpdate ? 'Update requested' : 'Needs replacement') : 'Missing')) }}</span>
                            </article>
                        @endforeach
                    </div>
                        <form class="sl-verification-upload" method="POST" enctype="multipart/form-data" action="{{ route('seller.account.verification-documents.store') }}">
                            @csrf
                            <div class="sl-verification-upload__intro"><strong>Request a document update</strong><p>Upload a replacement for any document. Admin will review it before the new version is accepted. JPG, PNG, WebP, or PDF, up to 5 MB.</p></div>
                            <div class="sl-verification-upload__grid">
                                @foreach($sellerDocumentInputs as $field => $type)
                                    @php($document = $sellerDocuments->get($type))
                                    @php($isPending = strtoupper((string) $document?->verification_status) === 'PENDING')
                                    <label class="sl-field">
                                        <span>{{ \App\Models\Auth\ApplicationDocument::DOCUMENT_LABELS[$type] }}</span>
                                        <input type="file" name="documents[{{ $field }}]" accept=".jpg,.jpeg,.png,.webp,.pdf" @disabled($isPending)>
                                        <small>{{ $isPending ? 'Already waiting for Admin review.' : 'Optional. Choose a file to submit this document for re-verification.' }}</small>
                                    </label>
                                @endforeach
                            </div>
                            <div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Send update request</button><small>Choose at least one document.</small></div>
                        </form>
                </section>
            @elseif($currentTab === 'security')
                <section class="sl-card sl-form-section">
                    <header class="sl-card-head"><div><h2>Security</h2><p>Change the password used for this Seller Center account.</p></div></header>
                    <div class="sl-security-list">
                        <article><span class="sl-security-icon">••</span><div><strong>Password</strong><p>Use at least 8 characters and keep it private.</p></div><button type="button" class="sl-btn sl-btn-ghost sl-btn-sm" data-password-change-toggle aria-expanded="{{ $errors->has('password') || $errors->has('password_confirmation') || $errors->has('current_password') ? 'true' : 'false' }}" aria-controls="sellerPasswordChange">{{ $errors->has('password') || $errors->has('password_confirmation') || $errors->has('current_password') ? 'Close form' : 'Change' }}</button></article>
                    </div>
                    <div class="sl-password-change-panel" id="sellerPasswordChange" data-password-change-panel @if(!$errors->has('password') && !$errors->has('password_confirmation') && !$errors->has('current_password')) hidden @endif>
                        <form method="POST" action="{{ route('seller.account.password') }}" data-password-change-form>
                            @csrf @method('PUT')
                            <div class="sl-form-grid">
                                <label class="sl-field sl-span-2"><span>Current password</span><div class="sl-password-input"><input id="sellerCurrentPassword" name="current_password" type="password" autocomplete="current-password" required><button type="button" class="sl-password-peek" data-password-peek="sellerCurrentPassword" aria-label="Show current password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div></label>
                                <label class="sl-field sl-span-2"><span>New password</span><div class="sl-password-input"><input id="sellerNewPassword" name="password" type="password" minlength="8" autocomplete="new-password" required><button type="button" class="sl-password-peek" data-password-peek="sellerNewPassword" aria-label="Show new password" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small>Use at least 8 characters.</small></label>
                                <label class="sl-field sl-span-2"><span>Confirm new password</span><div class="sl-password-input"><input id="sellerPasswordConfirmation" name="password_confirmation" type="password" minlength="8" autocomplete="new-password" aria-describedby="sellerPasswordMatch" data-password-confirmation required><button type="button" class="sl-password-peek" data-password-peek="sellerPasswordConfirmation" aria-label="Show password confirmation" aria-pressed="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.7"/></svg></button></div><small id="sellerPasswordMatch" class="sl-password-match" data-password-match-message role="status" aria-live="polite"></small></label>
                            </div>
                            <footer><button type="button" class="sl-btn sl-btn-ghost" data-password-change-cancel>Cancel</button><button type="submit" class="sl-btn sl-btn-primary">Update Password</button></footer>
                        </form>
                    </div>
                </section>
            @else
                <form class="sl-card sl-form-section" method="POST" action="{{ route('seller.account.profile') }}">
                    @csrf @method('PUT')
                    <header class="sl-card-head">
                        <div>
                            <h2>Profile Information</h2>
                            <p>Your shop logo is also your seller account picture. <a href="{{ route('seller.store') }}" class="underline">Change it in Your Shop</a>.</p>
                        </div>
                    </header>
                    <div class="sl-form-grid">
                        <div class="sl-span-2" style="display:flex;align-items:center;gap:14px">
                            <span style="display:block;width:72px;height:72px;flex:0 0 72px;overflow:hidden;border:1px solid #eadccc;border-radius:50%;background:#f6efe7">
                                <x-shared.profile-image :path="$storeProfile->avatar_path" role="seller" :alt="$storeProfile->shop_name ?: 'Shop picture'" style="width:100%;height:100%;object-fit:cover" />
                            </span>
                            <div><strong>Shop picture</strong><p style="margin:4px 0 0;font-size:11px">Shown here and in your seller account menu.</p></div>
                        </div>
                        <label class="sl-field"><span>First name</span><input name="first_name" value="{{ old('first_name',$seller->first_name ?: str($seller->name)->before(' ')) }}" required></label>
                        <label class="sl-field"><span>Middle name</span><input name="middle_initial" value="{{ old('middle_initial',$seller->middle_initial) }}" maxlength="10"><small>Optional</small></label>
                        <label class="sl-field"><span>Last name</span><input name="last_name" value="{{ old('last_name',$seller->last_name ?: str($seller->name)->after(' ')) }}" required></label>
                        <label class="sl-field"><span>Name extension / suffix</span><input name="name_extension" value="{{ old('name_extension',$seller->name_extension) }}" maxlength="20" placeholder="Jr., Sr., III"><small>Optional</small></label>
                        <label class="sl-field">
                            <span>Email address</span>
                            <input name="email" type="email" autocomplete="email" value="{{ old('email', $pendingEmail ?: $seller->email) }}" required>
                            <small>@if($pendingEmail)Verification code sent to the new address. Your current verified email stays active until confirmation.@elseif($seller->email_verified_at)Verified. Changing this email sends a code to the new address before it replaces this one.@else Verify your email before changing account contact details. @endif</small>
                        </label>
                        <label class="sl-field">
                            <span>Mobile number (private account contact)</span>
                            <input name="contact_number" type="tel" inputmode="tel" autocomplete="tel" maxlength="30" placeholder="+63 9XX XXX XXXX" value="{{ \App\Support\PhilippineMobileNumber::formatInternational((string) old('contact_number', filled($pendingPhone) ? $pendingPhone : $seller->contact_number)) }}">
                            <small>@if($pendingPhone)Confirmation code sent to your verified email. Your current mobile number stays active until confirmation.@else Use +63 9XX XXX XXXX. Changing this number sends a code to your verified email address. @endif Pickup contact details are managed separately under Business Information.</small>
                        </label>
                        @if(filled($pendingEmail) || filled($pendingPhone))
                            @php($contactOtpPurpose = filled($pendingPhone) ? 'mobile' : 'email')
                            <section class="sl-contact-verification sl-span-2" aria-labelledby="seller-contact-verification-title">
                                <div>
                                    <strong id="seller-contact-verification-title">{{ $contactOtpPurpose === 'mobile' ? 'Confirm mobile number change' : 'Verify new email address' }}</strong>
                                    <p>We sent a six-digit code to <strong>{{ $contactOtpPurpose === 'mobile' ? $seller->email : $pendingEmail }}</strong>. Codes expire after 5 minutes.</p>
                                </div>
                                <label class="sl-field">
                                    <span>Six-digit verification code</span>
                                    <input name="{{ $contactOtpPurpose === 'mobile' ? 'mobile_change_code' : 'email_change_code' }}" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" value="" required>
                                </label>
                                <div class="sl-contact-verification__actions">
                                    <button type="submit" name="contact_action" value="resend_{{ $contactOtpPurpose }}" class="sl-btn sl-btn-ghost sl-btn-sm" formnovalidate>Resend code</button>
                                    <button type="submit" name="contact_action" value="cancel" class="sl-btn sl-btn-ghost sl-btn-sm" formnovalidate>Cancel request</button>
                                </div>
                            </section>
                        @endif
                        <label class="sl-field"><span>Role</span><input value="Store Owner" disabled></label>
                        <label class="sl-field"><span>Seller ID</span><input value="SLR-{{ str_pad((string)$seller->id,5,'0',STR_PAD_LEFT) }}" disabled></label>
                    </div>
                    <div class="sl-form-actions"><button type="submit" class="sl-btn sl-btn-primary">Save Profile</button></div>
                </form>
            @endif
        </main>
    </div>
</div>

@endsection
