@extends('layouts.rider.app')
@section('title', 'Inter-Hub Transfers - LIKHAE')
@section('content')
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-ink">Inter-hub transfers</h1>
        <p class="mt-2 text-sm text-muted">Only manifests assigned to your approved inter-logistics vehicle appear here. Destination custody stays with the receiving hub.</p>
    </header>
    @if(session('status'))<div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-700">{{ session('status') }}</div>@endif
    @error('tracking')<div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">{{ $message }}</div>@enderror
    <x-shared.parcel-scanner
        :action="route('rider.scan', ['return_to' => 'transfers'])"
        :tracking="old('tracking', request('tracking', ''))"
        :method="request('scan_method', 'MANUAL')"
        title="Scan Inter-Hub Parcel"
        description="Find a parcel in a transfer manifest assigned to you. Scanning opens the manifest and does not change parcel custody."
        button="Find Assigned Manifest"
    />
    @if(!$ready)
        <div class="border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Inter-hub transport is not activated until Step 3/4 custody migrations are approved and installed.</div>
    @else
        <div class="grid gap-3">
            @forelse($manifests as $manifest)
                <a class="border border-line bg-surface p-4" href="{{ route('rider.transfers.show', $manifest->id) }}">
                    <div class="flex justify-between"><strong>{{ $manifest->manifest_number }}</strong><span>{{ str($manifest->status)->headline() }}</span></div>
                    <p class="mt-2 text-sm text-muted">{{ $manifest->originLogisticsCenter?->business_name }} &rarr; {{ $manifest->destinationLogisticsCenter?->business_name }}</p>
                </a>
            @empty
                <div class="border border-dashed border-line p-8 text-center text-muted">No assigned transfer manifests.</div>
            @endforelse
        </div>
        {{ $manifests->links() }}
    @endif
</div>
@endsection
