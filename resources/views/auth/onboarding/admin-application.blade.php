@extends('layouts.admin')

@section('title', 'Registration Review')

@section('content')
@php
    $user = $application->user;
    $address = $user?->addresses?->firstWhere('is_default', true) ?? $user?->addresses?->first();
    $seller = $application->sellerData;
    $logistics = $application->logisticsData;
    $rider = $application->riderData;
@endphp

<div class="ad-page">
    <div class="ad-page-header">
        <div>
            <p class="ad-eyebrow">Registration Review</p>
            <h1>{{ $user?->name ?? 'Applicant' }}</h1>
            <p>{{ $application->application_number }} · {{ str($user?->account_type ?? 'UNKNOWN')->headline() }} · {{ str($application->status)->replace('_', ' ')->headline() }}</p>
        </div>
        <div class="ad-inline-actions">
            <a class="ad-btn ad-btn-secondary" href="{{ route('admin.registrations') }}">Back to Applications</a>
        </div>
    </div>

    @if(session('success'))
        <div class="ad-card ad-card-body" style="margin-bottom:16px;color:#166534;background:#f0fdf4;border-color:#bbf7d0;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="ad-card ad-card-body" style="margin-bottom:16px;color:#991b1b;background:#fef2f2;border-color:#fecaca;">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="ad-card ad-card-body" style="margin-bottom:16px;">
        <h2>Applicant Information</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
            <div><small>Account Type</small><strong style="display:block;">{{ str($user?->account_type ?? 'UNKNOWN')->headline() }}</strong></div>
            <div><small>Email</small><strong style="display:block;">{{ $user?->email }}</strong></div>
            <div><small>Contact</small><strong style="display:block;">{{ $user?->contact_number }}</strong></div>
            <div><small>Birthday</small><strong style="display:block;">{{ $user?->birthday?->format('M d, Y') }}</strong></div>
            <div><small>Age</small><strong style="display:block;">{{ $user?->birthday?->age ?? 'Not recorded' }}</strong></div>
            <div><small>Sex</small><strong style="display:block;">{{ str($user?->sex ?? '')->replace('_', ' ')->headline() }}</strong></div>
            <div><small>Address</small><strong style="display:block;">{{ $address?->formatted() ?? 'Not recorded' }}</strong></div>
        </div>
    </div>

    @if($seller)
        <div class="ad-card ad-card-body" style="margin-bottom:16px;">
            <h2>Seller Information</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
                <div><small>Business Name</small><strong style="display:block;">{{ $seller->business_name }}</strong></div>
                <div><small>Category</small><strong style="display:block;">{{ $seller->category?->name ?? 'Not configured' }}</strong></div>
                <div><small>Registration Number</small><strong style="display:block;">{{ $seller->business_registration_number ?: 'Not provided' }}</strong></div>
            </div>
        </div>
    @endif

    @if($rider)
        <div class="ad-card ad-card-body" style="margin-bottom:16px;">
            <h2>Rider / Courier Information</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
                <div><small>Vehicle</small><strong style="display:block;">{{ str($rider->vehicle_type)->headline() }}</strong></div>
                <div><small>Plate Number</small><strong style="display:block;">{{ $rider->plate_number }}</strong></div>
                <div><small>Driver's License Number</small><strong style="display:block;">{{ $rider->drivers_license_number }}</strong></div>
                <div><small>Sorting Center</small><strong style="display:block;">{{ $rider->targetLogisticsCenter?->business_name ?? 'Not configured' }}</strong></div>
            </div>
        </div>
    @endif

    @if($logistics)
        <div class="ad-card ad-card-body" style="margin-bottom:16px;">
            <h2>Logistics Information</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
                <div><small>Business Name</small><strong style="display:block;">{{ $logistics->business_name }}</strong></div>
                <div><small>Registration Number</small><strong style="display:block;">{{ $logistics->business_registration_number ?: 'Not provided' }}</strong></div>
                <div><small>DTI Registration Number</small><strong style="display:block;">{{ $logistics->dti_registration_number ?: 'Not provided' }}</strong></div>
            </div>
        </div>
    @endif

    <div class="ad-card ad-card-body" style="margin-bottom:16px;">
        <h2>Submitted Documents</h2>
        <div class="ad-inline-actions" style="margin-top:16px;flex-wrap:wrap;">
            @forelse($application->documents as $document)
                <a class="ad-btn ad-btn-secondary" href="{{ route('admin.registrations.documents.show', [$application, $document]) }}">
                    {{ str($document->document_type)->replace('_', ' ')->headline() }}
                </a>
            @empty
                <p>No documents were submitted.</p>
            @endforelse
        </div>
    </div>

    @if(in_array($application->status, ['PENDING', 'UNDER_REVIEW'], true))
        <div class="ad-card ad-card-body">
            <h2>Decision</h2>
            <div style="display:grid;gap:16px;margin-top:16px;">
                <form method="POST" action="{{ route('admin.registrations.approve', $application) }}" style="display:grid;gap:10px;" data-confirm-title="Approve registration?" data-confirm-message="Approving will activate this user's LIKHAE account." data-confirm-label="Approve">
                    @csrf
                    <textarea name="decision_notes" maxlength="1000" placeholder="Optional approval notes"></textarea>
                    <button class="ad-btn ad-btn-primary" type="submit">Approve Registration</button>
                </form>

                <form method="POST" action="{{ route('admin.registrations.reject', $application) }}" style="display:grid;gap:10px;" data-confirm-title="Reject registration?" data-confirm-message="This application will be rejected using the reason entered above." data-confirm-label="Reject" data-confirm-tone="danger">
                    @csrf
                    <textarea name="reason" maxlength="1000" placeholder="Required rejection reason" required></textarea>
                    <button class="ad-btn ad-btn-danger-soft" type="submit">Reject Registration</button>
                </form>
            </div>
        </div>
    @else
        <div class="ad-card ad-card-body">
            <h2>Decision</h2>
            <p>Status: <strong>{{ str($application->status)->replace('_', ' ')->headline() }}</strong></p>
            @if($application->decision_notes)<p>Notes: {{ $application->decision_notes }}</p>@endif
            @if($application->rejection_reason)<p>Reason: {{ $application->rejection_reason }}</p>@endif
            @if($application->reviewer)<p>Reviewed by {{ $application->reviewer->name }}.</p>@endif
        </div>
    @endif
</div>
@endsection
