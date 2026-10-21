@extends('layouts.admin.app')

@section('title', 'Dashboard')
@section('subtitle', 'Platform health, risk signals, and operational priorities at a glance.')
@section('active', 'dashboard')



@section('content')


<div class="ad-page ad-dashboard-page">
    <div class="ad-page-head">
        <div>
            <span class="ad-overline">Control center</span>

            <h2>
                Marketplace overview
            </h2>

            <p>
                Prioritized for review speed, platform safety, and financial visibility.
            </p>
        </div>

        <div class="ad-inline-actions">
            <a
                href="{{ route('admin.reports') }}"
                class="ad-btn ad-btn-secondary"
            >
                View reports
            </a>

            <a
                href="{{ route('admin.marketing', ['section' => 'announcements']) }}#announcements"
                class="ad-btn ad-btn-primary"
            >
                New announcement
            </a>
        </div>
    </div>

    <section class="ad-stat-grid" aria-label="Platform summary">
        <x-admin.stat-card
            label="Pending applications"
            :value="number_format($accountStats['pending'])"
            detail="Awaiting administrator review"
            icon="users"
            tone="warning"
            :href="route('admin.registrations')"
        />

        <x-admin.stat-card
            label="Active accounts"
            :value="number_format($accountStats['active'])"
            detail="Buyer, Seller, and Logistics accounts"
            icon="users"
            :href="route('admin.users')"
        />

        <x-admin.stat-card
            label="Products"
            :value="number_format($productStats['total'])"
            detail="Current marketplace listings"
            icon="reports"
            :href="route('admin.products')"
        />

        <x-admin.stat-card
            label="Flagged products"
            :value="number_format($productStats['flagged'])"
            detail="Human review needed"
            icon="flag"
            tone="danger"
            :href="route('admin.products', ['view' => 'monitor'])"
        />

        <x-admin.stat-card
            label="Admin-level complaints"
            :value="number_format($attentionStats['complaints'])"
            detail="Open or under administrator review"
            icon="case"
            tone="warning"
            :href="route('admin.complaints')"
        />

        <x-admin.stat-card
            label="Settled commission"
            :value="'₱'.number_format($commissionStats['settled'], 2)"
            :trend="rtrim(rtrim(number_format($commissionRate * 100, 2), '0'), '.').'% platform commission'"
            :detail="'₱'.number_format($commissionStats['pending'], 2).' pending/accrued'"
            icon="money"
            :href="route('admin.finance')"
        />
    </section>

    <section class="ad-dashboard-grid">
        <article class="ad-card ad-chart-card">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">
                        Performance
                    </span>

                    <h2>
                        Marketplace order value
                    </h2>

                    <p>
                        Non-cancelled order value for {{ $marketplacePeriodLabel }}.
                    </p>
                </div>

                <form method="GET" action="{{ route('admin.dashboard') }}">
                    <select class="ad-select" name="period" aria-label="Order value period" onchange="this.form.submit()">
                        <option value="7" @selected($marketplacePeriod === '7')>Last 7 days</option>
                        <option value="30" @selected($marketplacePeriod === '30')>Last 30 days</option>
                        <option value="quarter" @selected($marketplacePeriod === 'quarter')>This quarter</option>
                    </select>
                </form>
            </header>

            <div class="ad-card-body">
                <div class="ad-chart-summary">
                    <div>
                        <small>
                            Gross order value
                        </small>

                        <strong>
                            PHP {{ number_format($marketplaceGross, 2) }}
                        </strong>

                        <small>
                            Non-cancelled orders placed during {{ $marketplacePeriodLabel }} · {{ $marketplaceGroupingLabel }}
                        </small>
                    </div>

                    <div class="ad-chart-legend">
                        <span>
                            <i></i>
                            Order value
                        </span>

                    </div>
                </div>

                @if($marketplaceHasData)
                    <div class="ad-chart-frame">
                        <div class="ad-y-axis" aria-hidden="true">
                            @foreach($marketplaceScale['ticks'] as $tick)
                                <span>₱{{ $tick >= 1000 ? number_format($tick / 1000, 1).'k' : number_format($tick, 0) }}</span>
                            @endforeach
                        </div>

                        <div class="ad-chart-area">
                            <div class="ad-bar-plot" style="--chart-points: {{ $marketplaceChart->count() }}" role="group" aria-label="Order value bar chart for {{ $marketplacePeriodLabel }} grouped as {{ $marketplaceGroupingLabel }}">
                                @foreach($marketplaceChart as $point)
                                    <div
                                        class="ad-bar"
                                        style="--bar-height: {{ $point['height'] }}%"
                                        role="img"
                                        tabindex="0"
                                        aria-label="{{ $point['date'] }}: {{ $point['exact'] }}"
                                    >
                                        <i aria-hidden="true"></i>
                                        <strong class="ad-bar-value">{{ $point['compact'] }}</strong>
                                        <span class="ad-bar-tooltip">{{ $point['date'] }}<br>{{ $point['exact'] }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="ad-chart-x-axis" style="--chart-points: {{ $marketplaceChart->count() }}">
                                @foreach($marketplaceChart as $point)
                                    <span title="{{ $point['date'] }}">{{ $point['label'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @else
                    <div class="ad-chart-empty" role="status">No non-cancelled order value in this period.</div>
                @endif
            </div>
        </article>

        <article class="ad-card">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">
                        Order health
                    </span>

                    <h2>
                        Fulfillment status
                    </h2>

                    <p>
                        Current parcel distribution across the ERP delivery workflow.
                    </p>
                </div>

                <span class="ad-overline">
                    Read-only summary
                </span>
            </header>

            <div class="ad-card-body ad-donut-wrap">
                <div class="ad-donut" style="background: conic-gradient({{ $fulfillmentStats['segments'] }})">
                    <strong>
                        {{ number_format($fulfillmentStats['total']) }}
                        <small>parcels</small>
                    </strong>
                </div>

                <div class="ad-donut-list">
                    <div>
                        <i></i>
                        <span>Seller preparation</span>
                        <b>{{ number_format($fulfillmentStats['seller_preparation']) }}</b>
                    </div>

                    <div>
                        <i></i>
                        <span>Logistics transit</span>
                        <b>{{ number_format($fulfillmentStats['logistics_transit']) }}</b>
                    </div>

                    <div>
                        <i></i>
                        <span>Delivered</span>
                        <b>{{ number_format($fulfillmentStats['delivered']) }}</b>
                    </div>

                    <div>
                        <i></i>
                        <span>Buyer-confirmed complete</span>
                        <b>{{ number_format($fulfillmentStats['completed']) }}</b>
                    </div>

                    <div>
                        <i></i>
                        <span>Failed / returned</span>
                        <b>{{ number_format($fulfillmentStats['exceptions']) }}</b>
                    </div>
                </div>
            </div>
        </article>
    </section>

    <section class="ad-dashboard-split">
        <article class="ad-card">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">
                        Operations
                    </span>

                    <h2>
                        Recent Accounts Pending Approval
                    </h2>

                    <p>
                        Latest account registrations awaiting administrator review.
                    </p>
                </div>

                <span class="ad-overline">
                    Administrator review
                </span>
            </header>

            <div class="ad-table-wrap">
                <table class="ad-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Account type</th>
                            <th>Date submitted</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentPendingApplications as $application)
                            <tr>
                                <td>
                                    <strong>{{ $application->user?->name ?: 'Unknown applicant' }}</strong>
                                    <small class="ad-muted">{{ $application->application_number }}</small>
                                </td>
                                <td>{{ str($application->user?->account_type ?? 'UNKNOWN')->headline() }}</td>
                                <td>{{ $application->submitted_at?->format('M j, Y') ?? 'Not submitted' }}</td>
                                <td>
                                    <span class="ad-status is-{{ \Illuminate\Support\Str::slug($application->status) }}">
                                        {{ str($application->status)->replace('_', ' ')->headline() }}
                                    </span>
                                </td>
                                <td>
                                    <a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.registrations.show', $application) }}">Review</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="ad-muted">No pending account applications.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ad-card-body">
                <a href="{{ route('admin.registrations') }}" class="ad-btn ad-btn-secondary ad-btn-sm">View All Applications -&gt;</a>
            </div>
        </article>

        <article class="ad-card">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">
                        Priority queue
                    </span>

                    <h2>
                        Needs attention
                    </h2>

                    <p>
                        Risk-ranked work for administrators.
                    </p>
                </div>
            </header>

            <div class="ad-queue">
                <a
                    href="{{ route('admin.compliance') }}"
                    class="ad-queue-item is-danger"
                >
                    <span class="ad-queue-icon">{{ number_format($attentionStats['compliance']) }}</span>

                    <span class="ad-queue-copy">
                        <strong>
                            Compliance concerns
                        </strong>

                        <span>
                            Open or under-review Seller compliance cases
                        </span>
                    </span>

                    <span>-&gt;</span>
                </a>

                <a
                    href="{{ route('admin.registrations') }}"
                    class="ad-queue-item is-warning"
                >
                    <span class="ad-queue-icon">{{ number_format($accountStats['pending']) }}</span>

                    <span class="ad-queue-copy">
                        <strong>
                            Pending applications
                        </strong>

                        <span>
                            {{ number_format($accountStats['pending_older_than_day']) }} older than 24 hours
                        </span>
                    </span>

                    <span>-&gt;</span>
                </a>

                <a
                    href="{{ route('admin.complaints') }}"
                    class="ad-queue-item is-warning"
                >
                    <span class="ad-queue-icon">{{ number_format($attentionStats['complaints']) }}</span>

                    <span class="ad-queue-copy">
                        <strong>
                            Admin-level complaints
                        </strong>

                        <span>
                            Open or under administrator review
                        </span>
                    </span>

                    <span>-&gt;</span>
                </a>
            </div>
        </article>
    </section>

    <div class="ad-note">
        <strong>Governance note:</strong>
        Automated product monitoring only creates risk signals. An administrator must review evidence before removing a listing, warning a user, or applying a ban.
    </div>
</div>
@endsection
