@extends('layouts.rider.app')

@section('title', 'Rider Account - LIKHAE')

@push('head')
    @vite('resources/js/address/forms.js')
@endpush

@section('content')
    @php
        $accountErrorKeys = $errors->keys();
        $hasAccountError = static fn (array $fields): bool => count(array_intersect($accountErrorKeys, $fields)) > 0;
        $activeProfileTab = match (true) {
            $hasAccountError(['current_password', 'password', 'password_confirmation']) => 'security',
            $hasAccountError(['payout', 'payout_method', 'payout_bank_name', 'payout_account_name', 'payout_account_number']) => 'payment',
            $hasAccountError(['region_code', 'region_name', 'province_code', 'province_name', 'municipality_code', 'municipality_name', 'barangay_code', 'barangay_name', 'postal_code', 'street_address', 'house_number', 'landmark', 'latitude', 'longitude']) => 'address',
            $hasAccountError(['profile_update', 'vehicle_type', 'plate_number', 'drivers_license_number', 'vehicle_or_cr', 'drivers_license']) => 'vehicle',
            $hasAccountError(['contact_number', 'profile_photo', 'profile_photo_path']) => 'personal',
            default => 'personal',
        };
        $canUseLocalRoutes = \App\Support\RiderVehicleType::isLocalBarangay($rider->vehicle_type);
    @endphp

    <div class="lk-rider-profile">
        <header class="lk-dashboard-heading lk-rider-profile__hero">
            <div class="lk-dashboard-heading__copy">
                <span class="lk-dashboard-overline">Rider account</span>
                <h1>My Profile</h1>
                <p>{{ $rider->user?->name }} · Account information, vehicle details, and verification.</p>
            </div>
            <div class="lk-dashboard-heading__actions">
                <span class="lk-rider-profile__status" data-status="{{ str($rider->status)->lower()->replace('_', '-') }}">
                    {{ str($rider->status)->replace('_', ' ')->headline() }}
                </span>
            </div>
        </header>

        @if(session('status'))
            <div class="lk-rider-profile__notice lk-rider-profile__notice--success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="lk-rider-profile__notice lk-rider-profile__notice--error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="lk-rider-profile__account-layout">
            <nav class="lk-rider-profile__account-nav" aria-label="Account sections">
                <div class="lk-rider-profile__account-identity">
                    <span class="lk-rider-profile__account-avatar">
                        <x-shared.profile-image :path="$rider->user?->profile_photo_path" role="rider" :alt="$rider->user?->name ?? 'Rider profile photo'" />
                    </span>
                    <span class="lk-rider-profile__account-identity-copy">
                        <strong>{{ $rider->user?->name }}</strong>
                        <small>{{ $rider->user?->email }}</small>
                    </span>
                </div>

                <div class="lk-rider-profile__account-tabs" role="tablist" aria-label="Rider profile sections" aria-orientation="vertical">
                    <a id="tab-personal" href="#personal" class="lk-rider-profile__account-tab{{ $activeProfileTab === 'personal' ? ' is-active' : '' }}" role="tab" aria-controls="personal" aria-selected="{{ $activeProfileTab === 'personal' ? 'true' : 'false' }}" tabindex="{{ $activeProfileTab === 'personal' ? '0' : '-1' }}">
                        <span class="lk-rider-profile__account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg></span>
                        <span class="lk-rider-profile__account-tab-copy"><strong>Personal info</strong><small>Contact and photo</small></span>
                    </a>
                    <a id="tab-vehicle" href="#vehicle" class="lk-rider-profile__account-tab{{ $activeProfileTab === 'vehicle' ? ' is-active' : '' }}" role="tab" aria-controls="vehicle" aria-selected="{{ $activeProfileTab === 'vehicle' ? 'true' : 'false' }}" tabindex="{{ $activeProfileTab === 'vehicle' ? '0' : '-1' }}">
                        <span class="lk-rider-profile__account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 14v-3l2-5h14l2 5v3M5 14h14v5H5z"/><circle cx="8" cy="16" r="1"/><circle cx="16" cy="16" r="1"/></svg></span>
                        <span class="lk-rider-profile__account-tab-copy"><strong>Vehicle &amp; verification</strong><small>Vehicle, documents, hub</small></span>
                    </a>
                    <a id="tab-address" href="#address" class="lk-rider-profile__account-tab{{ $activeProfileTab === 'address' ? ' is-active' : '' }}" role="tab" aria-controls="address" aria-selected="{{ $activeProfileTab === 'address' ? 'true' : 'false' }}" tabindex="{{ $activeProfileTab === 'address' ? '0' : '-1' }}">
                        <span class="lk-rider-profile__account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2.3"/></svg></span>
                        <span class="lk-rider-profile__account-tab-copy"><strong>Address</strong><small>Registered residence</small></span>
                    </a>
                    <a id="tab-payment" href="#payment" class="lk-rider-profile__account-tab{{ $activeProfileTab === 'payment' ? ' is-active' : '' }}" role="tab" aria-controls="payment" aria-selected="{{ $activeProfileTab === 'payment' ? 'true' : 'false' }}" tabindex="{{ $activeProfileTab === 'payment' ? '0' : '-1' }}">
                        <span class="lk-rider-profile__account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span>
                        <span class="lk-rider-profile__account-tab-copy"><strong>Payment</strong><small>{{ $canUseLocalRoutes ? 'Payout destination' : 'Payroll details' }}</small></span>
                    </a>
                    <a id="tab-security" href="#security" class="lk-rider-profile__account-tab{{ $activeProfileTab === 'security' ? ' is-active' : '' }}" role="tab" aria-controls="security" aria-selected="{{ $activeProfileTab === 'security' ? 'true' : 'false' }}" tabindex="{{ $activeProfileTab === 'security' ? '0' : '-1' }}">
                        <span class="lk-rider-profile__account-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3M12 14v3"/></svg></span>
                        <span class="lk-rider-profile__account-tab-copy"><strong>Security</strong><small>Password and sign out</small></span>
                    </a>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="lk-rider-profile__account-logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
                    @csrf
                    <button type="submit"><span aria-hidden="true">↗</span>Log out</button>
                </form>
            </nav>

            <div class="lk-rider-profile__account-panels">
                <section class="lk-rider-profile__account-panel" id="personal" role="tabpanel" aria-labelledby="tab-personal" tabindex="0" @if($activeProfileTab !== 'personal') hidden @endif>
                    <form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.update') }}" class="lk-dashboard-card">
                        @csrf
                        @method('PATCH')
                        <div class="lk-dashboard-card-head">
                            <div class="lk-dashboard-card-head__copy">
                                <span class="lk-dashboard-overline">Account details</span>
                                <h2>Personal Information</h2>
                                <p>Update your contact number and profile photo.</p>
                            </div>
                        </div>
                        <div class="lk-rider-profile__card-content">
                            <div class="lk-rider-profile__photo"><x-shared.profile-photo-upload :user="$rider->user" id="rider-profile-photo" /></div>
                            <div class="lk-rider-profile__fields">
                                <label class="lk-rider-profile__field">First name<input class="lk-rider-profile__input" value="{{ $rider->user?->first_name }}" readonly aria-readonly="true"></label>
                                <label class="lk-rider-profile__field">Last name<input class="lk-rider-profile__input" value="{{ $rider->user?->last_name }}" readonly aria-readonly="true"></label>
                                <label class="lk-rider-profile__field lk-rider-profile__field--wide">Email<input class="lk-rider-profile__input" type="email" value="{{ $rider->user?->email }}" readonly aria-readonly="true"></label>
                            </div>
                            <p class="lk-rider-profile__protected-note">Verified name and sign-in details are protected. Contact your Logistics center to request a review. Birthday: {{ $rider->user?->birthday?->format('M d, Y') }} · Rider status: {{ str($rider->status)->replace('_', ' ')->headline() }}</p>
                            <label class="lk-rider-profile__field">Contact number<input class="lk-rider-profile__input" name="contact_number" value="{{ old('contact_number', $rider->user?->contact_number) }}" required autocomplete="tel"></label>
                            <div class="lk-rider-profile__form-actions"><button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Save contact details</button></div>
                        </div>
                    </form>
                </section>

                <section class="lk-rider-profile__account-panel" id="vehicle" role="tabpanel" aria-labelledby="tab-vehicle" tabindex="0" @if($activeProfileTab !== 'vehicle') hidden @endif>
                    <div class="lk-rider-profile__overview">
                        <article class="lk-dashboard-card">
                            <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Registered vehicle</span><h2>Vehicle Information</h2></div></div>
                            <dl class="lk-rider-profile__details">
                                <div><dt>Vehicle type</dt><dd>{{ str($rider->vehicle_type)->headline() }}</dd></div>
                                <div><dt>Plate number</dt><dd>{{ $rider->plate_number ?: 'Not on file' }}</dd></div>
                                <div><dt>Driver’s license</dt><dd>{{ filled($rider->drivers_license_number) ? '•••• '.substr($rider->drivers_license_number, -4) : 'Not on file' }}</dd></div>
                            </dl>
                            <p class="lk-rider-profile__card-note">Use the update form below to submit vehicle or document changes for Logistics review.</p>
                        </article>

                        <article class="lk-dashboard-card">
                            <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Service coverage</span><h2>Logistics assignment</h2></div></div>
                            <dl class="lk-rider-profile__details">
                                <div><dt>Assigned Logistics center</dt><dd>{{ $rider->logisticsCenter?->business_name ?: 'Not assigned' }}</dd></div>
                                <div><dt>Coverage areas</dt><dd>{{ $rider->areaAssignments->where('is_active', true)->pluck('serviceArea.name')->filter()->join(', ') ?: 'No active area' }}</dd></div>
                                <div><dt>Rider status</dt><dd>{{ str($rider->status)->replace('_', ' ')->headline() }}</dd></div>
                                <div><dt>Operational type</dt><dd>{{ str($rider->vehicleRole())->replace('_', ' ')->headline() }}</dd></div>
                            </dl>
                        </article>

                        <article class="lk-dashboard-card lk-rider-profile__documents">
                            <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Verification</span><h2>Required Documents</h2><p>Private document files are not shown here.</p></div></div>
                            @if(($riderDocuments ?? collect())->isEmpty())
                                <p class="lk-rider-profile__empty">No document records are attached to your Rider application.</p>
                            @else
                                <div class="lk-rider-profile__document-list">
                                    @foreach($riderDocuments as $document)
                                        @php($documentStatus = strtoupper((string) $document->verification_status))
                                        <div class="lk-rider-profile__document-row">
                                            <div><strong>{{ str($document->document_type)->replace('_', ' ')->headline() }}</strong>@if($document->verified_at)<p>Reviewed {{ $document->verified_at->format('M d, Y') }}</p>@endif</div>
                                            <span class="lk-rider-profile__document-status" data-status="{{ strtolower($documentStatus) }}">{{ str($documentStatus)->replace('_', ' ')->headline() }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    </div>

                    <section class="lk-dashboard-card lk-rider-profile__review" aria-labelledby="rider-profile-review-heading">
                        <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Profile verification</span><h2 id="rider-profile-review-heading">Vehicle and document re-verification</h2><p>Submit vehicle or license changes with the matching supporting document. Logistics reviews the request before it changes your verified profile.</p></div></div>
                        <div class="lk-rider-profile__card-content">
                            @if(! $profileUpdateSchemaReady)
                                <p class="lk-dashboard-alert">Profile review is temporarily unavailable while the update workflow is installed.</p>
                            @else
                                @php($pendingProfileUpdate = $profileUpdateRequests->firstWhere('status', 'PENDING'))
                                @if($pendingProfileUpdate)
                                    <div class="lk-rider-profile__pending" role="status">
                                        <strong>Update awaiting Logistics review</strong>
                                        <p>Submitted {{ $pendingProfileUpdate->submitted_at?->format('M d, Y g:i A') }} · {{ str($pendingProfileUpdate->vehicle_type)->replace('_', ' ')->headline() }} · {{ $pendingProfileUpdate->plate_number }}</p>
                                        @if($pendingProfileUpdate->documents->isNotEmpty())<p>Documents: {{ $pendingProfileUpdate->documents->map(fn ($document) => str($document->document_type)->replace('_', ' ')->headline().' ('.str($document->verification_status)->headline().')')->join(', ') }}</p>@endif
                                    </div>
                                @else
                                    <form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.updates.store') }}" class="lk-rider-profile__review-form">
                                        @csrf
                                        <label class="lk-rider-profile__field">Vehicle type<select name="vehicle_type" required class="lk-rider-profile__input">@foreach(\App\Support\RiderVehicleType::registrationOptions() as $value => $label)<option value="{{ $value }}" @selected(old('vehicle_type', $rider->vehicle_type) === $value)>{{ $label }}</option>@endforeach</select></label>
                                        <label class="lk-rider-profile__field">Plate number<input class="lk-rider-profile__input" name="plate_number" value="{{ old('plate_number', $rider->plate_number) }}" maxlength="50" required></label>
                                        <label class="lk-rider-profile__field lk-rider-profile__field--wide">Driver’s license number<input class="lk-rider-profile__input" name="drivers_license_number" value="{{ old('drivers_license_number', $rider->drivers_license_number) }}" maxlength="100" required></label>
                                        <label class="lk-rider-profile__field">Vehicle OR/CR document<input class="lk-rider-profile__input lk-rider-profile__input--file" type="file" name="vehicle_or_cr" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="lk-rider-profile__hint">Required when changing the vehicle type or plate. JPG, PNG, WebP, or PDF up to 10 MB.</span></label>
                                        <label class="lk-rider-profile__field">Driver’s license document<input class="lk-rider-profile__input lk-rider-profile__input--file" type="file" name="drivers_license" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="lk-rider-profile__hint">Required when changing the license number. JPG, PNG, WebP, or PDF up to 10 MB.</span></label>
                                        <div class="lk-rider-profile__form-actions lk-rider-profile__field--wide"><button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Submit for Logistics review</button></div>
                                    </form>
                                @endif

                                @if($profileUpdateRequests->isNotEmpty())
                                    <div class="lk-rider-profile__history">
                                        <h3>Recent update requests</h3>
                                        <div>@foreach($profileUpdateRequests as $updateRequest)<article class="lk-rider-profile__history-row"><div class="lk-rider-profile__history-heading"><strong>{{ str($updateRequest->status)->replace('_', ' ')->headline() }} · {{ $updateRequest->submitted_at?->format('M d, Y g:i A') }}</strong><span>{{ str($updateRequest->vehicle_type)->replace('_', ' ')->headline() }} · {{ $updateRequest->plate_number }}</span></div>@if($updateRequest->review_notes)<p>Logistics note: {{ $updateRequest->review_notes }}</p>@endif</article>@endforeach</div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </section>
                </section>

                <section class="lk-rider-profile__account-panel" id="address" role="tabpanel" aria-labelledby="tab-address" tabindex="0" @if($activeProfileTab !== 'address') hidden @endif>
                    <form method="POST" action="{{ route('rider.account.address.update') }}" class="lk-dashboard-card lk-rider-profile__address">
                        @csrf
                        @method('PATCH')
                        <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Residential details</span><h2>Registered residential address</h2><p>This address is separate from your live delivery location.</p></div></div>
                        <div class="lk-rider-profile__card-content lk-rider-profile__address-content">
                            <x-shared.philippine-address-fields :address="$residentialAddress" field-class="lk-rider-profile__input" wrapper-class="contents" />
                            <div class="lk-rider-profile__form-actions lk-rider-profile__field--wide"><button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Save registered address</button></div>
                        </div>
                    </form>
                </section>

                <section class="lk-rider-profile__account-panel" id="payment" role="tabpanel" aria-labelledby="tab-payment" tabindex="0" @if($activeProfileTab !== 'payment') hidden @endif>
                    <article class="lk-dashboard-card">
                        <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Payment preferences</span><h2>{{ $canUseLocalRoutes ? 'Online payout details' : 'Interhub payroll' }}</h2><p>{{ $canUseLocalRoutes ? 'Save the destination Logistics will use for your rider payouts.' : 'Interhub driver pay is recorded through shift timekeeping and Logistics payroll.' }}</p></div>@if($canUseLocalRoutes && $payoutDestinationSaved)<span class="lk-rider-profile__document-status" data-status="verified">Destination saved</span>@endif</div>
                        <div class="lk-rider-profile__card-content">
                            @if($canUseLocalRoutes)
                                @if($payoutDestinationReady)
                                    @php($riderPayoutMethod = old('payout_method', $rider->payout_method))
                                    <form method="POST" action="{{ route('rider.wallet.payout-destination.update') }}" class="lk-rider-profile__payment-form" data-payout-destination-form>
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="return_to" value="profile">
                                        <label class="lk-rider-profile__field">Payment channel<select name="payout_method" required class="lk-rider-profile__input"><option value="">Select a channel</option>@foreach(['GCash', 'Maya', 'Bank Transfer', 'Other'] as $method)<option value="{{ $method }}" @selected($riderPayoutMethod === $method)>{{ $method }}</option>@endforeach</select></label>
                                        <label class="lk-rider-profile__field" data-payout-bank-field @if($riderPayoutMethod !== 'Bank Transfer') hidden @endif>Bank name<input class="lk-rider-profile__input" name="payout_bank_name" maxlength="120" value="{{ old('payout_bank_name', $rider->payout_bank_name) }}" autocomplete="organization"></label>
                                        <label class="lk-rider-profile__field">Account holder name<input class="lk-rider-profile__input" name="payout_account_name" maxlength="150" value="{{ old('payout_account_name', $rider->payout_account_name) }}" required autocomplete="name"></label>
                                        <label class="lk-rider-profile__field">Account number or mobile<input class="lk-rider-profile__input" name="payout_account_number" maxlength="150" value="{{ old('payout_account_number', $rider->payout_account_number) }}" required autocomplete="off"></label>
                                        <p class="lk-rider-profile__protected-note lk-rider-profile__field--wide">Account name and number are encrypted at rest. Logistics records payout references and proof in your wallet.</p>
                                        <div class="lk-rider-profile__form-actions lk-rider-profile__field--wide"><button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Save payment details</button><a class="lk-rider-profile__text-link" href="{{ route('rider.wallet') }}">Open wallet &amp; payouts</a></div>
                                    </form>
                                @else
                                    <p class="lk-rider-profile__empty">Payout details will be available after the payout destination setup is installed.</p>
                                @endif
                            @else
                                <p class="lk-rider-profile__protected-note">Interhub drivers are paid through Logistics payroll. Shift estimates, payroll status, transfer references, and payment proof are available in your Interhub wallet.</p>
                                <div class="lk-rider-profile__form-actions"><a class="lk-dashboard-button lk-dashboard-button--primary" href="{{ route('rider.wallet') }}">Open wallet &amp; payroll</a></div>
                            @endif
                        </div>
                    </article>
                </section>

                <section class="lk-rider-profile__account-panel" id="security" role="tabpanel" aria-labelledby="tab-security" tabindex="0" @if($activeProfileTab !== 'security') hidden @endif>
                    <form method="POST" action="{{ route('rider.account.password.update') }}" class="lk-dashboard-card">
                        @csrf
                        @method('PATCH')
                        <div class="lk-dashboard-card-head"><div class="lk-dashboard-card-head__copy"><span class="lk-dashboard-overline">Account security</span><h2>Change password</h2><p>Use a strong password you do not use elsewhere.</p></div></div>
                        <div class="lk-rider-profile__password-content">
                            <label class="lk-rider-profile__field">Current password<input class="lk-rider-profile__input" name="current_password" type="password" required autocomplete="current-password"></label>
                            <label class="lk-rider-profile__field">New password<input class="lk-rider-profile__input" name="password" type="password" required autocomplete="new-password"></label>
                            <label class="lk-rider-profile__field">Confirm password<input class="lk-rider-profile__input" name="password_confirmation" type="password" required autocomplete="new-password"></label>
                            <div class="lk-rider-profile__form-actions"><button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Update password</button></div>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const page = document.querySelector('.lk-rider-profile');
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
                    if (panels[index]) panels[index].hidden = !selected;
                });
                if (urlMode === 'push') window.history.pushState({ riderProfileTab: tab.hash }, '', tab.hash);
                if (urlMode === 'replace') window.history.replaceState({ riderProfileTab: tab.hash }, '', tab.hash);
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

            const syncOrientation = () => tabList?.setAttribute('aria-orientation', window.matchMedia('(max-width: 900px)').matches ? 'horizontal' : 'vertical');
            syncOrientation();
            window.matchMedia('(max-width: 900px)').addEventListener?.('change', syncOrientation);
            activate(tabForHash() || defaultTab);
            window.addEventListener('popstate', () => activate(tabForHash() || defaultTab));
            window.addEventListener('hashchange', () => activate(tabForHash() || defaultTab));
        })();
    </script>
@endpush
