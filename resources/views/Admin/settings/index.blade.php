@extends('layouts.admin.app')
@section('title','Platform Settings')
@section('subtitle','Platform configuration and policies.')
@section('active','settings')
@section('content')
<div style="display:grid;gap:16px">
    <div class="ad-page-head"><div><span class="ad-overline">System</span><h2>Platform Settings</h2><p>The PDF-defined platform commission remains fixed at 10%.</p></div></div>
    <section class="ad-card ad-card-body">
        <form method="POST" action="{{ route('admin.settings.update') }}" style="display:grid;gap:12px">
            @csrf @method('PATCH')
            <label class="ad-field"><span>Platform Name</span><input name="platform_name" value="{{ $settings['platform_name'] }}" required readonly></label>
            <label class="ad-field"><span>Support Email</span><input type="email" name="support_email" value="{{ $settings['support_email'] }}" readonly></label>
            <label class="ad-field"><span>Commission Rate</span><input type="number" step="0.01" name="commission_rate" value="{{ $settings['commission_rate'] }}" readonly></label>
            <label><input type="hidden" name="registration_enabled" value="0"><input type="checkbox" name="registration_enabled" value="1" @checked($settings['registration_enabled'])> Enable registration</label>
            <fieldset style="display:grid;gap:10px;border:1px solid var(--ad-border);padding:14px">
                <legend style="padding:0 6px;font-weight:700">Logistics Transfer Commission</legend>
                <p style="margin:0;color:var(--ad-muted);font-size:10px">Percentage is calculated from the shipping fees of parcels in a manifest. Fixed amount is charged per parcel. The rule is snapshotted when the manifest is sealed.</p>
                <label class="ad-field"><span>Fee type</span><select name="transfer_commission_type"><option value="PERCENT" @selected($settings['transfer_commission_type'] === 'PERCENT')>Percentage of shipping fees</option><option value="FIXED" @selected($settings['transfer_commission_type'] === 'FIXED')>Fixed amount per parcel</option></select></label>
                <label class="ad-field"><span>Rate (%) or amount (PHP)</span><input type="number" name="transfer_commission_value" min="0" max="10000" step="0.01" value="{{ old('transfer_commission_value', $settings['transfer_commission_value']) }}" required></label>
                @error('transfer_commission_value')<p style="margin:0;color:#b42318;font-size:10px">{{ $message }}</p>@enderror
            </fieldset>
            <fieldset style="display:grid;gap:10px;border:1px solid var(--ad-border);padding:14px">
                <legend style="padding:0 6px;font-weight:700">Regional Shipping Tiers</legend>
                <p style="margin:0;color:var(--ad-muted);font-size:10px">Same-region routes use the active base fee. Each configured cross-region tier adds the amount below. Use official region names; pairs work in either direction. No numeric region-code distance is inferred.</p>
                <label class="ad-field"><span>Additional fee per region tier (PHP)</span><input type="number" name="additional_region_tier_fee" min="0" max="1000000" step="0.01" value="{{ old('additional_region_tier_fee', $settings['additional_region_tier_fee']) }}" required></label>
                @error('additional_region_tier_fee')<p style="margin:0;color:#b42318;font-size:10px">{{ $message }}</p>@enderror
                <label class="ad-field"><span>Region pair tier map</span><textarea name="shipping_region_tiers" rows="6" maxlength="10000" placeholder="National Capital Region | Central Luzon | 1&#10;Central Luzon | CALABARZON | 2">{{ old('shipping_region_tiers', $settings['shipping_region_tiers']) }}</textarea><small>One per line: Origin Region | Destination Region | Tier (1–20). The pair is symmetric.</small></label>
                @error('shipping_region_tiers')<p style="margin:0;color:#b42318;font-size:10px">{{ $message }}</p>@enderror
            </fieldset>
            <button class="ad-btn ad-btn-primary">Save Settings</button>
        </form>
    </section>
    <section class="ad-card ad-card-body"><h2>Publish Policy</h2><form method="POST" action="{{ route('admin.policies.store') }}" style="display:grid;gap:10px;margin-top:12px">@csrf<input name="title" required placeholder="Policy title"><input name="version" required placeholder="Version"><textarea name="body" required rows="5" placeholder="Policy content"></textarea><input type="datetime-local" name="effective_at"><button class="ad-btn ad-btn-primary">Publish Policy</button></form><div style="display:grid;gap:8px;margin-top:16px">@foreach($policies as $policy)<article style="border:1px solid var(--ad-border);padding:12px"><strong>{{ $policy->title }} v{{ $policy->version }}</strong><p>{{ $policy->body }}</p></article>@endforeach</div></section>
</div>
@endsection
