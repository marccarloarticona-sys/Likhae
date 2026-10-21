@extends('layouts.buyer.app')
@section('title', 'Returns & Refunds')
@section('active', 'orders')
@section('content')
<div class="mx-auto w-full max-w-5xl">
    <div class="lk-page-title mb-5"><div><span class="lk-kicker">Order support</span><h1>Returns & Refunds</h1><p>Track Seller reviews, return custody, inspection, and cash-refund status.</p></div><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders') }}">My Orders</a></div>
    <div class="grid gap-4">
        @forelse($requests as $returnRequest)
            <article class="rounded-2xl border border-[#eadbce] bg-[#fffdf9] p-5 shadow-sm dark:border-[#4b3831] dark:bg-[#241b18]"><div class="flex flex-wrap items-start justify-between gap-3"><div><span class="text-xs font-bold uppercase tracking-widest text-[#8f382d]">{{ $returnRequest->request_number }}</span><h2 class="mt-1 text-lg font-bold text-stone-950 dark:text-white">Order {{ $returnRequest->order?->order_number }}</h2><p class="mt-1 text-sm text-stone-500 dark:text-stone-300">Submitted {{ $returnRequest->submitted_at?->format('M d, Y g:i A') }}</p></div><span class="rounded-full bg-[#fff0e8] px-3 py-1 text-xs font-bold text-[#8f382d] dark:bg-[#4b2f28]">{{ str($returnRequest->status)->headline() }}</span></div><div class="mt-4 grid gap-3 sm:grid-cols-3"><div><small class="block text-stone-500">Seller(s)</small><strong>{{ $returnRequest->sellerRequests->pluck('sellerProfile.business_name')->filter()->join(', ') ?: 'Seller review pending' }}</strong></div><div><small class="block text-stone-500">Requested refund</small><strong>PHP {{ number_format((float)$returnRequest->requested_amount,2) }}</strong></div><div><small class="block text-stone-500">Seller outcomes</small><strong>{{ $returnRequest->sellerRequests->map(fn($item)=>str($item->status)->headline())->join(', ') ?: 'Submitted' }}</strong></div></div><div class="mt-4 flex justify-end"><a class="lk-btn lk-btn-red" href="{{ route('buyer.returns.parent', $returnRequest) }}">View Details</a></div></article>
        @empty
            <section class="rounded-2xl border border-[#eadbce] bg-[#fffdf9] p-8 text-center dark:border-[#4b3831] dark:bg-[#241b18]"><h2 class="text-xl font-bold">No return or refund requests yet</h2><p class="mt-2 text-sm text-stone-500 dark:text-stone-300">When you submit a request, its Seller-scoped status and reference will appear here.</p><a class="lk-btn lk-btn-red mt-5" href="{{ route('buyer.orders') }}">View My Orders</a></section>
        @endforelse
    </div>
    @if($requests->hasPages())<div class="mt-5">{{ $requests->links() }}</div>@endif
</div>
@endsection
