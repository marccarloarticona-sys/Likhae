@extends('layouts.buyer')

@section('title', 'Rewards & Vouchers')
@section('active', 'rewards')
@section('subtitle', 'Manage vouchers, points, and voucher history in one place.')

@php
    $tab = in_array(request('tab'), ['vouchers', 'points'], true)
        ? request('tab')
        : 'vouchers';

    $tabs = [
        'vouchers' => 'My Vouchers',
        'points' => 'Reward Points',
    ];
@endphp

@push('head')
<style>
    :root {
        --lk-bg: #FBF7F2;
        --lk-bg-soft: #F6EFE7;
        --lk-bg-alt: #EFE7DE;
        --lk-card: #FFFDF9;

        --lk-border: #EADCCC;
        --lk-border-strong: #DBCEC1;

        --lk-maroon: #561C17;
        --lk-maroon-2: #642920;
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-gold: #C88418;

        --lk-success: #256F4A;
        --lk-warning: #9A5B11;

        --lk-shadow-soft: 0 8px 24px rgba(86, 28, 23, 0.055);
        --lk-shadow-card: 0 16px 40px rgba(86, 28, 23, 0.09);
    }

    .lk-rewards-page {
        display: grid;
        gap: 22px;
    }

    .lk-rewards-tabs {
        display: flex;
        gap: 6px;
        overflow-x: auto;

        padding: 6px;

        border: 1px solid var(--lk-border);
        border-radius: 16px;

        background: var(--lk-card);
        box-shadow: var(--lk-shadow-soft);
    }

    .lk-reward-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        min-height: 40px;
        padding: 0 16px;

        border-radius: 12px;

        color: var(--lk-brown);

        font-size: 12px;
        font-weight: 900;
        text-decoration: none;
        white-space: nowrap;

        transition: 160ms ease;
    }

    .lk-reward-tab:hover {
        background: var(--lk-bg-soft);
        color: var(--lk-maroon);
    }

    .lk-reward-tab.is-active {
        background: var(--lk-maroon);
        color: #FFFFFF;
        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.16);
    }

    .lk-reward-voucher-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 16px;
    }

    .lk-voucher-card,
    .lk-reward-panel,
    .lk-points-card {
        overflow: hidden;

        border: 1px solid var(--lk-border);
        border-radius: 22px;

        background:
            radial-gradient(circle at 96% 4%, rgba(193, 151, 113, 0.13), transparent 28%),
            linear-gradient(180deg, #FFFDF9 0%, #FFF9F2 100%);

        color: var(--lk-text);
        box-shadow: var(--lk-shadow-soft);

        transition:
            transform 180ms ease,
            border-color 180ms ease,
            box-shadow 180ms ease;
    }

    .lk-voucher-card:hover,
    .lk-points-card:hover {
        transform: translateY(-3px);
        border-color: var(--lk-tan);
        box-shadow: var(--lk-shadow-card);
    }

    .lk-voucher-top {
        display: flex;
        align-items: center;
        gap: 15px;

        padding: 18px;

        border-bottom: 1px dashed var(--lk-border-strong);
    }

    .lk-voucher-icon {
        display: grid;
        width: 58px;
        height: 58px;
        flex: 0 0 58px;
        place-items: center;

        border-radius: 16px;

        background: #F1E4D7;
        color: var(--lk-maroon);

        font-size: 18px;
        font-weight: 950;
    }

    .lk-voucher-status {
        color: var(--lk-maroon);

        font-size: 9px;
        font-weight: 900;
        letter-spacing: 0.16em;
        text-transform: uppercase;
    }

    .lk-voucher-card h2 {
        margin: 4px 0 0;

        color: var(--lk-text);

        font-size: 21px;
        font-weight: 950;
        letter-spacing: -0.04em;
    }

    .lk-voucher-card p {
        margin: 4px 0 0;

        color: var(--lk-muted);

        font-size: 12px;
        line-height: 1.55;
    }

    .lk-voucher-bottom {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;

        padding: 16px 18px;
    }

    .lk-voucher-code {
        display: inline-flex;
        align-items: center;

        min-height: 28px;
        padding: 0 10px;

        border: 1px solid var(--lk-border);
        border-radius: 9px;

        background: var(--lk-bg-soft);
        color: var(--lk-maroon);

        font-size: 11px;
        font-weight: 950;
        letter-spacing: 0.08em;
    }

    .lk-voucher-expiry {
        display: block;
        margin-top: 5px;

        color: var(--lk-muted-2);

        font-size: 10px;
        font-weight: 600;
    }

    .lk-reward-panel {
        padding: 20px;
    }

    .lk-reward-panel h2 {
        margin: 0;

        color: var(--lk-text);

        font-size: 18px;
        font-weight: 950;
        letter-spacing: -0.035em;
    }

    .lk-reward-table-wrap {
        overflow-x: auto;
        margin-top: 14px;
    }

    .lk-reward-table {
        width: 100%;
        min-width: 560px;
        border-collapse: collapse;
        text-align: left;
    }

    .lk-reward-table thead {
        border-top: 1px solid var(--lk-border);
        border-bottom: 1px solid var(--lk-border);
        background: var(--lk-bg-soft);
    }

    .lk-reward-table th {
        padding: 13px 14px;

        color: var(--lk-muted);

        font-size: 10px;
        font-weight: 900;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }

    .lk-reward-table td {
        padding: 13px 14px;
        border-bottom: 1px solid #EFE1D5;

        color: var(--lk-brown);

        font-size: 12px;
    }

    .lk-reward-table td strong {
        color: var(--lk-text);
        font-weight: 900;
    }

    .lk-reward-table .is-muted {
        color: var(--lk-muted);
    }

    .lk-points-layout {
        display: grid;
        grid-template-columns: 360px minmax(0, 1fr);
        gap: 16px;
    }

    .lk-points-wallet {
        padding: 26px;

        border-radius: 22px;

        background:
            radial-gradient(circle at 92% 10%, rgba(255, 255, 255, 0.15), transparent 28%),
            linear-gradient(135deg, var(--lk-maroon) 0%, var(--lk-maroon-2) 58%, var(--lk-maroon-dark) 100%);

        color: #FFFFFF;
        box-shadow: 0 18px 44px rgba(86, 28, 23, 0.18);
    }

    .lk-points-wallet span {
        color: #E8C8B2;
        font-size: 12px;
        font-weight: 700;
    }

    .lk-points-wallet strong {
        display: block;
        margin-top: 8px;

        color: #FFFFFF;

        font-size: 46px;
        font-weight: 950;
        letter-spacing: -0.055em;
        line-height: 1;
    }

    .lk-points-note {
        margin-top: 24px;
        padding-top: 16px;

        border-top: 1px solid rgba(255, 255, 255, 0.16);

        color: #F4DED4;

        font-size: 12px;
        line-height: 1.6;
    }

    .lk-points-card {
        padding: 20px;
    }

    .lk-points-card h2 {
        margin: 0;

        color: var(--lk-text);

        font-size: 18px;
        font-weight: 950;
        letter-spacing: -0.035em;
    }

    .lk-earn-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;

        margin-top: 16px;
    }

    .lk-earn-item {
        padding: 16px;

        border: 1px solid var(--lk-border);
        border-radius: 16px;

        background: var(--lk-card);
    }

    .lk-earn-item strong {
        display: block;

        color: var(--lk-maroon);

        font-size: 24px;
        font-weight: 950;
        letter-spacing: -0.04em;
    }

    .lk-earn-item p {
        margin: 5px 0 0;

        color: var(--lk-muted);

        font-size: 12px;
    }

    .lk-activity-list {
        display: grid;
        margin-top: 12px;
    }

    .lk-activity-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;

        padding: 14px 0;

        border-bottom: 1px solid #EFE1D5;
    }

    .lk-activity-row:last-child {
        border-bottom: 0;
    }

    .lk-activity-row strong {
        display: block;

        color: var(--lk-text);

        font-size: 12px;
        font-weight: 900;
    }

    .lk-activity-row time,
    .lk-activity-row small {
        display: block;
        margin-top: 3px;

        color: var(--lk-muted-2);

        font-size: 10px;
    }

    .lk-activity-amount {
        color: var(--lk-maroon);

        font-size: 12px;
        font-weight: 950;
        white-space: nowrap;
    }

    .lk-activity-amount.is-negative {
        color: var(--lk-muted);
    }

    @media (max-width: 1100px) {
        .lk-reward-voucher-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .lk-points-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 700px) {
        .lk-reward-voucher-grid,
        .lk-earn-grid {
            grid-template-columns: 1fr;
        }

        .lk-voucher-bottom {
            align-items: stretch;
            flex-direction: column;
        }

        .lk-voucher-bottom .lk-btn {
            width: 100%;
        }

    }

    html.dark .lk-rewards-tabs,
    html.dark .lk-voucher-card,
    html.dark .lk-reward-panel,
    html.dark .lk-points-card,
    html.dark .lk-earn-item {
        background: #211B17 !important;
        border-color: #3B2E27 !important;
        color: #F5EFE8 !important;
    }

    html.dark .lk-reward-tab {
        color: #C8B7AD !important;
    }

    html.dark .lk-reward-tab:hover {
        background: #2D1414 !important;
        color: #EBA99D !important;
    }

    html.dark .lk-reward-tab.is-active {
        background: #8A3A2F !important;
        color: #FFFFFF !important;
    }

    html.dark .lk-voucher-card h2,
    html.dark .lk-reward-panel h2,
    html.dark .lk-points-card h2,
    html.dark .lk-activity-row strong {
        color: #F5EFE8 !important;
    }

    html.dark .lk-voucher-card p,
    html.dark .lk-activity-row time,
    html.dark .lk-activity-row small {
        color: #C8B7AD !important;
    }

    html.dark .lk-voucher-icon,
    html.dark .lk-voucher-code {
        background: #2D1414 !important;
        border-color: #51342D !important;
        color: #EBA99D !important;
    }

    html.dark .lk-reward-table thead {
        background: #1E1A17 !important;
        border-color: #3B2E27 !important;
    }

    html.dark .lk-reward-table td,
    html.dark .lk-activity-row {
        border-color: #3B2E27 !important;
        color: #C8B7AD !important;
    }

    html.dark .lk-reward-table td strong,
    html.dark .lk-activity-amount {
        color: #EBA99D !important;
    }
