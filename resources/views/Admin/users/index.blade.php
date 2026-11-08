@extends('layouts.admin.app')

@section('title', 'User Management')
@section('subtitle', 'Monitor platform accounts, issue violation notices, and apply proportionate enforcement.')
@section('active', 'users')

@php
    $stats = array_merge(['total' => 0, 'buyers' => 0, 'sellers' => 0, 'delivery' => 0, 'admins' => 0], $stats ?? []);
    $role = $role ?? request('role', 'all');
    $status = $status ?? request('status', 'all');
    $search = $search ?? request('q', '');
    $riskFilter = $riskFilter ?? request('risk', 'all');
    $openRiskBuyerIds = $openRiskBuyerIds ?? collect();
    $roles = $roles ?? [
        'all' => 'All users',
        'buyers' => 'Buyers',
        'sellers' => 'Sellers',
        'logistics' => 'Logistics centers',
        'admins' => 'Administrators',
    ];
    $statuses = $statuses ?? [
        'all' => 'All statuses',
        'pending' => 'Pending approval',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'banned' => 'Permanently banned',
        'deactivated' => 'Deactivated',
    ];
    if (! ($accountEnforcementReady ?? false)) {
        unset($statuses['banned']);
    }
    $statusClasses = [
        'pending' => 'under-review',
        'active' => 'active',
        'suspended' => 'banned',
        'banned' => 'banned',
        'deactivated' => 'banned',
    ];
