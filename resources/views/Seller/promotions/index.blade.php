@extends('layouts.seller.app')

@section('title', 'Promotions')
@section('active', 'marketing')
@section('subtitle', 'Bundle offers and Buy X Get Y campaigns.')

@section('content')
<div class="sl-page">
    @if(session('status'))<div class="sl-compliance-feedback" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="sl-compliance-feedback is-warning" role="alert">{{ $errors->first() }}</div>@endif
    <div class="sl-page-toolbar">
        <div><a href="{{ route('seller.marketing', ['section' => 'special-offers']) }}" class="sl-editor-back">&larr; Back to Marketing</a><h2>Product Promotions</h2><p>Set discounts that apply when buyers place the required products in their cart.</p></div>
        <a href="{{ route('seller.marketing', ['section' => 'vouchers']) }}" class="sl-btn sl-btn-ghost">Voucher Center</a>
    </div>
    @include('seller.promotions._management', [
        'variants' => $variants,
        'promotions' => $promotions,
        'promotionStorageReady' => $promotionStorageReady,
    ])
</div>
@endsection