</style>
@endpush

@section('content')
<div class="lk-page lk-rewards-page">
    <div class="lk-page-title">
        <div>
            <span class="lk-kicker">
                Buyer Benefits
            </span>

            <h1>
                Rewards & Vouchers
            </h1>

            <p>
                Use earned benefits before they expire.
            </p>
        </div>

        <a
            class="lk-btn lk-btn-light"
            href="{{ route('buyer.products') }}"
        >
            Browse Products
        </a>
    </div>

    <nav
        class="lk-rewards-tabs"
        aria-label="Reward sections"
    >
        @foreach($tabs as $key => $label)
            <a
                class="lk-reward-tab {{ $tab === $key ? 'is-active' : '' }}"
                href="{{ route('buyer.rewards', ['tab' => $key]) }}"
            >
                {{ $label }}
            </a>
        @endforeach
    </nav>

    @if($tab === 'vouchers')
        <h2 class="text-lg font-black text-stone-900 dark:text-stone-100">Seller vouchers</h2>
        <section class="lk-reward-voucher-grid">
            @forelse($sellerVoucherCards as $voucher)
                <article class="lk-voucher-card">
                    <div class="lk-voucher-top">
                        <div class="lk-voucher-icon">
                            {{ $voucher['icon'] }}
                        </div>

                        <div>
                            <span class="lk-voucher-status">
                                {{ $voucher['status'] }}
                            </span>
                            <span class="lk-voucher-status">
                                {{ $voucher['collected'] ? 'Collected' : 'Seller voucher' }}
                            </span>


                            <h2>
                                {{ $voucher['value'] }}
                            </h2>

                            <p>
                                {{ $voucher['condition'] }}
                            </p>
                        </div>
                    </div>

                    <div class="lk-voucher-bottom">
                        <div>
                            <code class="lk-voucher-code">
                                {{ $voucher['code'] }}
                            </code>

                            <small class="lk-voucher-expiry">
                                Expires {{ $voucher['expires'] }}
                            </small>
                            @if($voucher['collected'])
                                <small class="lk-voucher-expiry">
                                    @if($voucher['unavailable_reason'])
                                        Unavailable: {{ $voucher['unavailable_reason'] }}
                                    @else
                                        Collected. Checkout will recheck order eligibility.
                                    @endif
                                </small>
                            @endif
                        </div>

                        @if($voucher['collected'])
                            <a href="{{ route('buyer.products') }}" class="lk-btn lk-btn-red">Use Now</a>
                        @else
                            <form method="POST" action="{{ route('buyer.vouchers.claim', $voucher['id']) }}">
                                @csrf
                                <button type="submit" class="lk-btn lk-btn-red">Claim</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <article class="lk-voucher-card">
                    <div class="lk-voucher-top">
                        <div class="lk-voucher-icon">
                            %
                        </div>

                        <div>
                            <span class="lk-voucher-status">
                                No active vouchers
                            </span>

                            <h2>
                                No Seller vouchers to collect
                            </h2>

                            <p>
                                Eligible Seller vouchers will appear here. Platform Campaigns stay in their separate section.
                            </p>
                        </div>
                    </div>

                    <div class="lk-voucher-bottom">
                        <div>
                            <code class="lk-voucher-code">
                                NONE
                            </code>

                            <small class="lk-voucher-expiry">
                                Check again later
                            </small>
                        </div>

                        <a
                            href="{{ route('buyer.products') }}"
                            class="lk-btn lk-btn-red"
                        >
                            Browse Sellers
                        </a>
                    </div>
                </article>
            @endforelse
        </section>

        @if($platformVoucherCards->isNotEmpty())
            <section class="lk-reward-panel">
                <h2>Admin Platform Campaigns</h2>
                <p class="mt-1 text-sm text-stone-600 dark:text-stone-300">These campaigns are managed by LIKHAE and are separate from Seller voucher claims.</p>
                <div class="lk-reward-voucher-grid mt-4">
                    @foreach($platformVoucherCards as $voucher)
                        <article class="lk-voucher-card">
                            <div class="lk-voucher-top">
                                <div class="lk-voucher-icon">{{ $voucher['icon'] }}</div>
                                <div>
                                    <span class="lk-voucher-status">{{ $voucher['status'] }}</span>
                                    <h2>{{ $voucher['value'] }}</h2>
                                    <p>{{ $voucher['condition'] }}</p>
                                </div>
                            </div>
                            <div class="lk-voucher-bottom">
                                <div>
                                    <code class="lk-voucher-code">{{ $voucher['code'] }}</code>
                                    <small class="lk-voucher-expiry">Expires {{ $voucher['expires'] }}</small>
                                </div>
                                <a href="{{ route('buyer.products') }}" class="lk-btn lk-btn-red">Use Now</a>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="lk-reward-panel">
            <h2>
                Voucher history
            </h2>

            <div class="lk-reward-table-wrap">
                <table class="lk-reward-table">
                    <thead>
                        <tr>
                            <th>Voucher</th>
                            <th>Benefit</th>
                            <th>Order</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse($voucherHistory as $history)
                            <tr>
                                <td>
                                    <strong>{{ $history['voucher'] }}</strong>
                                </td>

                                <td>{{ $history['benefit'] }}</td>

                                <td>{{ $history['order'] }}</td>

                                <td class="is-muted">{{ $history['status'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="is-muted">
                                    No voucher history yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif($tab === 'points')
        <section class="lk-points-layout">
            <article class="lk-points-wallet">
                <span>
                    Available balance
                </span>

                <strong>
                    {{ number_format($pointsBalance) }}
                </strong>

                <span>
                    LIKHAE Reward Points
                </span>

                <div class="lk-points-note">
                    100 points = PHP 1 checkout discount
                </div>
            </article>

            <article class="lk-points-card">
                <h2>
                    Earn more points
                </h2>

                <div class="lk-earn-grid">
                    @foreach([
                        ['Complete an order', '+50'],
                        ['Review a product', '+20'],
                        ['Database tracked', 'Live'],
                    ] as [$label, $value])
                        <div class="lk-earn-item">
                            <strong>
                                {{ $value }}
                            </strong>

                            <p>
                                {{ $label }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="lk-reward-panel">
            <h2>
                Points activity
            </h2>

            <div class="lk-activity-list">
                @forelse($pointActivities as $activity)
                    <div class="lk-activity-row">
                        <div>
                            <strong>
                                {{ $activity['label'] }}
                            </strong>

                            <time>
                                {{ $activity['date'] }}
                            </time>
                        </div>

                        <span class="lk-activity-amount {{ $activity['negative'] ? 'is-negative' : '' }}">
                            {{ $activity['amount'] }}
                        </span>
                    </div>
                @empty
                    <div class="lk-activity-row">
                        <div>
                            <strong>
                                No points activity yet
                            </strong>

                            <small>
                                Completed orders and product reviews will appear here.
                            </small>
                        </div>

                        <span class="lk-activity-amount">
                            0 points
                        </span>
                    </div>
                @endforelse
            </div>
        </section>
    @endif
</div>
@endsection
