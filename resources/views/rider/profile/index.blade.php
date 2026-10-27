@extends('layouts.rider.app')
@section('title','Rider Account - LIKHAE')
@push('head')
    @vite(['resources/css/shared/mapbox.css', 'resources/js/shared/address-pin.js', 'resources/js/address/forms.js'])
@endpush
@section('content')
<div class="space-y-6"><header><p class="text-xs font-bold uppercase text-primary">My Account</p><h1 class="mt-2 text-2xl font-bold">My Profile</h1><p class="mt-2 text-sm text-muted">{{ $rider->user?->name }} · Rider account information and verification.</p></header>
	@if(session('status'))<div class="border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
	@if($errors->any())<div class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first() }}</div>@endif
	<section class="grid gap-4 md:grid-cols-2">
		<form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.update') }}" class="border border-line bg-surface p-5">@csrf @method('PATCH')<h2 class="font-semibold">Personal Information</h2><div class="my-4"><x-shared.profile-photo-upload :user="$rider->user" id="rider-profile-photo" /></div><div class="mt-4 grid gap-3"><label class="grid gap-1 text-sm">First name<input class="border border-line bg-page-secondary px-3 py-2" value="{{ $rider->user?->first_name }}" readonly aria-readonly="true"></label><label class="grid gap-1 text-sm">Last name<input class="border border-line bg-page-secondary px-3 py-2" value="{{ $rider->user?->last_name }}" readonly aria-readonly="true"></label><label class="grid gap-1 text-sm">Email<input class="border border-line bg-page-secondary px-3 py-2" type="email" value="{{ $rider->user?->email }}" readonly aria-readonly="true"></label><p class="text-xs text-muted">Verified name and sign-in details are protected. Contact your Logistics center to request a review. Birthday: {{ $rider->user?->birthday?->format('M d, Y') }} · Rider status: {{ str($rider->status)->replace('_', ' ')->headline() }}</p><label class="grid gap-1 text-sm">Contact number<input class="border border-line px-3 py-2" name="contact_number" value="{{ old('contact_number', $rider->user?->contact_number) }}" required autocomplete="tel"></label><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Save Contact Details</button></div></form>
		<article class="border border-line bg-surface p-5"><h2 class="font-semibold">Vehicle Information</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-muted">Vehicle type</dt><dd>{{ str($rider->vehicle_type)->headline() }}</dd></div><div><dt class="text-muted">Plate number</dt><dd>{{ $rider->plate_number ?: 'Not on file' }}</dd></div><div><dt class="text-muted">Driver's license</dt><dd>{{ filled($rider->drivers_license_number) ? '•••• '.substr($rider->drivers_license_number, -4) : 'Not on file' }}</dd></div></dl><p class="mt-4 text-xs text-muted">Use the update form below to submit vehicle or document changes for Logistics review.</p></article>
		<article class="border border-line bg-surface p-5"><h2 class="font-semibold">Logistics Assignment</h2><dl class="mt-4 space-y-3 text-sm"><div><dt class="text-muted">Assigned Logistics center</dt><dd>{{ $rider->logisticsCenter?->business_name ?: 'Not assigned' }}</dd></div><div><dt class="text-muted">Coverage areas</dt><dd>{{ $rider->areaAssignments->where('is_active',true)->pluck('serviceArea.name')->filter()->join(', ') ?: 'No active area' }}</dd></div><div><dt class="text-muted">Rider status</dt><dd>{{ str($rider->status)->replace('_', ' ')->headline() }}</dd></div><div><dt class="text-muted">Operational type</dt><dd>{{ str($rider->vehicleRole())->replace('_', ' ')->headline() }}</dd></div></dl></article>
		<article class="border border-line bg-surface p-5"><h2 class="font-semibold">Required Documents</h2><p class="mt-1 text-xs text-muted">Private document files are not shown here.</p>@if(($riderDocuments ?? collect())->isEmpty())<p class="mt-4 text-sm text-muted">No document records are attached to your Rider application.</p>@else<div class="mt-4 divide-y divide-line">@foreach($riderDocuments as $document)@php($documentStatus = strtoupper((string) $document->verification_status))<div class="flex flex-wrap items-center justify-between gap-3 py-3"><div><strong class="text-sm">{{ str($document->document_type)->replace('_', ' ')->headline() }}</strong>@if($document->verified_at)<p class="mt-1 text-xs text-muted">Reviewed {{ $document->verified_at->format('M d, Y') }}</p>@endif</div><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold @if($documentStatus === 'VERIFIED') border-green-200 bg-green-50 text-green-800 @elseif($documentStatus === 'REJECTED') border-red-200 bg-red-50 text-red-800 @else border-amber-200 bg-amber-50 text-amber-900 @endif">{{ str($documentStatus)->replace('_', ' ')->headline() }}</span></div>@endforeach</div>@endif</article>
	</section>
	<section class="border border-line bg-surface p-5">
		<div>
			<h2 class="font-semibold">Vehicle and Document Re-verification</h2>
			<p class="mt-1 text-sm text-muted">Submit vehicle or license changes with the matching supporting document. Logistics reviews the request before it changes your verified profile.</p>
		</div>
		@if(! $profileUpdateSchemaReady)
			<p class="mt-4 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">Profile review is temporarily unavailable while the update workflow is installed.</p>
		@else
			@php($pendingProfileUpdate = $profileUpdateRequests->firstWhere('status', 'PENDING'))
			@if($pendingProfileUpdate)
				<div class="mt-4 border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
					<strong>Update awaiting Logistics review</strong>
					<p class="mt-1">Submitted {{ $pendingProfileUpdate->submitted_at?->format('M d, Y g:i A') }} · {{ str($pendingProfileUpdate->vehicle_type)->replace('_', ' ')->headline() }} · {{ $pendingProfileUpdate->plate_number }}</p>
					@if($pendingProfileUpdate->documents->isNotEmpty())
						<p class="mt-1">Documents: {{ $pendingProfileUpdate->documents->map(fn ($document) => str($document->document_type)->replace('_', ' ')->headline().' ('.str($document->verification_status)->headline().')')->join(', ') }}</p>
					@endif
				</div>
			@else
				<form method="POST" enctype="multipart/form-data" action="{{ route('rider.account.profile.updates.store') }}" class="mt-4 grid gap-3 md:grid-cols-2">
					@csrf
					<label class="grid gap-1 text-sm">Vehicle type
						<select name="vehicle_type" required class="border border-line px-3 py-2">
							@foreach(\App\Support\RiderVehicleType::registrationOptions() as $value => $label)
								<option value="{{ $value }}" @selected(old('vehicle_type', $rider->vehicle_type) === $value)>{{ $label }}</option>
							@endforeach
						</select>
					</label>
					<label class="grid gap-1 text-sm">Plate number<input class="border border-line px-3 py-2" name="plate_number" value="{{ old('plate_number', $rider->plate_number) }}" maxlength="50" required></label>
					<label class="grid gap-1 text-sm md:col-span-2">Driver's license number<input class="border border-line px-3 py-2" name="drivers_license_number" value="{{ old('drivers_license_number', $rider->drivers_license_number) }}" maxlength="100" required></label>
					<label class="grid gap-1 text-sm">Vehicle OR/CR document<input class="border border-line px-3 py-2" type="file" name="vehicle_or_cr" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="text-xs text-muted">Required when changing the vehicle type or plate. JPG, PNG, WebP, or PDF up to 10 MB.</span></label>
					<label class="grid gap-1 text-sm">Driver's license document<input class="border border-line px-3 py-2" type="file" name="drivers_license" accept=".jpg,.jpeg,.png,.webp,.pdf"><span class="text-xs text-muted">Required when changing the license number. JPG, PNG, WebP, or PDF up to 10 MB.</span></label>
					<div class="md:col-span-2"><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Submit for Logistics Review</button></div>
				</form>
			@endif
			@if($profileUpdateRequests->isNotEmpty())
				<div class="mt-5 border-t border-line pt-4">
					<h3 class="text-sm font-semibold">Recent update requests</h3>
					<div class="mt-2 divide-y divide-line">
						@foreach($profileUpdateRequests as $updateRequest)
							<div class="py-3 text-sm">
								<div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ str($updateRequest->status)->replace('_', ' ')->headline() }} · {{ $updateRequest->submitted_at?->format('M d, Y g:i A') }}</strong><span>{{ str($updateRequest->vehicle_type)->replace('_', ' ')->headline() }} · {{ $updateRequest->plate_number }}</span></div>
								@if($updateRequest->review_notes)<p class="mt-1 text-xs text-muted">Logistics note: {{ $updateRequest->review_notes }}</p>@endif
							</div>
						@endforeach
					</div>
				</div>
			@endif
		@endif
	</section>
	<form method="POST" action="{{ route('rider.account.address.update') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-2">@csrf @method('PATCH')<div class="md:col-span-2"><h2 class="font-semibold">Registered Residential Address</h2><p class="mt-1 text-sm text-muted">This address is separate from your live delivery location.</p></div><x-shared.philippine-address-fields :address="$residentialAddress" field-class="border border-line px-3 py-2" wrapper-class="contents" /><x-shared.address-pin :address="$residentialAddress" class="md:col-span-2" /><div class="md:col-span-2"><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Save Registered Address</button></div></form>
	<form method="POST" action="{{ route('rider.account.password.update') }}" class="grid gap-3 border border-line bg-surface p-5 md:grid-cols-4">@csrf @method('PATCH')<h2 class="font-semibold md:col-span-4">Account &amp; Security · Change Password</h2><input class="border border-line px-3 py-2" name="current_password" type="password" placeholder="Current password" required autocomplete="current-password"><input class="border border-line px-3 py-2" name="password" type="password" placeholder="New password" required autocomplete="new-password"><input class="border border-line px-3 py-2" name="password_confirmation" type="password" placeholder="Confirm password" required autocomplete="new-password"><button class="bg-primary px-4 py-2 text-sm font-semibold text-white">Update Password</button></form>
	<form method="POST" action="{{ route('logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">@csrf<button class="border border-red-200 px-4 py-2 font-semibold text-red-700">Logout</button></form>
</div>
@endsection
