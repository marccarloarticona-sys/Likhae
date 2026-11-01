@extends('layouts.rider.app')

@section('title', 'Rider Account - LIKHAE')

@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/address-pin.js', 'resources/js/address/forms.js'])
@endpush

@section('content')
    <div class="lk-logistics-dashboard lk-rider-profile">
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

        <section class="lk-rider-profile__overview" aria-label="Rider account overview">
            <form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.update') }}" class="lk-dashboard-card lk-rider-profile__personal">
                @csrf
                @method('PATCH')
                <div class="lk-dashboard-card-head">
                    <div class="lk-dashboard-card-head__copy">
                        <span class="lk-dashboard-overline">Account details</span>
                        <h2>Personal information</h2>
                        <p>Update your contact number and profile photo.</p>
                    </div>
                </div>
                <div class="lk-rider-profile__card-content">
                    <div class="lk-rider-profile__photo">
                        <x-shared.profile-photo-upload :user="$rider->user" id="rider-profile-photo" />
                    </div>
                    <div class="lk-rider-profile__fields">
                        <label class="lk-rider-profile__field">First name
                            <input class="lk-rider-profile__input" value="{{ $rider->user?->first_name }}" readonly aria-readonly="true">
                        </label>
                        <label class="lk-rider-profile__field">Last name
                            <input class="lk-rider-profile__input" value="{{ $rider->user?->last_name }}" readonly aria-readonly="true">
                        </label>
                        <label class="lk-rider-profile__field lk-rider-profile__field--wide">Email
                            <input class="lk-rider-profile__input" type="email" value="{{ $rider->user?->email }}" readonly aria-readonly="true">
                        </label>
                    </div>
                    <p class="lk-rider-profile__protected-note">
                        Verified name and sign-in details are protected. Contact your Logistics center to request a review.
                        Birthday: {{ $rider->user?->birthday?->format('M d, Y') }} · Rider status: {{ str($rider->status)->replace('_', ' ')->headline() }}
                    </p>
                    <label class="lk-rider-profile__field">Contact number
                        <input class="lk-rider-profile__input" name="contact_number" value="{{ old('contact_number', $rider->user?->contact_number) }}" required autocomplete="tel">
                    </label>
                    <div class="lk-rider-profile__form-actions">
                        <button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Save contact details</button>
                    </div>
                </div>
            </form>

            <div class="lk-rider-profile__side">
                <article class="lk-dashboard-card">
                    <div class="lk-dashboard-card-head">
                        <div class="lk-dashboard-card-head__copy">
                            <span class="lk-dashboard-overline">Registered vehicle</span>
                            <h2>Vehicle information</h2>
                        </div>
                    </div>
                    <dl class="lk-rider-profile__details">
                        <div><dt>Vehicle type</dt><dd>{{ str($rider->vehicle_type)->headline() }}</dd></div>
                        <div><dt>Plate number</dt><dd>{{ $rider->plate_number ?: 'Not on file' }}</dd></div>
                        <div><dt>Driver’s license</dt><dd>{{ filled($rider->drivers_license_number) ? '•••• '.substr($rider->drivers_license_number, -4) : 'Not on file' }}</dd></div>
                    </dl>
                    <p class="lk-rider-profile__card-note">Use the update form below to submit vehicle or document changes for Logistics review.</p>
                </article>

                <article class="lk-dashboard-card">
                    <div class="lk-dashboard-card-head">
                        <div class="lk-dashboard-card-head__copy">
                            <span class="lk-dashboard-overline">Service coverage</span>
                            <h2>Logistics assignment</h2>
                        </div>
                    </div>
                    <dl class="lk-rider-profile__details">
                        <div><dt>Assigned Logistics center</dt><dd>{{ $rider->logisticsCenter?->business_name ?: 'Not assigned' }}</dd></div>
                        <div><dt>Coverage areas</dt><dd>{{ $rider->areaAssignments->where('is_active', true)->pluck('serviceArea.name')->filter()->join(', ') ?: 'No active area' }}</dd></div>
                        <div><dt>Rider status</dt><dd>{{ str($rider->status)->replace('_', ' ')->headline() }}</dd></div>
                        <div><dt>Operational type</dt><dd>{{ str($rider->vehicleRole())->replace('_', ' ')->headline() }}</dd></div>
                    </dl>
                </article>
            </div>

            <article class="lk-dashboard-card lk-rider-profile__documents">
                <div class="lk-dashboard-card-head">
                    <div class="lk-dashboard-card-head__copy">
                        <span class="lk-dashboard-overline">Verification</span>
                        <h2>Required documents</h2>
                        <p>Private document files are not shown here.</p>
                    </div>
                </div>
                @if(($riderDocuments ?? collect())->isEmpty())
                    <p class="lk-rider-profile__empty">No document records are attached to your Rider application.</p>
                @else
                    <div class="lk-rider-profile__document-list">
                        @foreach($riderDocuments as $document)
                            @php($documentStatus = strtoupper((string) $document->verification_status))
                            <div class="lk-rider-profile__document-row">
                                <div>
                                    <strong>{{ str($document->document_type)->replace('_', ' ')->headline() }}</strong>
                                    @if($document->verified_at)
                                        <p>Reviewed {{ $document->verified_at->format('M d, Y') }}</p>
                                    @endif
                                </div>
                                <span class="lk-rider-profile__document-status" data-status="{{ strtolower($documentStatus) }}">{{ str($documentStatus)->replace('_', ' ')->headline() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        </section>

        <section class="lk-dashboard-card lk-rider-profile__review" aria-labelledby="rider-profile-review-heading">
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Profile verification</span>
                    <h2 id="rider-profile-review-heading">Vehicle and document re-verification</h2>
                    <p>Submit vehicle or license changes with the matching supporting document. Logistics reviews the request before it changes your verified profile.</p>
                </div>
            </div>
            <div class="lk-rider-profile__card-content">
                @if(! $profileUpdateSchemaReady)
                    <p class="lk-dashboard-alert">Profile review is temporarily unavailable while the update workflow is installed.</p>
                @else
                    @php($pendingProfileUpdate = $profileUpdateRequests->firstWhere('status', 'PENDING'))
                    @if($pendingProfileUpdate)
                        <div class="lk-rider-profile__pending" role="status">
                            <strong>Update awaiting Logistics review</strong>
                            <p>Submitted {{ $pendingProfileUpdate->submitted_at?->format('M d, Y g:i A') }} · {{ str($pendingProfileUpdate->vehicle_type)->replace('_', ' ')->headline() }} · {{ $pendingProfileUpdate->plate_number }}</p>
                            @if($pendingProfileUpdate->documents->isNotEmpty())
                                <p>Documents: {{ $pendingProfileUpdate->documents->map(fn ($document) => str($document->document_type)->replace('_', ' ')->headline().' ('.str($document->verification_status)->headline().')')->join(', ') }}</p>
                            @endif
                        </div>
                    @else
                        <form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.updates.store') }}" class="lk-rider-profile__review-form">
                            @csrf
                            <label class="lk-rider-profile__field">Vehicle type
                                <select name="vehicle_type" required class="lk-rider-profile__input">
                                    @foreach(\App\Support\RiderVehicleType::registrationOptions() as $value => $label)
                                        <option value="{{ $value }}" @selected(old('vehicle_type', $rider->vehicle_type) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="lk-rider-profile__field">Plate number
                                <input class="lk-rider-profile__input" name="plate_number" value="{{ old('plate_number', $rider->plate_number) }}" maxlength="50" required>
                            </label>
                            <label class="lk-rider-profile__field lk-rider-profile__field--wide">Driver’s license number
                                <input class="lk-rider-profile__input" name="drivers_license_number" value="{{ old('drivers_license_number', $rider->drivers_license_number) }}" maxlength="100" required>
                            </label>
                            <label class="lk-rider-profile__field">Vehicle OR/CR document
                                <input class="lk-rider-profile__input lk-rider-profile__input--file" type="file" name="vehicle_or_cr" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                <span class="lk-rider-profile__hint">Required when changing the vehicle type or plate. JPG, PNG, WebP, or PDF up to 10 MB.</span>
                            </label>
                            <label class="lk-rider-profile__field">Driver’s license document
                                <input class="lk-rider-profile__input lk-rider-profile__input--file" type="file" name="drivers_license" accept=".jpg,.jpeg,.png,.webp,.pdf">
                                <span class="lk-rider-profile__hint">Required when changing the license number. JPG, PNG, WebP, or PDF up to 10 MB.</span>
                            </label>
                            <div class="lk-rider-profile__form-actions lk-rider-profile__field--wide">
                                <button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Submit for Logistics review</button>
                            </div>
                        </form>
                    @endif

                    @if($profileUpdateRequests->isNotEmpty())
                        <div class="lk-rider-profile__history">
                            <h3>Recent update requests</h3>
                            <div>
                                @foreach($profileUpdateRequests as $updateRequest)
                                    <article class="lk-rider-profile__history-row">
                                        <div class="lk-rider-profile__history-heading">
                                            <strong>{{ str($updateRequest->status)->replace('_', ' ')->headline() }} · {{ $updateRequest->submitted_at?->format('M d, Y g:i A') }}</strong>
                                            <span>{{ str($updateRequest->vehicle_type)->replace('_', ' ')->headline() }} · {{ $updateRequest->plate_number }}</span>
                                        </div>
                                        @if($updateRequest->review_notes)
                                            <p>Logistics note: {{ $updateRequest->review_notes }}</p>
                                        @endif
                                    </article>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </section>

        <form method="POST" action="{{ route('rider.account.address.update') }}" class="lk-dashboard-card lk-rider-profile__address">
            @csrf
            @method('PATCH')
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Residential details</span>
                    <h2>Registered residential address</h2>
                    <p>This address is separate from your live delivery location.</p>
                </div>
            </div>
            <div class="lk-rider-profile__card-content lk-rider-profile__address-content">
                <x-shared.philippine-address-fields :address="$residentialAddress" field-class="lk-rider-profile__input" wrapper-class="contents" />
                <x-shared.address-pin :address="$residentialAddress" class="lk-rider-profile__address-pin" />
                <div class="lk-rider-profile__form-actions lk-rider-profile__field--wide">
                    <button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Save registered address</button>
                </div>
            </div>
        </form>

        <form method="POST" action="{{ route('rider.account.password.update') }}" class="lk-dashboard-card lk-rider-profile__password">
            @csrf
            @method('PATCH')
            <div class="lk-dashboard-card-head">
                <div class="lk-dashboard-card-head__copy">
                    <span class="lk-dashboard-overline">Account security</span>
                    <h2>Change password</h2>
                    <p>Use a strong password you do not use elsewhere.</p>
                </div>
            </div>
            <div class="lk-rider-profile__password-content">
                <input class="lk-rider-profile__input" name="current_password" type="password" placeholder="Current password" required autocomplete="current-password" aria-label="Current password">
                <input class="lk-rider-profile__input" name="password" type="password" placeholder="New password" required autocomplete="new-password" aria-label="New password">
                <input class="lk-rider-profile__input" name="password_confirmation" type="password" placeholder="Confirm password" required autocomplete="new-password" aria-label="Confirm password">
                <button type="submit" class="lk-dashboard-button lk-dashboard-button--primary">Update password</button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="lk-rider-profile__logout" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
            @csrf
            <button class="lk-dashboard-button lk-rider-profile__logout-button">Log out</button>
        </form>
    </div>
@endsection
