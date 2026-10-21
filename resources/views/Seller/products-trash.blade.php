@extends('layouts.seller.app')

@section('title', 'Recently Deleted Products')
@section('active', 'products')
@section('subtitle', 'Deleted products remain recoverable for 30 days before permanent removal.')

@section('content')
<div class="sl-page">
    <div class="sl-page-toolbar"><div><span class="sl-eyebrow">Product recovery</span><h2>Recently Deleted</h2><p>Restore a product within 30 days of deletion.</p></div><a href="{{ route('seller.products') }}" class="sl-btn sl-btn-ghost">Back to Products</a></div>
    @if(session('status'))<div class="sl-alert sl-alert-success" role="status">{{ session('status') }}</div>@endif
    <section class="sl-card"><div class="sl-table-wrap"><table class="sl-table"><thead><tr><th>Product</th><th>Deleted</th><th>Permanent deletion</th><th></th></tr></thead><tbody>
        @forelse($products as $product)<tr><td><strong>{{ $product->name }}</strong><small>{{ $product->slug }}</small></td><td>{{ $product->deleted_at?->format('M d, Y g:i A') }}</td><td>{{ $product->deleted_at?->copy()->addDays(30)->format('M d, Y g:i A') }}</td><td><form method="POST" action="{{ route('seller.products.restore', $product->id) }}">@csrf<button class="sl-btn sl-btn-primary sl-btn-sm">Restore</button></form></td></tr>
        @empty<tr><td colspan="4" class="p-8 text-center">No recently deleted products.</td></tr>@endforelse
    </tbody></table></div><div class="sl-card-body">{{ $products->links() }}</div></section>
</div>
@endsection
