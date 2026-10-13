@extends('layouts.seller')

@php($mode = $mode ?? 'courier')
@section('title', $mode === 'courier' ? 'Courier Dashboard' : ucwords(str_replace('-', ' ', $mode)))
@section('active', $mode)
@section('subtitle', 'Manage your pickup and delivery assignments from one workspace.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar">
        <div><span class="sl-eyebrow">Rider workspace</span><h2>Good morning, Rider</h2><p>Your pickup and delivery queue for today.</p></div>
        <div class="sl-toolbar-group"><a class="sl-btn sl-btn-primary" href="{{ route('rider.deliveries') }}">Open deliveries</a></div>
    </div>

    <section class="sl-stat-grid" aria-label="Courier summary">
        <x-seller.stat-card label="Items for Pickup" value="6" change="3 new requests" icon="shipping" />
        <x-seller.stat-card label="Items for Delivery" value="4" change="2 due today" icon="orders" />
        <x-seller.stat-card label="Completed This Week" value="28" change="12.5%" icon="revenue" />
        <x-seller.stat-card label="This Week's Earnings" value="₱3,840" change="On track" icon="sales" />
    </section>

    <div class="sl-dashboard-grid">
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Seller pickup</span><h2>Pickup assignments</h2><p>Accept, collect, and scan parcels from sellers.</p></div><a href="{{ route('rider.pickups') }}" class="sl-text-link">View pickup queue</a></header><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Parcel</th><th>Seller</th><th>Area</th><th>Status</th><th></th></tr></thead><tbody>
            @foreach ([['#1004','Metro Finds PH','Makati','New request','is-warning'],['#1005','Urban Carry Co.','Quezon City','Accepted','is-info'],['#1006','Stride PH','Pasig','To confirm','is-neutral']] as $pickup)
                <tr><td><strong>{{ $pickup[0] }}</strong></td><td>{{ $pickup[1] }}</td><td>{{ $pickup[2] }}</td><td><span class="sl-status {{ $pickup[4] }}">{{ $pickup[3] }}</span></td><td><a class="sl-icon-link" href="{{ route('rider.pickups') }}" aria-label="Open pickup">→</a></td></tr>
            @endforeach
        </tbody></table></div></section>
        <section class="sl-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Sorting center</span><h2>Delivery assignments</h2><p>Collect sorted parcels and deliver to buyers.</p></div><a href="{{ route('rider.deliveries') }}" class="sl-text-link">View delivery queue</a></header><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Parcel</th><th>Destination</th><th>Status</th></tr></thead><tbody>
            @foreach ([['#0998','Santa Cruz, Laguna','Out for delivery','is-info'],['#0999','Los Baños, Laguna','Ready to collect','is-warning'],['#1000','Calamba, Laguna','At sorting center','is-neutral']] as $delivery)
                <tr><td><strong>{{ $delivery[0] }}</strong></td><td>{{ $delivery[1] }}</td><td><span class="sl-status {{ $delivery[3] }}">{{ $delivery[2] }}</span></td></tr>
            @endforeach
        </tbody></table></div></section>
    </div>

    <section class="sl-card sl-flow-card"><header class="sl-card-head"><div><span class="sl-eyebrow">Delivery workflow</span><h2>Keep every parcel moving</h2><p>Follow the same operational stages across pickup and delivery.</p></div></header><div class="sl-process-flow">
        @foreach ([['1','Accept pickup','Review seller request'],['2','Scan parcel','Confirm handover'],['3','At sorting center','Deliver to hub'],['4','Out for delivery','Start route'],['5','Delivered','Record outcome']] as $step)
            <div class="sl-process-step"><span>{{ $step[0] }}</span><div><strong>{{ $step[1] }}</strong><small>{{ $step[2] }}</small></div></div>
        @endforeach
    </div></section>
</div>
@endsection
