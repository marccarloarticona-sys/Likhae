@extends('layouts.logistics.app')

@section('title', 'Rider Application Review - LIKHAE Logistics')

@php
    $data = $rider ?? [];
    $status = data_get($data, 'status', 'Pending');
    $statusClass = match ($status) {
        'Approved' => 'bg-success-soft text-success',
        'Rejected' => 'bg-danger-soft text-danger',
        default => 'bg-warning-soft text-warning',
    };
    $fullName = data_get($data, 'name', 'Rider Applicant');
    $latestDocumentIds = $application->documents
        ->groupBy(fn ($document) => strtoupper((string) $document->document_type))
        ->map(fn ($versions) => (int) $versions->sortByDesc('id')->first()->id);

    $details = [
        ['Full Name', $fullName],
        ['Email', data_get($data, 'email', 'Not recorded')],
        ['Contact Number', data_get($data, 'contact', 'Not recorded')],
        ['Address', data_get($data, 'address', 'Not recorded')],
        ['Vehicle Type', data_get($data, 'vehicle', 'Not recorded')],
        ['Plate Number', data_get($data, 'plate', 'Not recorded')],
        ["Driver's License Number", data_get($data, 'license', 'Not recorded')],
        ['Sorting Center', data_get($data, 'center', 'Not recorded')],
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
    @if($errors->any())
        <div class="rounded-xl border border-danger/30 bg-danger-soft p-4 text-[10px] font-semibold text-danger" role="alert">
            {{ $errors->first() }}
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
            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Rider Registration</span>
            <h1 class="mt-2 font-display text-[32px] font-semibold tracking-[-0.04em] text-ink sm:text-[38px]">Rider Application</h1>
            <p class="mt-2 max-w-[680px] text-[11px] leading-6 text-muted">
                Review the submitted information and each document before making an approval decision.
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
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            @forelse($application->documents as $document)
                @php
                    $documentUrl = route('logistics.riders.application.documents.show', [$application, $document]);
                    $previewUrl = $documentUrl.'?preview=1';
                    $mime = strtolower((string) $document->mime_type);
                    $isCurrentSubmission = $latestDocumentIds->get(strtoupper((string) $document->document_type)) === (int) $document->id;
                @endphp
                <article class="rounded-xl border border-line p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-[11px] font-semibold text-ink">{{ str($document->document_type)->replace('_', ' ')->headline() }}</h3>
                            <p class="mt-1 break-all text-[9px] text-muted">{{ $document->original_name ?: 'Submitted document' }}</p>
                            @unless($isCurrentSubmission)<p class="mt-1 text-[9px] text-muted">Previous submission</p>@endunless
                        </div>
                        <a href="{{ $documentUrl }}" class="rounded-lg border border-line px-3 py-2 text-[9px] font-semibold text-ink">Download</a>
                    </div>
                    <p class="mt-2 text-[9px] font-semibold text-muted">{{ str($document->verification_status)->headline() }}</p>
                    <div class="mt-3 flex h-60 items-center justify-center overflow-hidden rounded-lg border border-line bg-page-secondary">
                        @if(str_starts_with($mime, 'image/'))
                            <img src="{{ $previewUrl }}" alt="{{ str($document->document_type)->replace('_', ' ')->headline() }}" class="h-full w-full object-contain">
                        @elseif($mime === 'application/pdf')
                            <iframe src="{{ $previewUrl }}" title="{{ str($document->document_type)->replace('_', ' ')->headline() }}" class="h-full w-full border-0"></iframe>
                        @else
                            <a href="{{ $documentUrl }}" class="text-[10px] font-semibold text-primary">Open document</a>
                        @endif
                    </div>
                    @if($document->rejection_reason)
                        <p class="mt-2 text-[9px] text-danger">Reason: {{ $document->rejection_reason }}</p>
                    @endif
                    @if($isCurrentSubmission && in_array($application->status, ['PENDING', 'UNDER_REVIEW'], true))
                        <div class="mt-3 grid gap-2">
                            <form method="POST" action="{{ route('logistics.riders.application.documents.verify', [$application, $document]) }}">
                                @csrf
                                <button type="submit" class="rounded-lg border border-success/30 px-3 py-2 text-[9px] font-semibold text-success">Mark verified</button>
                            </form>
                            <form method="POST" action="{{ route('logistics.riders.application.documents.reject', [$application, $document]) }}" class="grid gap-2">
                                @csrf
                                <label class="text-[9px] font-semibold text-muted" for="reason-{{ $document->id }}">Document rejection reason</label>
                                <textarea id="reason-{{ $document->id }}" name="reason" minlength="3" maxlength="1000" required class="rounded-lg border border-line p-2 text-[10px]"></textarea>
                                <button type="submit" class="justify-self-start rounded-lg border border-danger/30 px-3 py-2 text-[9px] font-semibold text-danger">Reject document</button>
                            </form>
                        </div>
                    @endif
                </article>
            @empty
                <p class="text-[10px] text-muted">No documents were submitted.</p>
            @endforelse
        </div>
    </section>

    @if(in_array($application->status, ['PENDING', 'UNDER_REVIEW'], true))
        <section class="flex flex-col gap-4 rounded-xl border border-line bg-surface p-5 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-[13px] font-semibold text-ink">Application Decision</h2>
                <p class="mt-1 text-[9px] text-muted">Verify the required documents before approving this rider.</p>
            </div>
            <div class="grid w-full gap-3 md:max-w-md">
                <form method="POST" action="{{ route('logistics.riders.approve', $application) }}" class="grid gap-2">
                    @csrf
                    <label class="text-[9px] font-semibold text-muted" for="decision_notes">Approval notes (optional)</label>
                    <textarea id="decision_notes" name="decision_notes" maxlength="1000" class="rounded-lg border border-line p-2 text-[10px]"></textarea>
                    <button type="submit" class="h-10 rounded-lg bg-success px-4 text-[10px] font-semibold text-white">Approve Application</button>
                </form>
                <form method="POST" action="{{ route('logistics.riders.reject', $application) }}" class="grid gap-2">
                    @csrf
                    <label class="text-[9px] font-semibold text-muted" for="rejection_reason">Application rejection reason</label>
                    <textarea id="rejection_reason" name="reason" minlength="3" maxlength="1000" required class="rounded-lg border border-line p-2 text-[10px]"></textarea>
                    <button type="submit" class="h-10 rounded-lg border border-danger/30 bg-surface px-4 text-[10px] font-semibold text-danger">Reject Application</button>
                </form>
            </div>
        </section>
    @else
        <section class="rounded-xl border border-line bg-surface p-5 text-[10px] text-muted">
            Reviewed {{ $application->reviewed_at?->format('M d, Y h:i A') ?? 'recently' }}.
            @if($application->decision_notes) Notes: {{ $application->decision_notes }} @endif
            @if($application->rejection_reason) Reason: {{ $application->rejection_reason }} @endif
        </section>
    @endif
</div>
@endsection
