@extends('layouts.buyer')

@section('content')
<div class="mx-auto max-w-3xl px-5 py-10">
    <p class="text-xs font-bold uppercase text-red-800">Seller application</p>
    <h1 class="mt-2 text-3xl font-bold">Open a shop</h1>
    <p class="mt-2 text-stone-500">Seller accounts use the verified fixed-role registration flow. Your shop remains unavailable until administrator approval.</p>

    <div class="mt-8 border border-stone-200 bg-white p-6 shadow-sm">
        <a class="inline-flex bg-red-900 px-5 py-3 font-semibold text-white" href="{{ route('register', ['role' => 'seller']) }}">Continue to seller registration</a>
    </div>
</div>
@endsection