@endphp
@section('content')
<div class="ad-page ad-users-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">
                Platform accounts
            </span>

            <h2>
                User directory
            </h2>

            <p>
                Search registered accounts, review their latest sign-in location, or end their active sessions.
            </p>
        </div>

        <div class="ad-inline-actions">
            @if($role === 'admins')
                <a class="ad-btn ad-btn-primary" href="#create-admin-account">Create administrator</a>
                <a class="ad-btn ad-btn-secondary" href="{{ route('admin.registrations') }}">Review registrations</a>
            @else
                <a class="ad-btn ad-btn-primary" href="{{ route('admin.registrations') }}">Review registrations</a>
            @endif
        </div>
    </div>

    @if(session('status'))
        <div class="ad-note" role="status">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="ad-note" role="alert">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="ad-summary-grid" aria-label="User summary">
        <div class="ad-mini-stat">
            <span>Total accounts</span>
            <strong>{{ number_format($stats['total']) }}</strong>
            <small>Buyers, sellers, administrators, and logistics</small>
        </div>

        <div class="ad-mini-stat">
            <span>Buyers</span>
            <strong>{{ number_format($stats['buyers']) }}</strong>
            <small>Registered buyer accounts</small>
        </div>

        <div class="ad-mini-stat">
            <span>Sellers</span>
            <strong>{{ number_format($stats['sellers']) }}</strong>
            <small>Registered seller accounts</small>
        </div>

        <div class="ad-mini-stat">
            <span>Logistics</span>
            <strong>{{ number_format($stats['delivery']) }}</strong>
            <small>Registered logistics accounts</small>
        </div>

        <div class="ad-mini-stat">
            <span>Administrators</span>
            <strong>{{ number_format($stats['admins']) }}</strong>
            <small>Registered administrator accounts</small>
        </div>
    </section>

    @unless($accountEnforcementReady ?? false)
        <div class="ad-note" role="status">
            Suspension and permanent ban controls are unavailable until the account enforcement database migration is applied.
        </div>
    @endunless

    @unless($sessionManagementAvailable ?? false)
        <div class="ad-note" role="status">
            Active session details and sign-out controls are unavailable for the configured session driver or until the existing sessions table is installed.
        </div>
    @endunless

    <nav class="ad-tabs" aria-label="User role">
        @foreach($roles as $key => $label)
            <a
                class="ad-tab {{ $role === $key ? 'is-active' : '' }}"
                @if($role === $key) aria-current="page" @endif
                href="{{ route('admin.users', ['role' => $key, 'status' => $status, 'q' => $search, 'risk' => $key === 'buyers' && $riskFilter === 'flagged' ? 'flagged' : null]) }}"
            >
                {{ $label }}
            </a>
        @endforeach
        @if($role === 'buyers')
            <div class="ad-users-safety-actions" style="margin-left:auto;flex:0 0 auto">
                <a class="ad-btn ad-users-safety-review {{ $riskFilter === 'flagged' ? 'ad-btn-primary' : 'ad-btn-secondary' }}" href="{{ route('admin.users', ['role' => 'buyers', 'risk' => 'flagged']) }}">
                    Buyers flagged for safety review ({{ number_format($stats['open_buyer_risk_reviews'] ?? 0) }})
                </a>
                @if($riskFilter === 'flagged')
                    <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.users', ['role' => 'buyers']) }}">Show all buyers</a>
                @endif
            </div>
        @endif
    </nav>

    @if($role === 'admins')
        <section class="ad-card" id="create-admin-account" style="margin:16px 0">
            <div class="ad-card-head">
                <div>
                    <span class="ad-overline">Administrator access</span>
                    <h2>Create administrator account</h2>
                    <p>This creates an active, email-verified Admin account. Use a temporary password and share it privately.</p>
                </div>
            </div>
            <form
                method="POST"
                action="{{ route('admin.users.admins.store') }}"
                class="ad-card-body"
                data-confirm-title="Create administrator account?"
                data-confirm-message="This grants the new account full Admin access immediately."
                data-confirm-label="Create administrator"
            >
                @csrf
                <div class="ad-form-grid">
                    <label class="ad-field">
                        <span>First name</span>
                        <input name="first_name" value="{{ old('first_name') }}" maxlength="100" autocomplete="given-name" required>
                        @error('first_name')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Middle initial <small>(optional)</small></span>
                        <input name="middle_initial" value="{{ old('middle_initial') }}" maxlength="10" autocomplete="additional-name">
                        @error('middle_initial')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Last name</span>
                        <input name="last_name" value="{{ old('last_name') }}" maxlength="100" autocomplete="family-name" required>
                        @error('last_name')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Email</span>
                        <input name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" required>
                        @error('email')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Contact number</span>
                        <input name="contact_number" type="tel" value="{{ old('contact_number') }}" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="09XXXXXXXXX or +639XXXXXXXXX" required>
                        @error('contact_number')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Birthday</span>
                        <input name="birthday" type="date" value="{{ old('birthday') }}" max="{{ now()->subDay()->toDateString() }}" autocomplete="bday" required>
                        @error('birthday')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Password</span>
                        <input name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                        <small>At least 8 characters, with uppercase, lowercase, and a number.</small>
                        @error('password')<small role="alert">{{ $message }}</small>@enderror
                    </label>
                    <label class="ad-field">
                        <span>Confirm password</span>
                        <input name="password_confirmation" type="password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </label>
                </div>
                <div class="ad-inline-actions" style="justify-content:flex-end;margin-top:16px">
                    <button class="ad-btn ad-btn-primary" type="submit">Create administrator</button>
                </div>
            </form>
        </section>
    @endif

    <section class="ad-card ad-users-card" id="user-table">
        <div class="ad-users-toolbar">
            <form class="ad-filter-bar" action="{{ route('admin.users') }}" method="GET" role="search">
                <input type="hidden" name="role" value="{{ $role }}">
                @if($riskFilter === 'flagged')<input type="hidden" name="risk" value="flagged">@endif
                <label class="ad-filter-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.5-3.5"/>
                    </svg>

                    <input
                        name="q"
                        type="search"
                        value="{{ $search }}"
                        maxlength="100"
                        placeholder="Search name, email, or user ID"
                        aria-label="Search name, email, or user ID"
                    >
                </label>

                <div class="ad-inline-actions">
                    <select class="ad-select" name="status" aria-label="Account status">
                        @foreach($statuses as $key => $label)
                            <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                        @endforeach
                    </select>

                    <button
                        class="ad-btn ad-btn-primary ad-btn-sm"
                        type="submit"
                    >
                        Search
                    </button>

                    <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.users') }}">
                        Clear
                    </a>
                </div>
            </form>

            <div class="ad-inline-actions ad-users-session-toolbar" data-session-toolbar>
                <span class="ad-muted" aria-live="polite">
                    <strong data-session-selected-count>0</strong> selected accounts
                    <small data-session-selection-message></small>
                </span>
                <button
                    class="ad-btn ad-btn-danger-soft ad-btn-sm"
                    type="submit"
                    form="user-session-revocation-form"
                    data-session-bulk-submit
                    disabled
                    @disabled(! ($sessionManagementAvailable ?? false))
                >
                    Suspend selected sessions
                </button>
            </div>
        </div>

        <form
            id="user-session-revocation-form"
            method="POST"
            action="{{ route('admin.users.sessions.revoke') }}"
            data-session-revocation-form
        >
            @csrf

            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>
                                <input
                                    type="checkbox"
                                    data-select-all
                                    aria-label="Select all loaded accounts"
                                    @disabled(! ($sessionManagementAvailable ?? false))
                                >
                            </th>
                            <th>User</th>
                            <th>Role</th>
                            <th>Joined</th>
                            <th>IP address</th>
                            <th>Browser / sessions</th>
                            <th>Status</th>
                            <th style="text-align:right">Actions</th>
                        </tr>
                    </thead>

                    <tbody data-user-table-body>
                        @forelse($users as $user)
                            @php
                                $roleLabel = ucfirst(strtolower($user->account_type));
                                $statusKey = $user->isPermanentlyBanned() ? 'banned' : strtolower($user->status);
                                $statusLabel = $statuses[$statusKey] ?? ucfirst($statusKey);
                                $statusClass = $statusClasses[$statusKey] ?? strtolower(str_replace(' ', '-', $statusKey));
                                $searchText = strtolower('USR-'.$user->id.' '.$user->name.' '.$user->email.' '.$roleLabel.' '.$statusLabel);
                                $profilePhotoPath = $user->profile_photo_path;
                                $profilePhotoUrl = filled($profilePhotoPath)
                                    ? (str($profilePhotoPath)->startsWith(['http://', 'https://'])
                                        ? $profilePhotoPath
                                        : \Illuminate\Support\Facades\Storage::disk('public')->url($profilePhotoPath))
                                    : null;
                                $sessionSummary = $sessionSummaries[$user->id] ?? ['ip_address' => null, 'browser' => 'Not captured', 'session_count' => 0];
                                $isCurrentAdmin = (int) auth()->id() === (int) $user->id;
                            @endphp

                            <tr
                                data-filter-item
                                data-search="{{ $searchText }}"
                            >
                                <td>
                                    <input
                                        type="checkbox"
                                        name="user_ids[]"
                                        value="{{ $user->id }}"
                                        aria-label="Select {{ $user->name }}"
                                        data-session-user-checkbox
                                        @disabled($isCurrentAdmin || ! ($sessionManagementAvailable ?? false))
                                    >
                                </td>

                                <td>
                                    <div class="ad-cell-user">
                                        <span class="ad-avatar is-soft">
                                            @if($profilePhotoUrl)
                                                <img src="{{ $profilePhotoUrl }}" alt="{{ $user->name }} profile photo" loading="lazy" decoding="async">
                                            @else
                                                {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                                            @endif
                                        </span>

                                        <span>
                                            <strong>
                                                {{ $user->name }}
                                            </strong>

                                            <small>
                                                USR-{{ $user->id }} - {{ $user->email }}
                                            </small>
                                            @if($user->account_type === \App\Models\User::TYPE_SELLER && (int) ($user->pending_seller_document_updates_count ?? 0) > 0)
                                                <a class="ad-user-update-link" href="{{ route('admin.users.show', $user) }}#seller-verification">Seller document update waiting for review</a>
                                            @endif
                                        </span>
                                    </div>
                                </td>

                                <td>{{ $roleLabel }}</td>
                                <td>{{ $user->created_at->format('M d, Y') }}</td>
                                <td>{{ $sessionSummary['ip_address'] ?? 'Not captured' }}</td>
                                <td>
                                    @if(($sessionSummary['session_count'] ?? 0) > 0)
                                        <strong>{{ $sessionSummary['browser'] }}</strong>
                                        <small>{{ $sessionSummary['session_count'] }} active {{ \Illuminate\Support\Str::plural('session', $sessionSummary['session_count']) }}</small>
                                    @else
                                        <span>-</span>
                                        <small>No active sessions detected</small>
                                    @endif
                                </td>
                                <td>
                                    <span class="ad-status is-{{ $statusClass }}">
                                        {{ $statusLabel }}
                                    </span>
                                    @if($user->isAccountType(\App\Models\User::TYPE_BUYER) && $openRiskBuyerIds->contains((int) $user->id))
                                        <small class="ad-status is-warning" style="display:block; width:max-content; margin-top:5px">Safety review open</small>
                                    @endif
                                </td>

                                <td>
                                    <div class="ad-row-actions">
                                        <a
                                            class="ad-btn ad-btn-secondary ad-btn-sm"
                                            href="{{ route('admin.users.show', $user) }}"
                                        >
                                            View
                                        </a>
                                        <button
                                            class="ad-btn ad-btn-danger-soft ad-btn-sm"
                                            type="submit"
                                            name="single_user_id"
                                            value="{{ $user->id }}"
                                            data-session-single-submit
                                            @disabled($isCurrentAdmin || ! ($sessionManagementAvailable ?? false))
                                            @if($isCurrentAdmin) title="You cannot suspend your own current session." @endif
                                        >
                                            Suspend session
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8">
                                    No accounts match your search.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>

        <div
            class="ad-users-load-more"
            data-user-load-more
            data-next-page-url="{{ $users->nextPageUrl() }}"
            data-total-users="{{ $users->total() }}"
            data-first-user="{{ $users->firstItem() ?? 0 }}"
            data-loaded-users="{{ $users->count() }}"
        >
            <span data-user-count-label>
                Showing {{ $users->firstItem() ?? 0 }}-{{ $users->lastItem() ?? 0 }} of {{ $users->total() }} accounts
            </span>

            <button class="ad-btn ad-btn-secondary ad-btn-sm" type="button" data-user-load-more-button @if(!$users->hasMorePages()) hidden @endif>
                Load more accounts
            </button>
            <span data-user-load-more-status role="status" aria-live="polite"></span>
            <span class="ad-users-load-more__sentinel" data-user-load-more-sentinel aria-hidden="true"></span>
        </div>
    </section>
</div>
@endsection
