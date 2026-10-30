@extends('layouts.admin.app')

@section('title', 'Account Details')
@section('subtitle', 'Review registration details and manage account access.')
@section('active', 'users')
@section('content')
@php
    $managedAccount = $user->isAccountType(\App\Models\User::TYPE_BUYER, \App\Models\User::TYPE_SELLER, \App\Models\User::TYPE_LOGISTICS);
    $address = $user->addresses->firstWhere('is_default', true) ?? $user->addresses->first();
    $shop = $user->sellerProfile;
    $provider = $user->logisticsCenter;
    $status = $user->isPermanentlyBanned() ? 'banned' : strtolower($user->status);
    $warningCount = $warningCount ?? 0;
@endphp
<div class="ad-page ad-user-details-page">
    <div class="ad-page-head ad-user-page-head">
        <div class="ad-user-identity">
            <div class="ad-user-identity-kicker"><span class="ad-overline">USR-{{ $user->id }}</span><span class="ad-user-role">{{ ucfirst(strtolower($user->account_type)) }} account</span></div>
            <h2>{{ $user->name }}</h2>
            <p>{{ $user->email }}</p>
            <div class="ad-user-summary-line"><span class="ad-status is-{{ $status }}">{{ $status === 'pending' ? 'Pending approval' : ($status === 'banned' ? 'Permanently banned' : ucfirst($status)) }}</span><span>Registered {{ $user->created_at->format('M d, Y') }}</span></div>
        </div>
        <a class="ad-btn ad-btn-secondary" href="{{ route('admin.users') }}">Back to users</a>
    </div>
    @if(session('success'))<div class="ad-note" role="status">{{ session('success') }}</div>@endif
    @if(session('status'))<div class="ad-note" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="ad-note" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <section class="ad-card">
        <header class="ad-card-head"><div><h2>Account information</h2><p>Contact details and registered address</p></div></header>
        <div class="ad-card-body">
            <dl class="ad-application-meta ad-user-meta">
                <div><dt>Role</dt><dd>{{ ucfirst(strtolower($user->account_type)) }}</dd></div>
                <div><dt>Registered</dt><dd>{{ $user->created_at->format('M d, Y H:i') }}</dd></div>
                <div><dt>Contact number</dt><dd>{{ $user->contact_number ?: 'Not provided' }}</dd></div>
                <div><dt>Birthday</dt><dd>{{ $user->birthday?->format('M d, Y') ?? 'Not provided' }}</dd></div>
                <div><dt>Sex</dt><dd>{{ $user->sex ? ucfirst(str_replace('_', ' ', $user->sex)) : 'Not provided' }}</dd></div>
                <div><dt>Street address</dt><dd>{{ trim(($address?->house_number ?? '').' '.($address?->street_address ?? '')) ?: 'Not provided' }}</dd></div>
                <div><dt>Barangay</dt><dd>{{ $address?->barangay_name ?: 'Not provided' }}</dd></div>
                <div><dt>Municipality / City</dt><dd>{{ $address?->municipality_name ?: 'Not provided' }}</dd></div>
                <div><dt>Province</dt><dd>{{ $address?->province_name ?: 'Not provided' }}</dd></div>
                <div><dt>Postal code</dt><dd>{{ $address?->postal_code ?: 'Not provided' }}</dd></div>
                <div><dt>Landmark</dt><dd>{{ $address?->landmark ?: 'Not provided' }}</dd></div>
            </dl>
            @if($user->isAccountType(\App\Models\User::TYPE_SELLER, \App\Models\User::TYPE_LOGISTICS))
                <h3>Business details</h3>
                <dl class="ad-application-meta ad-user-meta">
                    <div><dt>Business name</dt><dd>{{ $shop?->business_name ?? $provider?->business_name ?? 'Not provided' }}</dd></div>
                    <div><dt>Registration number</dt><dd>{{ $shop?->business_registration_number ?? $provider?->business_registration_number ?? 'Not provided' }}</dd></div>
                    @if($provider)<div><dt>DTI number</dt><dd>{{ $provider->dti_registration_number ?: 'Not provided' }}</dd></div>@endif
                </dl>
            @endif
        </div>
    </section>

    @if($user->isAccountType(\App\Models\User::TYPE_SELLER))
        @php
            $sellerDocumentTypes = array_values(\App\Models\Auth\ApplicationDocument::SELLER_UPLOAD_FIELDS);
        @endphp
        <section class="ad-card ad-user-verification-card" id="seller-verification">
            <header class="ad-card-head"><div><h2>Seller verification documents</h2><p>Current files and document update requests from this seller.</p></div>@if($sellerApplication)<span class="ad-status is-{{ \Illuminate\Support\Str::slug($sellerApplication->status) }}">Verification record {{ str($sellerApplication->status)->replace('_', ' ')->headline() }}</span>@endif</header>
            <div class="ad-table-wrap ad-user-history-table">
                <table class="ad-table ad-user-verification-table">
                    <thead><tr><th>Document</th><th>Latest upload</th><th>Status</th><th>Admin review</th></tr></thead>
                    <tbody>
                        @foreach($sellerDocumentTypes as $documentType)
                            @php
                                $document = $sellerDocuments->get($documentType);
                                $documentStatus = strtoupper((string) $document?->verification_status);
                                $adminRequestedUpdate = $document && isset($sellerUpdateRequestDocumentIds[$document->id]);
                                $documentLabel = \App\Models\Auth\ApplicationDocument::DOCUMENT_LABELS[$documentType];
                            @endphp
                            <tr>
                                <td><strong>{{ $documentLabel }}</strong></td>
                                <td>
                                    @if($document)
                                        <span>{{ $document->original_name ?: 'Submitted document' }}</span>
                                        <small>Uploaded {{ $document->created_at?->format('M d, Y H:i') ?? 'date unavailable' }}</small>
                                        <div class="ad-user-document-links"><a href="{{ route('admin.registrations.documents.show', [$sellerApplication, $document, 'preview' => 1]) }}" target="_blank" rel="noopener">Preview</a><a href="{{ route('admin.registrations.documents.show', [$sellerApplication, $document]) }}">Download</a></div>
                                        @if($document->rejection_reason)<small class="ad-user-document-reason">Reason: {{ $document->rejection_reason }}</small>@endif
                                    @else
                                        <span>Not submitted</span>
                                    @endif
                                </td>
                                <td><span class="ad-status {{ $document ? 'is-'.\Illuminate\Support\Str::slug($documentStatus) : 'is-review' }}">{{ $adminRequestedUpdate && $documentStatus === 'REJECTED' ? 'Update requested' : ($document ? str($documentStatus)->replace('_', ' ')->headline() : 'Missing') }}</span></td>
                                <td>
                                    @if($document && $documentStatus === 'PENDING')
                                        <div class="ad-user-verification-actions">
                                            <form method="POST" action="{{ route('admin.users.seller-documents.review', ['user' => $user, 'document' => $document]) }}">
                                                @csrf<input type="hidden" name="decision" value="verify"><button class="ad-btn ad-btn-secondary ad-btn-sm" type="submit">Verify update</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.users.seller-documents.review', ['user' => $user, 'document' => $document]) }}" data-confirm-title="Reject this document update?" data-confirm-message="The seller will be asked to upload another replacement." data-confirm-label="Reject update" data-confirm-tone="danger">
                                                @csrf<input type="hidden" name="decision" value="reject"><input type="text" name="reason" required minlength="3" maxlength="1000" placeholder="Reason for rejection"><button class="ad-btn ad-btn-danger-soft ad-btn-sm" type="submit">Reject update</button>
                                            </form>
                                        </div>
                                    @elseif($documentStatus === 'VERIFIED')
                                        <div class="ad-user-verification-actions">
                                            <small>Verified{{ $document->verified_at ? ' on '.$document->verified_at->format('M d, Y') : '' }}.</small>
                                            <form method="POST" action="{{ route('admin.users.seller-documents.review', ['user' => $user, 'document' => $document]) }}" data-confirm-title="Request a document update?" data-confirm-message="The seller will be notified and can upload a replacement for Admin review." data-confirm-label="Request update" data-confirm-tone="danger">
                                                @csrf<input type="hidden" name="decision" value="request_update"><input type="text" name="reason" required minlength="3" maxlength="1000" placeholder="What needs to be updated?"><button class="ad-btn ad-btn-danger-soft ad-btn-sm" type="submit">Request update</button>
                                            </form>
                                        </div>
                                    @elseif($documentStatus === 'REJECTED')
                                        <small>{{ $adminRequestedUpdate ? 'Admin requested an update; waiting for the seller to upload a replacement.' : 'Waiting for the seller to upload a replacement.' }}</small>
                                    @else
                                        <small>No review is needed yet.</small>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="ad-card ad-user-access-card" id="account-access">
        <header class="ad-card-head"><div><h2>Account access</h2><p>Apply a temporary suspension or permanently remove platform access</p></div></header>
        <div class="ad-card-body">
            @unless($accountEnforcementReady)
                <div class="ad-note is-warning" role="status"><span>Account enforcement controls are unavailable until the account enforcement migration is applied to this database.</span></div>
            @elseif($managedAccount && in_array($status, ['active', 'suspended'], true))
                @php
                    $suspended = $user->isSuspended();
                @endphp
                <p>
                    @if($suspended)
                        This account is suspended{{ $user->suspended_until ? ' until '.$user->suspended_until->format('M d, Y h:i A') : ' with no expiry recorded' }}. You can reactivate it early.
                    @else
                        Choose a suspension period. Access will return automatically when it ends.
                    @endif
                </p>
                <form class="ad-user-control-form" method="POST" action="{{ route($suspended ? 'admin.users.reactivate' : 'admin.users.suspend', $user) }}" data-confirm-title="{{ $suspended ? 'Reactivate account?' : 'Suspend account?' }}" data-confirm-message="{{ $suspended ? 'This user will be able to access the account again.' : 'This user will lose access to their account.' }}" data-confirm-label="{{ $suspended ? 'Reactivate' : 'Suspend' }}" data-confirm-details="{{ $user->name }} | {{ ucfirst(strtolower($user->account_type)) }}" @if(!$suspended) data-confirm-tone="danger" @endif>
                    @csrf
                    <label class="ad-field">
                        <span>{{ $suspended ? 'Reason for reactivation' : 'Reason for suspension' }}</span>
                        <textarea name="reason" rows="3" required maxlength="2000" placeholder="Explain why this account's access is changing.">{{ old('reason') }}</textarea>
                    </label>
                    @if(! $suspended)
                        <label class="ad-field">
                            <span>Suspension period</span>
                            <select name="duration" required>
                                @foreach($suspensionDurations as $durationValue => $durationLabel)
                                    <option value="{{ $durationValue }}" @selected(old('duration', 'week') === $durationValue)>{{ $durationLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="ad-user-reference-note">A case reference will be generated automatically when the suspension is recorded.</p>
                        <label class="ad-field">
                            <span>Evidence or investigation notes (optional)</span>
                            <textarea name="evidence" rows="3" maxlength="5000" placeholder="Record the supporting context for this reviewed decision.">{{ old('evidence') }}</textarea>
                        </label>
                    @endif
                    <button class="ad-btn {{ $suspended ? 'ad-btn-primary' : 'ad-btn-danger-soft' }}" type="submit">{{ $suspended ? 'Reactivate account' : 'Suspend account' }}</button>
                </form>
            @elseif($user->isPermanentlyBanned())
                <p>This account is permanently banned. Sign-in and workspace access are blocked.</p>
            @elseif($user->isAccountType(\App\Models\User::TYPE_ADMIN))
                <p>Administrator access cannot be changed from the user directory.</p>
            @else
                <p>This account is {{ $status }}. Temporary suspension controls are available for active accounts.</p>
            @endif

            @if($accountEnforcementReady && $managedAccount && ! $user->isPermanentlyBanned())
                <div class="ad-user-danger-panel">
                    <h3>Permanent ban</h3>
                    <p>Blocks this account from signing in or using LIKHAE. A permanent ban does not expire.</p>
                    <form method="POST" action="{{ route('admin.users.ban', $user) }}" data-confirm-title="Permanently ban this account?" data-confirm-message="This account will lose LIKHAE access permanently. This cannot be undone from this page." data-confirm-label="Permanently ban" data-confirm-tone="danger" data-confirm-details="{{ $user->name }} | {{ ucfirst(strtolower($user->account_type)) }}">
                        @csrf
                        <label class="ad-field">
                            <span>Reason for permanent ban</span>
                            <textarea name="reason" rows="3" required minlength="10" maxlength="2000" placeholder="Document the reason for permanently removing this account from the platform.">{{ old('reason') }}</textarea>
                        </label>
                        <p class="ad-user-reference-note">A case reference will be generated automatically when the ban is recorded.</p>
                        <label class="ad-field">
                            <span>Evidence or investigation notes (optional)</span>
                            <textarea name="evidence" rows="3" maxlength="5000" placeholder="Record supporting evidence for this permanent decision.">{{ old('evidence') }}</textarea>
                        </label>
                        <button class="ad-btn ad-btn-danger-soft" type="submit">Permanently ban account</button>
                    </form>
                </div>
            @endif
        </div>
    </section>

    @if($user->isAccountType(\App\Models\User::TYPE_BUYER))
    <section class="ad-card" id="buyer-risk-signals">
        <header class="ad-card-head"><div><h2>Automated buyer safety signals</h2><p>Patterns flagged for human review; a signal alone does not establish misconduct.</p></div><a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.users', ['role' => 'buyers', 'risk' => 'flagged']) }}">Review queue</a></header>
        <div class="ad-table-wrap ad-user-history-table">
            <table class="ad-table">
                <thead><tr><th>Detected</th><th>Signal</th><th>Observed pattern</th><th>Status and review</th></tr></thead>
                <tbody>
                    @forelse($riskSignals as $signal)
                        @php
                            $signalValues = $signal->new_values ?? [];
                            $signalStatus = strtoupper((string) data_get($signalValues, 'status', 'OPEN'));
                            $signalType = data_get($signalValues, 'signal_type');
                        @endphp
                        <tr>
                            <td>{{ $signal->created_at?->format('M d, Y H:i') ?? 'Not provided' }}<small>{{ data_get($signalValues, 'reference') }}</small></td>
                            <td>{{ $signalType === 'REPEATED_BUYER_CANCELLATIONS' ? 'Repeated buyer cancellations' : ($signalType === 'RAPID_ORDERING' ? 'Rapid order placement' : 'Buyer activity pattern') }}</td>
                            <td>
                                @if($signalType === 'REPEATED_BUYER_CANCELLATIONS')
                                    {{ data_get($signalValues, 'buyer_cancelled_orders', 0) }} of {{ data_get($signalValues, 'orders_in_window', 0) }} orders were cancelled by the buyer in {{ data_get($signalValues, 'window_days', 30) }} days ({{ data_get($signalValues, 'cancellation_rate_percent', 0) }}%).
                                @elseif($signalType === 'RAPID_ORDERING')
                                    {{ data_get($signalValues, 'orders_in_window', 0) }} orders in {{ data_get($signalValues, 'window_minutes', 10) }} minutes.
                                @else
                                    Review the stored signal details before taking action.
                                @endif
                                <small>{{ data_get($signalValues, 'review_guidance') }}</small>
                                @if(data_get($signalValues, 'resolution_note'))<small>Review note: {{ data_get($signalValues, 'resolution_note') }}</small>@endif
                            </td>
                            <td>
                                <span class="ad-status {{ in_array($signalStatus, ['OPEN', 'UNDER_REVIEW'], true) ? 'is-warning' : 'is-active' }}">{{ str($signalStatus)->replace('_', ' ')->headline() }}</span>
                                @if(! in_array($signalStatus, ['RESOLVED', 'CLOSED'], true))
                                    <form method="POST" action="{{ route('admin.users.risk-signals.review', ['user' => $user, 'signal' => $signal]) }}" style="display:grid;gap:6px;margin-top:8px;">
                                        @csrf
                                        <select name="status" aria-label="Review status for safety signal {{ $signal->id }}">
                                            @foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED'] as $reviewStatus)
                                                <option value="{{ $reviewStatus }}" @selected($signalStatus === $reviewStatus)>{{ str($reviewStatus)->replace('_', ' ')->headline() }}</option>
                                            @endforeach
                                        </select>
                                        <input name="note" maxlength="5000" placeholder="Review note (optional)">
                                        <button class="ad-btn ad-btn-secondary ad-btn-sm" type="submit">Save review</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No automated safety signals recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($riskSignals->hasPages())<div class="ad-card-body">{{ $riskSignals->links() }}</div>@endif
    </section>
    @endif

    <section class="ad-card" id="account-warnings">
        <header class="ad-card-head"><div><h2>Account warnings</h2><p>The three-warning limit includes resolved and closed warnings.</p></div><span class="ad-user-warning-count">{{ $warningCount }} / {{ \App\Models\User::MAX_ACCOUNT_WARNINGS }} used</span></header>
        <div class="ad-card-body">
            @if($managedAccount)
                @if($warningCount < \App\Models\User::MAX_ACCOUNT_WARNINGS)
                <form class="ad-user-warning-form" method="POST" action="{{ route('admin.users.warnings.store', $user) }}" data-confirm-title="Issue account warning?" data-confirm-message="Send this documented warning and corrective action to {{ $user->name }}?" data-confirm-label="Issue warning">
                    @csrf
                    <div class="ad-form-grid">
                        <label class="ad-field">
                            <span>Account holder</span>
                            <input value="{{ $user->name }}" readonly>
                        </label>
                        <label class="ad-field">
                            <span>Account type</span>
                            <input value="{{ ucfirst(strtolower($user->account_type)) }}" readonly>
                        </label>
                        <label class="ad-field is-full">
                            <span>Warning category</span>
                            <select name="category" required>
                                <option value="">Select a review category</option>
                                @foreach($warningCategories as $warningKey => $warningLabel)
                                    <option value="{{ $warningKey }}" @selected(old('category') === $warningKey)>{{ $warningLabel }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="ad-field is-full">
                            <span>Detailed reason</span>
                            <textarea name="reason" rows="3" required minlength="10" maxlength="5000" placeholder="Explain the substantiated concern; a category alone is not a finding.">{{ old('reason') }}</textarea>
                        </label>
                        <label class="ad-field is-full">
                            <span>Corrective action requested</span>
                            <textarea name="corrective_action" rows="3" required minlength="5" maxlength="5000" placeholder="Explain what the account holder must correct or review.">{{ old('corrective_action') }}</textarea>
                        </label>
                        <p class="ad-user-reference-note is-full">A case reference will be generated automatically when this warning is issued.</p>
                        <label class="ad-field">
                            <span>Evidence or investigation notes (optional)</span>
                            <textarea name="evidence" rows="3" maxlength="5000" placeholder="Keep notes relevant to this warning.{{ $user->account_type === \App\Models\User::TYPE_BUYER ? ' Do not treat failed checkout alone as proof of misconduct.' : '' }}">{{ old('evidence') }}</textarea>
                        </label>
                    </div>
                    <button class="ad-btn ad-btn-warning-soft" type="submit">Issue Warning ({{ \App\Models\User::MAX_ACCOUNT_WARNINGS - $warningCount }} remaining)</button>
                </form>
                @else
                    <p role="status">This account has reached the three-warning limit. No further warnings can be issued.</p>
                @endif
            @elseif($user->isAccountType(\App\Models\User::TYPE_ADMIN))
                <p>Administrator accounts cannot receive ordinary User Management warnings or suspension actions.</p>
            @else
                <p>Rider accounts are managed by their Logistics provider and are not available here.</p>
            @endif
        </div>
        <div class="ad-table-wrap ad-user-history-table">
            <table class="ad-table">
                <thead><tr><th>Date issued</th><th>Category</th><th>Reason / corrective action</th><th>Admin</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($warnings as $warning)
                        @php
                            $warningValues = $warning->new_values ?? [];
                        @endphp
                        <tr>
                            <td>{{ $warning->created_at?->format('M d, Y H:i') ?? 'Not provided' }}</td>
                            <td>{{ data_get($warningValues, 'category_label', data_get($warningValues, 'category', 'Warning')) }}</td>
                            <td><strong>{{ data_get($warningValues, 'reason', 'No reason recorded.') }}</strong><small>Corrective action: {{ data_get($warningValues, 'corrective_action', 'Not recorded') }}</small>@if(data_get($warningValues, 'reference'))<small>Reference: {{ data_get($warningValues, 'reference') }}</small>@endif</td>
                            <td>{{ $warning->actor?->name ?: 'System' }}</td>
                            <td>
                                <span class="ad-status is-warning">{{ str(data_get($warningValues, 'status', 'OPEN'))->replace('_', ' ')->headline() }}</span>
                                @if(! in_array(data_get($warningValues, 'status', 'OPEN'), ['RESOLVED', 'CLOSED'], true))
                                    <form method="POST" action="{{ route('admin.users.warnings.review', ['user' => $user, 'warning' => $warning]) }}" style="display:grid;gap:6px;margin-top:8px;">
                                        @csrf
                                        <select name="status" aria-label="Review status for warning {{ $warning->id }}">
                                            @foreach(['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'CLOSED'] as $warningStatus)
                                                <option value="{{ $warningStatus }}" @selected(data_get($warningValues, 'status', 'OPEN') === $warningStatus)>{{ str($warningStatus)->replace('_', ' ')->headline() }}</option>
                                            @endforeach
                                        </select>
                                        <input name="note" maxlength="5000" placeholder="Review note (optional)">
                                        <button class="ad-btn ad-btn-secondary ad-btn-sm" type="submit">Save review</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No account warnings recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($warnings->hasPages())<div class="ad-card-body">{{ $warnings->links() }}</div>@endif
    </section>

    <section class="ad-card">
        <header class="ad-card-head"><h2>Access history</h2><p>Recorded account changes.</p></header>
        <div class="ad-table-wrap ad-user-history-table">
            <table class="ad-table">
                <thead><tr><th>Date</th><th>Change</th><th>Reference</th><th>Administrator</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($changes as $change)
                        <tr><td>{{ $change->created_at->format('M d, Y H:i') }}</td><td>{{ str($change->event)->replace('.', ' ')->headline() }}</td><td><code>{{ data_get($change->new_values, 'reference') ?: 'Not recorded' }}</code></td><td>{{ $change->actor?->name ?? 'System' }}</td><td>{{ data_get($change->old_values, 'status', '—') }} → {{ data_get($change->new_values, 'status', '—') }}</td></tr>
                    @empty
                        <tr><td colspan="5">No access changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($changes->hasPages())<div class="ad-card-body">{{ $changes->links() }}</div>@endif
    </section>
</div>
@endsection
