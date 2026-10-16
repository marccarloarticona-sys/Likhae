@extends('layouts.admin')

@section('title', 'Registration Applications')
@section('active', 'registrations')
@section('content')
    @php
        $accountTypeOptions = [
            '' => 'All',
            'BUYER' => 'Buyer',
            'SELLER' => 'Seller',
            'LOGISTICS' => 'Logistics',
            'RIDER' => 'Rider',
        ];
        $statusOptions = [
            'NEEDS_REVIEW' => 'All awaiting review',
            'PENDING' => 'Pending',
            'UNDER_REVIEW' => 'Under Review',
        ];
    @endphp

    <div class="ad-page ad-registrations-page">
        <div class="ad-page-head">
            <div>
                <span class="ad-overline">Account onboarding</span>
                <h2>Registration applications</h2>
                <p>View Buyer, Seller, Logistics, and Rider registrations submitted through the account registration flow. Rider decisions belong to their selected logistics center.</p>
            </div>
            <span class="ad-muted">Updated {{ now()->format('M j, Y · g:i A') }}</span>
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

        <section class="ad-stat-grid ad-registration-stat-grid" aria-label="Registration statistics">
            <x-admin.stat-card
                label="Pending"
                :value="number_format($registrationStats['pending'])"
                detail="Awaiting their assigned reviewer."
                icon="users"
                tone="warning"
            />
            <x-admin.stat-card
                label="Under review"
                :value="number_format($registrationStats['under_review'])"
                detail="Currently being reviewed."
                icon="case"
                tone="blue"
            />
        </section>

        <section class="ad-card" style="margin-bottom:16px;">
            <div class="ad-card-head">
                <div>
                    <span class="ad-overline">Review queue</span>
                    <h2>Filter applications</h2>
                </div>
                <span class="ad-muted">{{ number_format($records->total()) }} result{{ $records->total() === 1 ? '' : 's' }}</span>
            </div>

            <form method="GET" class="ad-card-body ad-filter-bar" aria-label="Filter registration applications">
                <label class="ad-field">
                    <span>Account type</span>
                    <select name="type" onchange="this.form.submit()">
                        @foreach($accountTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($type === ($value ?: null))>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ad-field">
                    <span>Status</span>
                    <select name="status" onchange="this.form.submit()">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </form>
        </section>

        <div class="ad-card">
            <div class="ad-card-head">
                <div>
                    <span class="ad-overline">Applicant list</span>
                    <h2>Applications to review</h2>
                </div>
                <span class="ad-muted">Newest first</span>
            </div>

            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Account Type</th>
                            <th>Contact</th>
                            <th>Date Submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $application)
                            @php
                                $user = $application->user;
                                $address = $user?->addresses?->firstWhere('is_default', true) ?? $user?->addresses?->first();
                                $businessName = $application->sellerData?->business_name ?? $application->logisticsData?->business_name;
                                $rider = $application->riderData;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $user?->name ?: 'Unknown applicant' }}</strong>
                                    <small>{{ $application->application_number }}</small>
                                    @if($businessName)
                                        <small>{{ $businessName }}</small>
                                    @elseif($rider)
                                        <small>{{ str($rider->vehicle_type)->headline() }} · {{ $rider->plate_number }}</small>
                                    @endif
                                </td>
                                <td>{{ str($user?->account_type ?? 'UNKNOWN')->headline() }}</td>
                                <td>
                                    {{ $user?->email ?: 'Not provided' }}
                                    <small>{{ $user?->contact_number ?: 'No phone provided' }}</small>
                                </td>
                                <td>
                                    {{ $application->submitted_at?->format('M j, Y g:i A') ?? 'Not provided' }}
                                    <small>{{ $address?->formatted() ?: 'No address provided' }}</small>
                                </td>
                                <td>
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($application->status) }}">
                                        {{ str($application->status)->replace('_', ' ')->headline() }}
                                    </span>
                                </td>
                                <td>
                                    <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.registrations.show', $application) }}">{{ $user?->account_type === 'RIDER' ? 'View' : 'Review' }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div style="padding:32px 20px;text-align:center;">
                                        <strong style="display:block;font-size:16px;margin-bottom:6px;">
                                            {{ $status === 'NEEDS_REVIEW' ? 'No applications awaiting review.' : 'No applications found for the selected filters.' }}
                                        </strong>
                                        <span class="ad-muted">Try another account type or status filter.</span>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="ad-card-body">
                {{ $records->links() }}
            </div>
        </div>
    </div>
@endsection
