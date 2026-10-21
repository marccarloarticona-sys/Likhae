@extends('layouts.admin.app')

@section('title', 'Registration Applications')
@section('active', 'registrations')
@section('content')
    @php
        $accountTypeOptions = [
            '' => 'All account types',
            'BUYER' => 'Buyer',
            'SELLER' => 'Seller',
            'LOGISTICS' => 'Logistics',
            'RIDER' => 'Rider',
        ];
        $statusOptions = [
            'NEEDS_REVIEW' => 'Awaiting review',
            'PENDING' => 'Pending',
            'UNDER_REVIEW' => 'Under review',
        ];
    @endphp

    <div class="ad-page ad-registrations-page">
        <header class="ad-page-head ad-registration-page-head">
            <div class="ad-registration-heading">
                <span class="ad-overline">Account onboarding</span>
                <h2>Registration applications</h2>
                <p>Review Buyer, Seller, Logistics, and Rider sign-ups. Rider decisions are handled by the applicant’s selected logistics center.</p>
            </div>
            <time class="ad-registration-updated" datetime="{{ now()->toIso8601String() }}">
                <span class="ad-registration-live-dot" aria-hidden="true"></span>
                Updated {{ now()->format('M j, Y · g:i A') }}
            </time>
        </header>

        @if(session('success'))
            <div class="ad-registration-feedback is-success" role="status">{{ session('success') }}</div>
        @endif

        @if($errors->any())
            <div class="ad-registration-feedback is-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <section class="ad-stat-grid ad-registration-stat-grid" aria-label="Registration statistics">
            <x-admin.stat-card
                label="Pending"
                :value="number_format($registrationStats['pending'])"
                detail="Awaiting their assigned reviewer"
                icon="users"
                tone="warning"
            />
            <x-admin.stat-card
                label="Under review"
                :value="number_format($registrationStats['under_review'])"
                detail="Currently being reviewed"
                icon="case"
                tone="blue"
            />
        </section>

        <section class="ad-card ad-registration-queue" aria-labelledby="registration-queue-title">
            <div class="ad-card-head ad-registration-queue-head">
                <div>
                    <span class="ad-overline">Review queue</span>
                    <h2 id="registration-queue-title">Applications</h2>
                </div>
                <span class="ad-registration-result-count">
                    <strong>{{ number_format($records->total()) }}</strong>
                    {{ $records->total() === 1 ? 'result' : 'results' }}
                </span>
            </div>

            <form method="GET" action="{{ route('admin.registrations') }}" class="ad-registration-filters" aria-label="Filter registration applications">
                <label class="ad-field">
                    <span>Account type</span>
                    <select name="type">
                        @foreach($accountTypeOptions as $value => $label)
                            <option value="{{ $value }}" @selected($type === ($value ?: null))>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ad-field">
                    <span>Application status</span>
                    <select name="status">
                        @foreach($statusOptions as $value => $label)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="ad-registration-filter-actions">
                    <button class="ad-btn ad-btn-primary" type="submit">Apply filters</button>
                    @if($type || $status !== 'NEEDS_REVIEW')
                        <a class="ad-registration-reset" href="{{ route('admin.registrations') }}">Reset</a>
                    @endif
                </div>
            </form>

            <div class="ad-registration-list-heading">
                <h3>Applicant list</h3>
                <span>Newest first</span>
            </div>

            <div class="ad-table-wrap ad-registration-table-wrap">
                <table class="ad-table ad-registration-table">
                    <thead>
                        <tr>
                            <th scope="col">Applicant</th>
                            <th scope="col">Account type</th>
                            <th scope="col">Contact</th>
                            <th scope="col">Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($records as $application)
                            @php
                                $user = $application->user;
                                $address = $user?->addresses?->firstWhere('is_default', true) ?? $user?->addresses?->first();
                                $businessName = $application->sellerData?->business_name ?? $application->logisticsData?->business_name;
                                $rider = $application->riderData;
                                $applicantName = $user?->name ?: 'Unknown applicant';
                                $initials = collect(explode(' ', trim($applicantName)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn ($part) => mb_substr($part, 0, 1))
                                    ->implode('');
                                $accountType = $user?->account_type ?? 'UNKNOWN';
                            @endphp
                            <tr>
                                <td data-label="Applicant">
                                    <div class="ad-registration-person">
                                        <span class="ad-registration-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span>
                                        <span class="ad-registration-person-copy">
                                            <strong>{{ $applicantName }}</strong>
                                            <small class="ad-registration-id">{{ $application->application_number }}</small>
                                            @if($businessName)
                                                <small>{{ $businessName }}</small>
                                            @elseif($rider)
                                                <small>{{ str($rider->vehicle_type)->headline() }} · {{ $rider->plate_number }}</small>
                                            @endif
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Account type">
                                    <span class="ad-registration-type is-{{ \Illuminate\Support\Str::slug($accountType) }}">{{ str($accountType)->headline() }}</span>
                                </td>
                                <td data-label="Contact" class="ad-registration-contact">
                                    <span>{{ $user?->email ?: 'Not provided' }}</span>
                                    <small>{{ $user?->contact_number ?: 'No phone provided' }}</small>
                                </td>
                                <td data-label="Submitted" class="ad-registration-submitted">
                                    <time datetime="{{ $application->submitted_at?->toIso8601String() }}">{{ $application->submitted_at?->format('M j, Y · g:i A') ?? 'Not provided' }}</time>
                                    <small>{{ $address?->formatted() ?: 'No address provided' }}</small>
                                </td>
                                <td data-label="Status">
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($application->status) }}">
                                        {{ str($application->status)->replace('_', ' ')->headline() }}
                                    </span>
                                </td>
                                <td data-label="Action" class="ad-registration-action-cell">
                                    <a class="ad-btn ad-btn-secondary ad-btn-sm ad-registration-action" href="{{ route('admin.registrations.show', $application) }}">
                                        {{ $user?->account_type === 'RIDER' ? 'View' : 'Review' }}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="ad-registration-empty-cell">
                                    <div class="ad-registration-empty">
                                        <span class="ad-registration-empty-mark" aria-hidden="true">✓</span>
                                        <strong>{{ $status === 'NEEDS_REVIEW' ? 'You’re all caught up' : 'No applications found' }}</strong>
                                        <span>{{ $status === 'NEEDS_REVIEW' ? 'There are no applications awaiting review right now.' : 'Try changing the account type or status filters.' }}</span>
                                        @if($type || $status !== 'NEEDS_REVIEW')
                                            <a href="{{ route('admin.registrations') }}">Clear filters</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($records->hasPages())
                <div class="ad-card-body ad-registration-pagination">
                    <span>Showing {{ number_format($records->firstItem()) }}–{{ number_format($records->lastItem()) }} of {{ number_format($records->total()) }}</span>
                    {{ $records->links() }}
                </div>
            @endif
        </section>
    </div>
@endsection
