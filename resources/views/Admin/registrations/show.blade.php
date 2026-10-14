@extends('layouts.admin')

@section('title', 'Registration Review')
@section('active', 'registrations')

@section('content')
@php
    $user = $application->user;
    $address = $user?->addresses?->firstWhere('is_default', true) ?? $user?->addresses?->first();
    $seller = $application->sellerData;
    $logistics = $application->logisticsData;
    $documentReviewRoutesAvailable = app('router')->has('admin.registrations.documents.verify')
        && app('router')->has('admin.registrations.documents.reject');
@endphp

<div class="ad-page">
    <div class="ad-page-header">
        <div>
            <p class="ad-eyebrow">Registration Review</p>
            <h1>{{ $user?->name ?: 'Applicant' }}</h1>
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
            <div><small>Submitted</small><strong style="display:block;">{{ $application->submitted_at?->format('M d, Y g:i A') ?? 'Not provided' }}</strong></div>
            <div><small>Email</small><strong style="display:block;">{{ $user?->email ?: 'Not provided' }}</strong></div>
            <div><small>Contact</small><strong style="display:block;">{{ $user?->contact_number ?: 'Not provided' }}</strong></div>
            <div><small>Birthday</small><strong style="display:block;">{{ $user?->birthday?->format('M d, Y') ?? 'Not provided' }}</strong></div>
            <div><small>Age</small><strong style="display:block;">{{ $user?->birthday?->age ?? 'Not provided' }}</strong></div>
            <div><small>Sex</small><strong style="display:block;">{{ filled($user?->sex) ? str($user->sex)->replace('_', ' ')->headline() : 'Not provided' }}</strong></div>
            <div><small>Address</small><strong style="display:block;">{{ $address?->formatted() ?: 'Not provided' }}</strong></div>
        </div>
    </div>

    @if($seller)
        <div class="ad-card ad-card-body" style="margin-bottom:16px;">
            <h2>Seller Information</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
                <div><small>Business Name</small><strong style="display:block;">{{ $seller->business_name ?: 'Not provided' }}</strong></div>
                <div><small>Category / Line of Business</small><strong style="display:block;">{{ $seller->category?->name ?? 'Not provided' }}</strong></div>
                <div><small>Seller Type</small><strong style="display:block;">{{ filled($seller->seller_type) ? str($seller->seller_type)->replace('_', ' ')->headline() : 'Not provided' }}</strong></div>
                <div><small>Registration Number</small><strong style="display:block;">{{ $seller->business_registration_number ?: 'Not provided' }}</strong></div>
                <div><small>TIN</small><strong style="display:block;">{{ $seller->tin ?: 'Not provided' }}</strong></div>
            </div>
        </div>
    @endif

    @if($logistics)
        <div class="ad-card ad-card-body" style="margin-bottom:16px;">
            <h2>Logistics Information</h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-top:16px;">
                <div><small>Business Name</small><strong style="display:block;">{{ $logistics->business_name ?: 'Not provided' }}</strong></div>
                <div><small>Registration Number</small><strong style="display:block;">{{ $logistics->business_registration_number ?: 'Not provided' }}</strong></div>
                <div><small>DTI Registration Number</small><strong style="display:block;">{{ $logistics->dti_registration_number ?: 'Not provided' }}</strong></div>
                <div><small>TIN</small><strong style="display:block;">{{ $logistics->tin ?: 'Not provided' }}</strong></div>
                <div><small>Business Address</small><strong style="display:block;">{{ $logistics->businessAddress?->formatted() ?: 'Not provided' }}</strong></div>
            </div>
        </div>
    @endif

    <div class="ad-card ad-card-body" style="margin-bottom:16px;">
        <h2>Submitted Documents</h2>
        <div class="ad-inline-actions" style="margin-top:16px;flex-wrap:wrap;">
            @forelse($application->documents as $document)
                @php
                    $documentUrl = route('admin.registrations.documents.show', [$application, $document]);
                    $previewUrl = $documentUrl.'?preview=1';
                    $mime = strtolower((string) $document->mime_type);
                @endphp
                <article style="width:min(100%,560px);padding:14px;border:1px solid var(--ad-border);border-radius:14px;background:var(--ad-surface,#fff);">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">
                        <div>
                            <strong>{{ str($document->document_type)->replace('_', ' ')->headline() }}</strong>
                            <small style="display:block;margin-top:4px;">{{ $document->original_name ?: 'Submitted document' }}</small>
                        </div>
                        <div class="ad-inline-actions">
                            <span class="ad-status is-{{ \Illuminate\Support\Str::slug($document->verification_status) }}">
                                {{ str($document->verification_status)->replace('_', ' ')->headline() }}
                            </span>
                            <a class="ad-btn ad-btn-secondary" href="{{ $documentUrl }}">Download</a>
                        </div>
                    </div>
                    <div style="width:100%;height:clamp(180px,32vw,360px);overflow:hidden;border:1px solid var(--ad-border);border-radius:10px;background:#f8fafc;display:flex;align-items:center;justify-content:center;">
                        @if(str_starts_with($mime, 'image/'))
                            <img src="{{ $previewUrl }}" alt="{{ str($document->document_type)->replace('_', ' ')->headline() }}" style="display:block;width:100%;height:100%;object-fit:contain;">
                        @elseif($mime === 'application/pdf')
                            <iframe src="{{ $previewUrl }}" title="{{ str($document->document_type)->replace('_', ' ')->headline() }}" style="display:block;width:100%;height:100%;border:0;"></iframe>
                        @else
                            <a class="ad-btn ad-btn-secondary" href="{{ $documentUrl }}">Open document</a>
                        @endif
                    </div>
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-top:12px;">
                        <small>
                            @if($document->verifier)
                                Reviewed by {{ $document->verifier->name }}{{ $document->verified_at ? ' on '.$document->verified_at->format('M j, Y g:i A') : '' }}.
                            @else
                                Awaiting document review.
                            @endif
                            @if($document->rejection_reason)
                                <br>Reason: {{ $document->rejection_reason }}
                            @endif
                        </small>
                    </div>
                    @if($documentReviewRoutesAvailable && in_array($application->status, ['PENDING', 'UNDER_REVIEW'], true))
                        <div style="display:grid;gap:10px;margin-top:12px;">
                            <form method="POST" action="{{ route('admin.registrations.documents.verify', [$application, $document]) }}">
                                @csrf
                                <button class="ad-btn ad-btn-secondary" type="submit">Mark verified</button>
                            </form>
                            <form method="POST" action="{{ route('admin.registrations.documents.reject', [$application, $document]) }}" style="display:grid;gap:8px;" data-confirm-title="Reject this document?" data-confirm-message="Reject the uploaded document using the reason entered above?" data-confirm-label="Reject document" data-confirm-tone="danger">
                                @csrf
                                <textarea name="reason" maxlength="1000" placeholder="Reason for rejecting this document" required></textarea>
                                <button class="ad-btn ad-btn-danger-soft" type="submit">Reject document</button>
                            </form>
                        </div>
                    @endif
                </article>
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
            @if($application->reviewed_at)<p>Reviewed on {{ $application->reviewed_at->format('M j, Y g:i A') }}</p>@endif
            @if($application->decision_notes)<p>Notes: {{ $application->decision_notes }}</p>@endif
            @if($application->rejection_reason)<p>Reason: {{ $application->rejection_reason }}</p>@endif
            @if($application->reviewer)<p>Reviewed by {{ $application->reviewer->name }}.</p>@endif
        </div>
    @endif
</div>
@endsection
