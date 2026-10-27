@extends('layouts.logistics.app')
@section('title', 'Delivery Trip Overview - LIKHAE Logistics')
@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-bold uppercase text-primary">Delivery operations</p>
        <h1 class="mt-2 text-2xl font-bold text-ink">Delivery Trip Overview</h1>
        <p class="mt-2 text-sm text-muted">Use Rider Assignment to assign sorted parcels and create or fill a compatible trip. This page is for reviewing trip loads and status.</p>
        <a class="mt-3 inline-flex rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white" href="{{ route('logistics.assignments') }}">Open Rider Assignment</a>
    </header>

    @if(session('status'))
        <div class="border border-green-200 bg-green-50 p-4 text-sm font-semibold text-green-800">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800">{{ $errors->first() }}</div>
    @endif

    @if(!$ready)
        <section class="border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            Delivery trip support is not active until the required trip and custody migrations are installed.
        </section>
    @else
        <div class="overflow-x-auto border border-line bg-surface">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-line bg-gray-50 text-xs uppercase text-muted">
                    <tr>
                        <th class="p-4">Trip</th>
                        <th class="p-4">Rider</th>
                        <th class="p-4">PSGC barangay</th>
                        <th class="p-4">Load</th>
                        <th class="p-4">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse($trips as $trip)
                        <tr>
                            <td class="p-4">
                                <a class="font-semibold text-primary" href="{{ route('logistics.local-trips.show', $trip->id) }}">#{{ $trip->id }}</a>
                                <div class="text-xs text-muted">{{ $trip->trip_type }}</div>
                            </td>
                            <td class="p-4">{{ $trip->rider?->user?->name }}</td>
                            <td class="p-4 font-mono text-xs">{{ $trip->province_code }} / {{ $trip->municipality_code }} / {{ $trip->barangay_code }}</td>
                            <td class="p-4">{{ $trip->load_count }}/{{ $trip->approved_parcel_capacity }}</td>
                            <td class="p-4 font-semibold">{{ str($trip->status)->headline() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-muted">No delivery trips yet. Assign a sorted parcel to create the first trip.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $trips->links() }}
    @endif
</div>
@endsection
