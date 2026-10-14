@extends('Logistics.app')

@section('title', 'Rider Application Review - LIKHAE Logistics')

@php
    $data = $rider ?? [];
    $status = data_get($data, 'status', 'Pending Approval');
    $statusClass = match ($status) {
        'Approved' => 'bg-success-soft text-success',
        'Rejected' => 'bg-danger-soft text-danger',
        default => 'bg-warning-soft text-warning',
    };
    $fullName = data_get($data, 'name', 'Rider Applicant');

    $details = [
        ['Full Name', $fullName],
        ['Email', data_get($data, 'email', 'Not recorded')],
        ['Contact Number', data_get($data, 'contact', 'Not recorded')],
        ['Address', data_get($data, 'address', 'Not recorded')],
        ['Vehicle Type', data_get($data, 'vehicle', 'Not recorded')],
        ['Plate Number', data_get($data, 'plate', 'Not recorded')],
        ['Delivery Area', data_get($data, 'area', 'Unassigned')],
        ['Submitted', data_get($data, 'submitted', 'Not recorded')],
    ];
@endphp

@section('content')
<div class="flex w-full flex-col gap-6">
    @if(session('success'))
        <div class="rounded-xl border border-success/30 bg-success-soft p-4 text-[10px] font-semibold text-success">
            {{ session('success') }}
        </div>
    @endif

    <nav class="flex flex-wrap items-center gap-2 text-[10px] text-muted">
        <a href="{{ route('logistics.dashboard') }}" class="transition hover:text-primary">Dashboard</a>
        <span>/</span>
        <a href="{{ route('logistics.riders.applications') }}" class="transition hover:text-primary">Rider Applications</a>
        <span>/</span>
        <span class="font-semibold text-ink">{{ $fullName }}</span>
    </nav>

    <section class="flex flex-col gap-5 rounded-xl border border-line bg-surface p-5 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Courier Registration</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink sm:text-[38px]">Rider Application</h1>
            <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">
                Review the applicant profile from the user record before making an approval decision.
            </p>
        </div>
        <span class="rounded-full px-3 py-1 text-[8px] font-semibold {{ $statusClass }}">
            {{ $status }}
        </span>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Submitted Information</h2>
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($details as $detail)
                <div class="rounded-lg bg-page-secondary p-4">
                    <span class="text-[8px] uppercase text-muted">{{ $detail[0] }}</span>
                    <strong class="mt-2 block break-words text-[10px] font-semibold text-ink">{{ $detail[1] }}</strong>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-line bg-surface p-5">
        <h2 class="text-[13px] font-semibold text-ink">Documents</h2>
        <p class="mt-2 text-[10px] leading-6 text-muted">
            No storage-backed rider document fields are exposed by the current controller payload. This page does not display fake document previews.
        </p>
    </section>

    @if($status === 'Pending Approval')
        <section class="flex flex-col gap-4 rounded-xl border border-line bg-surface p-5 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-[13px] font-semibold text-ink">Application Decision</h2>
                <p class="mt-1 text-[9px] text-muted">Approval and rejection update the rider account in the database.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('logistics.riders.approve', data_get($data, 'id')) }}" data-confirm-title="Approve rider application?" data-confirm-message="This will activate the rider account." data-confirm-label="Approve">
                    @csrf
                    <button type="submit" class="h-10 rounded-lg bg-success px-4 text-[10px] font-semibold text-white">Approve Application</button>
                </form>
                <form method="POST" action="{{ route('logistics.riders.reject', data_get($data, 'id')) }}" data-confirm-title="Reject rider application?" data-confirm-message="This rider application will be rejected." data-confirm-label="Reject" data-confirm-tone="danger">
                    @csrf
                    <button type="submit" class="h-10 rounded-lg border border-danger/30 bg-surface px-4 text-[10px] font-semibold text-danger">Reject Application</button>
                </form>
            </div>
        </section>
    @endif
</div>
@endsection
