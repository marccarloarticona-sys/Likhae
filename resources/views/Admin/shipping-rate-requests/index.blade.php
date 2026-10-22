@extends('layouts.admin.app')

@section('title', 'Shipping Rate Requests')
@section('subtitle', 'Review shipping fee and commission split proposals.')
@section('active', 'shipping-rate-requests')

@section('content')
<div class="ad-page ad-shipping-rate-page">
    @if(session('status'))
        <div role="status" class="ad-rate-flash ad-rate-flash--success">
            <span class="ad-rate-flash__icon" aria-hidden="true">✓</span>
            <p>{{ session('status') }}</p>
        </div>
    @endif

    @if($errors->any())
        <div role="alert" class="ad-rate-flash ad-rate-flash--error">
            <span class="ad-rate-flash__icon" aria-hidden="true">!</span>
            <p>{{ $errors->first() }}</p>
        </div>
    @endif

    <section class="ad-card ad-rate-policy-card" aria-labelledby="shipping-rate-policy-title">
        <header class="ad-rate-policy-card__header">
            <div>
                <span class="ad-overline">GLOBAL rate policy</span>
                <h2 id="shipping-rate-policy-title">Current Approved Shipping Fee</h2>
                <p>New checkout transactions use this global fee. Existing SellerOrder snapshots remain unchanged.</p>
            </div>
            <span class="ad-status {{ $policy['initialized'] ? 'is-approved' : 'is-pending' }}">
                {{ $policy['initialized'] ? 'Active approved policy' : 'Bootstrap fallback' }}
            </span>
        </header>

        <dl class="ad-rate-policy-metrics">
            <div class="ad-rate-policy-metric ad-rate-policy-metric--primary">
                <dt>{{ $policy['initialized'] ? 'Active shipping fee' : 'Fallback shipping fee' }}</dt>
                <dd>PHP {{ number_format((float) $policy['shipping_fee'], 2) }}</dd>
                @unless($policy['initialized'])<small>Used until a global policy is initialized</small>@endunless
            </div>
            <div class="ad-rate-policy-metric">
                <dt>Rider Pool</dt>
                <dd>{{ number_format((float) $policy['rider_pool_percentage'], 2) }}%</dd>
            </div>
            <div class="ad-rate-policy-metric">
                <dt>Logistics</dt>
                <dd>{{ number_format((float) $policy['logistics_percentage'], 2) }}%</dd>
            </div>
            <div class="ad-rate-policy-metric">
                <dt>Effective since</dt>
                <dd class="ad-rate-policy-metric__date">{{ $currentVersion?->effective_from?->format('M j, Y g:i A') ?? 'Not initialized' }}</dd>
            </div>
        </dl>

        @if(! $schemaReady)
            <div class="ad-rate-notice ad-rate-notice--warning" role="status">
                <div>
                    <strong>Rate governance is unavailable</strong>
                    <p>The rate-governance migration is not installed. Checkout continues to use the PHP {{ number_format((float) $policy['shipping_fee'], 2) }} config fallback.</p>
                </div>
            </div>
        @elseif(! $policy['initialized'])
            <div class="ad-rate-notice ad-rate-notice--info">
                <div>
                    <strong>One-time initialization required</strong>
                    <p>No production rate row was inserted by the migration. Initialize the current PHP {{ number_format((float) $policy['shipping_fee'], 2) }} fee with the {{ number_format((float) $policy['rider_pool_percentage'], 2) }}/{{ number_format((float) $policy['logistics_percentage'], 2) }} Rider/Logistics allocation.</p>
                </div>
                <form method="POST" action="{{ route('admin.shipping-rate-requests.initialize') }}" onsubmit="return confirm('Initialize the current GLOBAL rate at PHP {{ number_format((float) $policy['shipping_fee'], 2) }} with Rider {{ number_format((float) $policy['rider_pool_percentage'], 2) }}% / Logistics {{ number_format((float) $policy['logistics_percentage'], 2) }}%? Checkout will continue at the same fee.')">
                    @csrf
                    <button class="ad-btn ad-btn-primary" type="submit">Initialize Current Global Rate</button>
                </form>
            </div>
        @endif
    </section>

    @if($schemaReady && $policy['initialized'])
        <section class="ad-card ad-card-body ad-rate-split-card" aria-labelledby="shipping-split-title">
            <header class="ad-rate-section-heading">
                <div>
                    <span class="ad-overline">Shipping commission split</span>
                    <h2 id="shipping-split-title">Set Logistics Share</h2>
                    <p>Applies to shipping fees only. Riders receive the remainder. Existing orders keep their saved rate version.</p>
                </div>
                <div class="ad-rate-current-split">
                    <span>Current allocation</span>
                    <strong>Rider {{ number_format((float) $policy['rider_pool_percentage'], 2) }}% <i aria-hidden="true">·</i> Logistics {{ number_format((float) $policy['logistics_percentage'], 2) }}%</strong>
                </div>
            </header>

            <form method="POST" action="{{ route('admin.shipping-rate-requests.commission-split') }}" class="ad-rate-split-form" onsubmit="return confirm('Activate this shipping split for new orders?')">
                @csrf
                <label class="ad-field ad-rate-split-form__field">
                    <span>Logistics share</span>
                    <input type="number" name="logistics_percentage" min="0" max="100" step="0.0001" value="{{ old('logistics_percentage', $policy['logistics_percentage']) }}" required>
                </label>
                <div class="ad-rate-rider-remainder">
                    <span>Rider Pool receives</span>
                    <strong>{{ number_format(100 - (float) old('logistics_percentage', $policy['logistics_percentage']), 2) }}%</strong>
                </div>
                <button class="ad-btn ad-btn-primary" type="submit">Save Shipping Split</button>
            </form>
            @error('logistics_percentage')<p role="alert" class="ad-rate-field-error">{{ $message }}</p>@enderror
        </section>
    @endif

    <section class="ad-card ad-card-body ad-rate-requests-card" aria-labelledby="shipping-rate-requests-title">
        <header class="ad-rate-requests-header">
            <div>
                <span class="ad-overline">Logistics proposals</span>
                <h2 id="shipping-rate-requests-title">Shipping Rate Requests</h2>
                <p>Review requested changes to the global shipping rate and allocation.</p>
            </div>
            @if($schemaReady)
                <span class="ad-rate-request-count">{{ method_exists($requests, 'total') ? $requests->total() : $requests->count() }} requests</span>
            @endif
        </header>

        @if($schemaReady)
            <nav class="ad-rate-filter-tabs" aria-label="Filter shipping rate requests by status">
                @foreach(['PENDING', 'APPROVED', 'REJECTED', 'CANCELLED', 'SUPERSEDED', 'ALL'] as $filter)
                    <a class="ad-rate-filter-tab {{ $status === $filter ? 'is-active' : '' }}" href="{{ route('admin.shipping-rate-requests', ['status' => $filter]) }}" @if($status === $filter) aria-current="page" @endif>
                        {{ str($filter)->headline() }}
                    </a>
                @endforeach
            </nav>

            <div class="ad-table-wrap ad-rate-table-wrap">
                <table class="ad-table ad-rate-table">
                    <thead>
                        <tr>
                            <th scope="col">Logistics</th>
                            <th scope="col">Current</th>
                            <th scope="col">Requested</th>
                            <th scope="col">Change</th>
                            <th scope="col">Reason</th>
                            <th scope="col">Requested at</th>
                            <th scope="col">Status</th>
                            <th scope="col"><span class="sr-only">Action</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($requests as $rateRequest)
                            <tr>
                                <td>
                                    <strong>{{ $rateRequest->requester?->logisticsCenter?->business_name ?? $rateRequest->requester?->name ?? 'Logistics account' }}</strong>
                                </td>
                                <td>
                                    @if($rateRequest->requested_rider_pool_percentage !== null)
                                        <span class="ad-rate-allocation"><b>Rider</b> {{ number_format((float) $rateRequest->current_rider_pool_percentage, 2) }}%</span>
                                        <span class="ad-rate-allocation"><b>Logistics</b> {{ number_format(100 - (float) $rateRequest->current_rider_pool_percentage, 2) }}%</span>
                                    @else
                                        <strong>PHP {{ number_format((float) $rateRequest->current_shipping_fee, 2) }}</strong>
                                    @endif
                                </td>
                                <td>
                                    @if($rateRequest->requested_rider_pool_percentage !== null)
                                        <span class="ad-rate-allocation"><b>Rider</b> {{ number_format((float) $rateRequest->requested_rider_pool_percentage, 2) }}%</span>
                                        <span class="ad-rate-allocation"><b>Logistics</b> {{ number_format(100 - (float) $rateRequest->requested_rider_pool_percentage, 2) }}%</span>
                                    @else
                                        <strong>PHP {{ number_format((float) $rateRequest->requested_shipping_fee, 2) }}</strong>
                                    @endif
                                </td>
                                <td>
                                    @if($rateRequest->requested_rider_pool_percentage !== null)
                                        @php($shareChange = (float) $rateRequest->requested_rider_pool_percentage - (float) $rateRequest->current_rider_pool_percentage)
                                        Rider share <strong>{{ $shareChange > 0 ? '+' : '' }}{{ number_format($shareChange, 2) }} pts</strong>
                                    @else
                                        {{ str($rateRequest->change_type)->headline() }}<br>
                                        <strong>PHP {{ number_format(abs((float) $rateRequest->change_amount), 2) }}</strong>
                                        <small>{{ number_format((float) $rateRequest->change_percentage, 2) }}%</small>
                                    @endif
                                </td>
                                <td class="ad-rate-reason">
                                    {{ \App\Models\Finance\ShippingRateChangeRequest::REASONS[$rateRequest->reason_code] ?? $rateRequest->reason_code }}
                                    @if($rateRequest->reason_details)<small>{{ $rateRequest->reason_details }}</small>@endif
                                </td>
                                <td class="ad-rate-requested-at">
                                    @if($rateRequest->created_at)
                                        <time datetime="{{ $rateRequest->created_at->toIso8601String() }}">{{ $rateRequest->created_at->format('M j, Y') }}</time>
                                        <small>{{ $rateRequest->created_at->format('g:i A') }}</small>
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td><span class="ad-status is-{{ strtolower($rateRequest->status) }}">{{ str($rateRequest->status)->headline() }}</span></td>
                                <td><a class="ad-btn ad-btn-secondary ad-btn-sm" href="{{ route('admin.shipping-rate-requests.show', $rateRequest) }}">Review</a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="ad-rate-empty-cell">
                                    <div class="ad-rate-empty-state">
                                        <span class="ad-rate-empty-state__icon" aria-hidden="true">—</span>
                                        <strong>No {{ $status === 'ALL' ? '' : str($status)->lower().' ' }}requests in this view</strong>
                                        <p>Shipping rate proposals from Logistics operators will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if(method_exists($requests, 'links'))
                <div class="ad-rate-pagination">{{ $requests->links() }}</div>
            @endif
        @else
            <div class="ad-rate-empty-state ad-rate-empty-state--unavailable" role="status">
                <strong>Requests cannot be loaded yet</strong>
                <p>Install the rate-governance migration to review and manage Logistics proposals.</p>
            </div>
        @endif
    </section>
</div>
@endsection
