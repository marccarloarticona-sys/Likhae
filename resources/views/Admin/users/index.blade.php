@extends('layouts.admin.app')

@section('title', 'User Management')
@section('subtitle', 'Monitor platform accounts, issue violation notices, and apply proportionate enforcement.')
@section('active', 'users')

@php
    $stats = array_merge(['total' => 0, 'buyers' => 0, 'sellers' => 0, 'delivery' => 0], $stats ?? []);
    $role = $role ?? request('role', 'all');
    $status = $status ?? request('status', 'all');
    $search = $search ?? request('q', '');
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
                Search registered accounts and open a profile to review or manage access.
            </p>
        </div>

        <a
            class="ad-btn ad-btn-primary"
            href="{{ route('admin.registrations') }}"
        >
            Review registrations
        </a>
    </div>

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
    </section>

    @unless($accountEnforcementReady ?? false)
        <div class="ad-note" role="status">
            Suspension and permanent ban controls are unavailable until the account enforcement database migration is applied.
        </div>
    @endunless

    <nav class="ad-tabs" aria-label="User role">
        @foreach($roles as $key => $label)
            <a
                class="ad-tab {{ $role === $key ? 'is-active' : '' }}"
                @if($role === $key) aria-current="page" @endif
                href="{{ route('admin.users', ['role' => $key, 'status' => $status, 'q' => $search]) }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <section class="ad-card ad-users-card" id="user-table">
        <form class="ad-filter-bar" action="{{ route('admin.users') }}" method="GET" role="search">
            <input type="hidden" name="role" value="{{ $role }}">
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

        <div class="ad-table-wrap">
            <table class="ad-table">
                <thead>
                    <tr>
                        <th>
                            <input
                                type="checkbox"
                                data-select-all
                                aria-label="Select all users"
                            >
                        </th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Orders / jobs</th>
                        <th>Status</th>
                        <th style="text-align:right">Actions</th>
                    </tr>
                </thead>

                <tbody>
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
                        @endphp

                        <tr
                            data-filter-item
                            data-search="{{ $searchText }}"
                        >
                            <td>
                                <input
                                    type="checkbox"
                                    aria-label="Select {{ $user->name }}"
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
                                    </span>
                                </div>
                            </td>

                            <td>
                                {{ $roleLabel }}
                            </td>

                            <td>
                                {{ $user->created_at->format('M d, Y') }}
                            </td>

                            <td>
                                0
                            </td>

                            <td>
                                <span class="ad-status is-{{ $statusClass }}">
                                    {{ $statusLabel }}
                                </span>
                            </td>

                            <td>
                                <div class="ad-row-actions">
                                    <a
                                        class="ad-btn ad-btn-secondary ad-btn-sm"
                                        href="{{ route('admin.users.show', $user) }}"
                                    >
                                        View
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                No accounts match your search.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="ad-pagination">
            <span>
                Showing {{ $users->firstItem() ?? 0 }}-{{ $users->lastItem() ?? 0 }} of {{ $users->total() }} accounts
            </span>

            <nav aria-label="User pages">
                @if($users->onFirstPage())
                    <span class="ad-page-btn" aria-disabled="true">Previous</span>
                @else
                    <a class="ad-page-btn" rel="prev" href="{{ $users->previousPageUrl() }}">Previous</a>
                @endif

                @foreach($users->getUrlRange(1, $users->lastPage()) as $page => $url)
                    @if($page === $users->currentPage())
                        <span class="ad-page-btn is-active" aria-current="page">{{ $page }}</span>
                    @else
                        <a class="ad-page-btn" href="{{ $url }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach

                @if($users->hasMorePages())
                    <a class="ad-page-btn" rel="next" href="{{ $users->nextPageUrl() }}">Next</a>
                @else
                    <span class="ad-page-btn" aria-disabled="true">Next</span>
                @endif
            </nav>
        </div>
    </section>
</div>
@endsection
