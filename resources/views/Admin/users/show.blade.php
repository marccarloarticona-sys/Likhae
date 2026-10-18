@extends('layouts.admin')

@section('title', 'Account Details')
@section('subtitle', 'Review registration details and manage account access.')
@section('active', 'users')
@section('content')
@php
    $managedAccount = $user->isAccountType(\App\Models\User::TYPE_BUYER, \App\Models\User::TYPE_SELLER, \App\Models\User::TYPE_LOGISTICS);
    $address = $user->addresses->firstWhere('is_default', true) ?? $user->addresses->first();
    $shop = $user->sellerProfile;
    $provider = $user->logisticsCenter;
    $status = strtolower($user->status);
@endphp
<div class="ad-page ad-user-details-page">
    <div class="ad-page-head">
        <div><span class="ad-overline">USR-{{ $user->id }}</span><h2>{{ $user->name }}</h2><p>{{ $user->email }}</p></div>
        <a class="ad-btn ad-btn-secondary" href="{{ route('admin.users') }}">Back to users</a>
    </div>
    @if(session('success'))<div class="ad-note" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="ad-note" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <section class="ad-card">
        <header class="ad-card-head"><h2>Account information</h2><span class="ad-status is-{{ $status }}">{{ $status === 'pending' ? 'Pending approval' : ucfirst($status) }}</span></header>
        <div class="ad-card-body">
            <dl class="ad-application-meta">
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
                <dl class="ad-application-meta">
                    <div><dt>Business name</dt><dd>{{ $shop?->business_name ?? $provider?->business_name ?? 'Not provided' }}</dd></div>
                    <div><dt>Registration number</dt><dd>{{ $shop?->business_registration_number ?? $provider?->business_registration_number ?? 'Not provided' }}</dd></div>
                    @if($provider)<div><dt>DTI number</dt><dd>{{ $provider->dti_registration_number ?: 'Not provided' }}</dd></div>@endif
                </dl>
            @endif
        </div>
    </section>

    <section class="ad-card" id="account-access">
        <header class="ad-card-head"><h2>Account access</h2></header>
        <div class="ad-card-body">
            @if($managedAccount && in_array($status, ['active', 'suspended'], true))
                @php($suspended = $status === 'suspended')
                <p>{{ $suspended ? 'Reactivate this account to let the user sign in again.' : 'Suspending this account blocks sign-in and access to its workspace.' }}</p>
                <form method="POST" action="{{ route($suspended ? 'admin.users.reactivate' : 'admin.users.suspend', $user) }}" data-confirm-title="{{ $suspended ? 'Reactivate account?' : 'Suspend account?' }}" data-confirm-message="{{ $suspended ? 'This user will be able to access the account again.' : 'This user will lose access to their account.' }}" data-confirm-label="{{ $suspended ? 'Reactivate' : 'Suspend' }}" data-confirm-details="{{ $user->name }} | {{ ucfirst(strtolower($user->account_type)) }}" @if(!$suspended) data-confirm-tone="danger" @endif>
                    @csrf
                    <label class="ad-field">
                        <span>{{ $suspended ? 'Reason for reactivation' : 'Reason for suspension' }}</span>
                        <textarea name="reason" rows="3" required maxlength="2000" placeholder="Explain why this account's access is changing.">{{ old('reason') }}</textarea>
                    </label>
                    @if(! $suspended)
                        <label class="ad-field">
                            <span>Case or evidence reference (optional)</span>
                            <input name="reference" value="{{ old('reference') }}" maxlength="2000" placeholder="Complaint, order, or investigation reference">
                        </label>
                        <label class="ad-field">
                            <span>Evidence or investigation notes (optional)</span>
                            <textarea name="evidence" rows="3" maxlength="5000" placeholder="Record the supporting context for this reviewed decision.">{{ old('evidence') }}</textarea>
                        </label>
                    @endif
                    <button class="ad-btn {{ $suspended ? 'ad-btn-primary' : 'ad-btn-danger-soft' }}" type="submit">{{ $suspended ? 'Reactivate account' : 'Suspend account' }}</button>
                </form>
            @elseif($user->isAccountType(\App\Models\User::TYPE_ADMIN))
                <p>Administrator access cannot be changed from the user directory.</p>
            @else
                <p>This account has not been approved. Access changes are available after registration approval.</p>
            @endif
        </div>
    </section>

    <section class="ad-card" id="account-warnings">
        <header class="ad-card-head"><div><h2>Account Warnings</h2><p>Documented warnings remain separate from suspension decisions.</p></div></header>
        <div class="ad-card-body">
            @if($managedAccount)
                <form method="POST" action="{{ route('admin.users.warnings.store', $user) }}" data-confirm-title="Issue account warning?" data-confirm-message="Send this documented warning and corrective action to {{ $user->name }}?" data-confirm-label="Issue warning">
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
                        <label class="ad-field">
                            <span>Transaction or case reference (optional)</span>
                            <input name="reference" value="{{ old('reference') }}" maxlength="2000" placeholder="Order, complaint, or case reference">
                        </label>
                        <label class="ad-field">
                            <span>Evidence or investigation notes (optional)</span>
                            <textarea name="evidence" rows="3" maxlength="5000" placeholder="Keep notes relevant to this warning.{{ $user->account_type === \App\Models\User::TYPE_BUYER ? ' Do not treat failed checkout alone as proof of misconduct.' : '' }}">{{ old('evidence') }}</textarea>
                        </label>
                    </div>
                    <button class="ad-btn ad-btn-warning-soft" type="submit">Issue Warning</button>
                </form>
            @elseif($user->isAccountType(\App\Models\User::TYPE_ADMIN))
                <p>Administrator accounts cannot receive ordinary User Management warnings or suspension actions.</p>
            @else
                <p>Rider accounts are managed by their Logistics provider and are not available here.</p>
            @endif
        </div>
        <div class="ad-table-wrap">
            <table class="ad-table">
                <thead><tr><th>Date issued</th><th>Category</th><th>Reason / corrective action</th><th>Admin</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($warnings as $warning)
                        @php($warningValues = $warning->new_values ?? [])
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
        <div class="ad-table-wrap">
            <table class="ad-table">
                <thead><tr><th>Date</th><th>Change</th><th>Administrator</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse($changes as $change)
                        <tr><td>{{ $change->created_at->format('M d, Y H:i') }}</td><td>{{ str($change->event)->replace('.', ' ')->headline() }}</td><td>{{ $change->actor?->name ?? 'System' }}</td><td>{{ data_get($change->old_values, 'status', '—') }} → {{ data_get($change->new_values, 'status', '—') }}</td></tr>
                    @empty
                        <tr><td colspan="4">No access changes recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($changes->hasPages())<div class="ad-card-body">{{ $changes->links() }}</div>@endif
    </section>
</div>
@endsection
