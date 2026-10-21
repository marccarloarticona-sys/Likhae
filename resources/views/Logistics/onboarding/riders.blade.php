@extends('layouts.logistics.app')

@section('title', 'Riders')

@section('content')
<div class="space-y-6">
    <div>
        <p class="text-xs font-bold uppercase text-primary">Team access</p>
        <h1 class="text-2xl font-bold">Rider accounts</h1>
        <p class="mt-2 max-w-2xl text-sm text-muted">Riders now register their own verified account and select a logistics center. Use the canonical rider management pages below; this compatibility page does not create accounts or bypass approval.</p>
    </div>

    <div class="flex flex-wrap gap-3">
        <a class="likhae-btn-primary" href="{{ route('logistics.riders') }}">Rider accounts</a>
        <a class="likhae-btn-secondary" href="{{ route('logistics.riders.applications') }}">Rider applications</a>
    </div>
</div>
@endsection
