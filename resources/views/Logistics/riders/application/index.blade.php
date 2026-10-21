@extends('layouts.logistics.app')

@section('title', 'Rider Applications - LIKHAE Logistics')

@php
    $riders = $logisticsRiderApplications ?? [];
    $summary = $logisticsRiderSummary ?? [
        ['label' => 'Total Applications', 'value' => 0, 'description' => 'All rider registrations', 'tone' => 'primary', 'icon' => 'applications'],
        ['label' => 'Pending', 'value' => 0, 'description' => 'Awaiting verification', 'tone' => 'warning', 'icon' => 'clock'],
        ['label' => 'Approved', 'value' => 0, 'description' => 'Verified rider accounts', 'tone' => 'success', 'icon' => 'check'],
        ['label' => 'Rejected', 'value' => 0, 'description' => 'Applications declined', 'tone' => 'danger', 'icon' => 'x'],
    ];
@endphp

@section('content')
<div class="flex w-full flex-col gap-6">
    <section class="rounded-xl border border-line bg-surface p-5">
        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Rider Management</span>
        <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink sm:text-[38px]">Rider Applications</h1>
        <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">
            Review rider registration requests from Rider accounts.
        </p>
    </section>

    <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach($summary as $item)
            <article class="rounded-xl border border-line bg-surface p-4">
                <span class="text-[9px] font-semibold uppercase tracking-[0.08em] text-muted">{{ $item['label'] }}</span>
                <strong class="mt-2 block text-[24px] font-bold text-ink">{{ $item['value'] }}</strong>
                <p class="mt-1 text-[9px] text-muted">{{ $item['description'] }}</p>
            </article>
        @endforeach
    </section>

    <section class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="border-b border-line px-5 py-4">
            <h2 class="text-[13px] font-semibold text-ink">Rider Applicants</h2>
        </div>

        @forelse($riders as $rider)
            <article class="flex flex-col gap-4 border-b border-line p-5 last:border-b-0 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <strong class="block text-[11px] font-semibold text-ink">{{ $rider['name'] }}</strong>
                        <span class="rounded-full bg-primary-soft px-3 py-1 text-[8px] font-semibold text-primary">{{ $rider['status'] }}</span>
                    </div>
                    <p class="mt-1 text-[9px] text-muted">{{ $rider['email'] }}</p>
                    <p class="mt-1 text-[8px] text-muted">{{ $rider['vehicle'] }} · {{ $rider['plate'] }} · {{ $rider['center'] }} · Submitted {{ $rider['submitted'] }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('logistics.riders.application.show', $rider['id']) }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">Review application</a>
                </div>
            </article>
        @empty
            <div class="p-5 text-[10px] text-muted">No rider applications are available.</div>
        @endforelse
    </section>
</div>
@endsection
