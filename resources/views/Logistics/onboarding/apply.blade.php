@extends('layouts.buyer')

@section('content')
<div class="mx-auto max-w-3xl px-5 py-10">
    <p class="text-xs font-bold uppercase text-red-800">Logistics partnership</p>
    <h1 class="mt-2 text-3xl font-bold">Register a logistics center</h1>
    <p class="mt-2 text-stone-500">Logistics accounts use the same verified registration flow as every other fixed-role account. Administrator approval is required before access is granted.</p>

    <div class="mt-8 border border-stone-200 bg-white p-6 shadow-sm">
        <a class="inline-flex bg-red-900 px-5 py-3 font-semibold text-white" href="{{ route('register', ['role' => 'logistics']) }}">Continue to logistics registration</a>
    </div>
</div>
@endsection
