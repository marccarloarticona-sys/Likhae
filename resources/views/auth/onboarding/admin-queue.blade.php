@extends('layouts.admin.app')

@section('title', 'Registration Applications')

@section('content')
<div class="ad-page">
    <div class="ad-page-header">
        <div>
            <p class="ad-eyebrow">Account Onboarding</p>
            <h1>Registration Applications</h1>
            <p>Review Buyer, Seller, Logistics, and Rider registrations submitted through the account registration flow.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="ad-card ad-card-body" style="margin-bottom:16px;color:#166534;background:#f0fdf4;border-color:#bbf7d0;">
            {{ session('success') }}
        </div>
    @endif

    <form method="GET" class="ad-card ad-card-body ad-filter-bar" style="margin-bottom:16px;">
        <select name="type" onchange="this.form.submit()">
            <option value="">All account types</option>
            @foreach(['BUYER' => 'Buyer', 'SELLER' => 'Seller', 'LOGISTICS' => 'Logistics', 'RIDER' => 'Rider'] as $value => $label)
                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="status" onchange="this.form.submit()">
            @foreach(['PENDING', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'CANCELLED'] as $value)
                <option value="{{ $value }}" @selected($status === $value)>{{ str($value)->replace('_', ' ')->headline() }}</option>
            @endforeach
        </select>
    </form>

    <div class="ad-card ad-card-body">
        @forelse($records as $application)
            @php
                $user = $application->user;
                $address = $user?->addresses?->firstWhere('is_default', true) ?? $user?->addresses?->first();
                $businessName = $application->sellerData?->business_name ?? $application->logisticsData?->business_name;
                $riderSummary = $application->riderData
                    ? str($application->riderData->vehicle_type)->headline().' · '.$application->riderData->plate_number
                    : null;
            @endphp

            <article style="padding:18px 0;border-bottom:1px solid #e7e5e4;display:flex;gap:16px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">
                <div style="min-width:260px;flex:1;">
                    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <strong>{{ $user?->name ?? 'Unknown applicant' }}</strong>
                        <span class="ad-badge">{{ str($user?->account_type ?? 'UNKNOWN')->headline() }}</span>
                        <span class="ad-badge">{{ str($application->status)->replace('_', ' ')->headline() }}</span>
                    </div>
                    <div style="margin-top:6px;color:#78716c;">{{ $user?->email }} · {{ $user?->contact_number }}</div>
                    @if($businessName)
                        <div style="margin-top:6px;">{{ $businessName }}</div>
                    @endif
                    @if($riderSummary)
                        <div style="margin-top:6px;">{{ $riderSummary }}</div>
                    @endif
                    @if($address)
                        <div style="margin-top:6px;color:#78716c;">{{ $address->formatted() }}</div>
                    @endif
                    <div style="margin-top:6px;color:#a8a29e;font-size:12px;">
                        {{ $application->application_number }} · Submitted {{ $application->submitted_at?->diffForHumans() ?? 'recently' }}
                    </div>
                </div>

                <div class="ad-inline-actions">
                    <a class="ad-btn ad-btn-secondary" href="{{ route('admin.registrations.show', $application) }}">Review</a>
                </div>
            </article>
        @empty
            <p>No matching registration applications.</p>
        @endforelse

        <div style="margin-top:16px;">
            {{ $records->links() }}
        </div>
    </div>
</div>
@endsection
