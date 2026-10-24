@extends('layouts.admin.app')

@section('title', 'Shipping Rate Requests')
@section('subtitle', 'Review Logistics rate requests and manage shipping fee rules.')
@section('active', 'shipping-rate-requests')

@section('content')
@php($transferType = old('transfer_commission_type', $shippingSettings['transfer_commission_type']))
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

    <nav class="ad-rate-page-tabs" aria-label="Shipping rate administration sections">
        @foreach([
            'requests' => 'Requests & allocation',
            'transfer-commission' => 'Transfer commission',
            'regional-tiers' => 'Regional shipping tiers',
        ] as $tabKey => $tabLabel)
            <a class="ad-rate-page-tab {{ $tab === $tabKey ? 'is-active' : '' }}"
               href="{{ route('admin.shipping-rate-requests', ['tab' => $tabKey, 'status' => $tabKey === 'requests' ? $status : null]) }}"
               @if($tab === $tabKey) aria-current="page" @endif>{{ $tabLabel }}</a>
        @endforeach
    </nav>

    @if($tab === 'requests')
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
                    <a class="ad-rate-filter-tab {{ $status === $filter ? 'is-active' : '' }}" href="{{ route('admin.shipping-rate-requests', ['tab' => 'requests', 'status' => $filter]) }}" @if($status === $filter) aria-current="page" @endif>
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
    @elseif($tab === 'transfer-commission')
        <section class="ad-card ad-platform-card ad-rate-settings-card" aria-labelledby="transfer-commission-title">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Logistics</span>
                    <h2 id="transfer-commission-title">Transfer commission</h2>
                    <p>Choose how much Logistics pays from each sealed manifest.</p>
                </div>
            </header>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.transfer-commission.update') }}" class="ad-platform-card__body ad-rate-settings-form">
                @csrf
                @method('PATCH')
                <label class="ad-field">
                    <span>Fee type</span>
                    <select id="transfer-commission-type" name="transfer_commission_type">
                        <option value="PERCENT" @selected($transferType === 'PERCENT')>Percentage of shipping fees</option>
                        <option value="FIXED" @selected($transferType === 'FIXED')>Fixed amount per parcel</option>
                    </select>
                </label>
                <label class="ad-field">
                    <span data-transfer-value-label>Commission rate</span>
                    <span class="ad-platform-money-input">
                        <input id="transfer-commission-value" type="number" name="transfer_commission_value" min="0" max="{{ $transferType === 'PERCENT' ? '100' : '10000' }}" step="0.01" value="{{ old('transfer_commission_value', $shippingSettings['transfer_commission_value']) }}" required>
                        <span data-transfer-value-unit>{{ $transferType === 'PERCENT' ? '%' : 'PHP' }}</span>
                    </span>
                    <small data-transfer-value-help>
                        @if($transferType === 'PERCENT')
                            This percentage is based on the shipping fees of parcels in the manifest.
                        @else
                            This amount is charged once for each parcel in the manifest.
                        @endif
                    </small>
                    @error('transfer_commission_value')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>
                <p class="ad-platform-note">The selected rule is saved with each manifest when it is sealed. Later setting changes do not alter sealed manifests.</p>
                <div class="ad-platform-settings__actions">
                    <p>Changes apply to new manifests only.</p>
                    <button class="ad-btn ad-btn-primary" type="submit">Save transfer commission</button>
                </div>
            </form>
        </section>
    @else
        <section class="ad-card ad-platform-card ad-rate-settings-card" aria-labelledby="regional-tiers-title">
            <header class="ad-card-head">
                <div>
                    <span class="ad-overline">Shipping</span>
                    <h2 id="regional-tiers-title">Regional shipping tiers</h2>
                    <p>Add a fee when a parcel travels between different regions.</p>
                </div>
            </header>
            <form method="POST" action="{{ route('admin.shipping-rate-requests.regional-tiers.update') }}" class="ad-platform-card__body ad-rate-settings-form">
                @csrf
                @method('PATCH')
                <label class="ad-field">
                    <span>Additional fee per tier</span>
                    <span class="ad-platform-money-input ad-platform-money-input--prefix">
                        <span aria-hidden="true">PHP</span>
                        <input type="number" name="additional_region_tier_fee" min="0" max="1000000" step="0.01" value="{{ old('additional_region_tier_fee', $shippingSettings['additional_region_tier_fee']) }}" required>
                    </span>
                    <small>This amount is multiplied by the route’s tier and added to the base shipping fee.</small>
                    @error('additional_region_tier_fee')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>

                <label class="ad-field ad-platform-region-map">
                    <span>Region pair map</span>
                    <textarea name="shipping_region_tiers" rows="5" maxlength="10000" spellcheck="false" placeholder="National Capital Region | Central Luzon | 1&#10;Central Luzon | CALABARZON | 2">{{ old('shipping_region_tiers', $shippingSettings['shipping_region_tiers']) }}</textarea>
                    <small>One route per line: <code>Origin Region | Destination Region | Tier</code>. Use official region names and a tier from 1 to 20.</small>
                    @error('shipping_region_tiers')<small class="ad-platform-field-error">{{ $message }}</small>@enderror
                </label>

                <div class="ad-platform-note ad-platform-note--soft">
                    <strong>How tiers work</strong>
                    <p>Same-region routes use the base fee. Cross-region pairs work in either direction. For example, PHP 6 per tier means a tier 2 route adds PHP 12. The system does not infer distance from region codes.</p>
                </div>
                <div class="ad-platform-settings__actions">
                    <p>Changes apply to new checkouts. Existing snapshots remain as saved.</p>
                    <button class="ad-btn ad-btn-primary" type="submit">Save shipping tiers</button>
                </div>
            </form>
        </section>
    @endif
</div>
@endsection

@push('scripts')
<script>
    const transferType = document.getElementById('transfer-commission-type');
    const transferValue = document.getElementById('transfer-commission-value');
    const transferUnit = document.querySelector('[data-transfer-value-unit]');
    const transferValueLabel = document.querySelector('[data-transfer-value-label]');
    const transferValueHelp = document.querySelector('[data-transfer-value-help]');

    function updateTransferCommissionFields() {
        if (!transferType || !transferValue || !transferUnit || !transferValueLabel || !transferValueHelp) return;
        const percentage = transferType.value === 'PERCENT';
        transferValue.max = percentage ? '100' : '10000';
        transferUnit.textContent = percentage ? '%' : 'PHP';
        transferValueLabel.textContent = percentage ? 'Commission rate' : 'Amount per parcel';
        transferValueHelp.textContent = percentage
            ? 'This percentage is based on the shipping fees of parcels in the manifest.'
            : 'This amount is charged once for each parcel in the manifest.';
    }

    transferType?.addEventListener('change', updateTransferCommissionFields);
    updateTransferCommissionFields();
</script>
@endpush
