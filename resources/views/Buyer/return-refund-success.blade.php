@extends('layouts.buyer.app')
@section('title', 'Request Submitted')
@section('active', 'orders')
@section('content')
<div class="mx-auto w-full max-w-2xl">
    <section class="rounded-2xl border border-[#eadbce] bg-[#fffdf9] p-8 text-center shadow-sm dark:border-[#4b3831] dark:bg-[#241b18]">
        <div class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-green-100 text-3xl text-green-700 dark:bg-green-950 dark:text-green-300">✓</div>
        <span class="mt-5 block text-xs font-bold uppercase tracking-widest text-[#8f382d]">Request submitted</span>
        <h1 class="mt-2 text-3xl font-bold text-stone-950 dark:text-white">We received your request</h1>
        <p class="mt-2 text-sm text-stone-600 dark:text-stone-300">Your request was recorded. You can track its stored status below; updates will be sent to {{ $requestRecord->buyer_email }}.</p>
        <dl class="mx-auto mt-6 grid max-w-md gap-3 rounded-xl bg-[#fff7f2] p-4 text-left text-sm dark:bg-[#30211d] sm:grid-cols-2"><div><dt class="text-stone-500 dark:text-stone-300">Request ID</dt><dd class="font-bold">{{ $requestRecord->request_number }}</dd></div><div><dt class="text-stone-500 dark:text-stone-300">Status</dt><dd class="font-bold">{{ str($requestRecord->status)->headline() }}</dd></div><div><dt class="text-stone-500 dark:text-stone-300">Solution</dt><dd class="font-bold">{{ $requestRecord->solution }}</dd></div><div><dt class="text-stone-500 dark:text-stone-300">Estimated refund</dt><dd class="font-bold">₱{{ number_format((float) $requestRecord->requested_amount, 2) }}</dd></div></dl>
        <div class="mt-7 flex flex-wrap justify-center gap-2"><a class="lk-btn lk-btn-red" href="{{ route('buyer.returns') }}">View Returns & Refunds</a><a class="lk-btn lk-btn-light" href="{{ route('buyer.orders.show', $order) }}">Back to Purchase</a></div>
    </section>
</div>
@endsection
