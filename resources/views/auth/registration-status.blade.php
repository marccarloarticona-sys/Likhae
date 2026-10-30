@extends('layouts.guest.app')

@section('title', 'Application Status')

@section('content')
@php
    $accountLabel = str($application->user?->account_type ?? 'ACCOUNT')->headline()->toString();
    $isAwaitingReview = in_array($application->status, [
        \App\Models\Auth\RegistrationApplication::STATUS_PENDING,
        \App\Models\Auth\RegistrationApplication::STATUS_UNDER_REVIEW,
    ], true);
@endphp

<section class="mx-auto grid w-full max-w-4xl gap-6 px-4 py-8 sm:px-6 sm:py-12" aria-labelledby="application-status-title">
    @if(session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">
            <p class="font-semibold">We could not submit the replacement.</p>
            <ul class="mt-1 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <header class="rounded-2xl border border-[#eadccc] bg-[#fffdf9] p-6 shadow-sm sm:p-8">
        <p class="text-xs font-bold uppercase tracking-[.16em] text-[#8a5b3c]">{{ $accountLabel }} application</p>
        <h1 id="application-status-title" class="mt-2 text-2xl font-bold text-[#3b211b] sm:text-3xl">Your account is pending approval</h1>
        <p class="mt-3 max-w-2xl text-sm leading-6 text-[#765f51]">Check your submitted documents below. If a reviewer rejected one, upload a replacement so the application can continue.</p>
        <div class="mt-5 flex flex-wrap gap-x-6 gap-y-2 text-sm text-[#765f51]">
            <span>Application <strong class="text-[#3b211b]">{{ $application->application_number }}</strong></span>
            <span>Status <strong class="text-[#3b211b]">{{ str($application->status)->replace('_', ' ')->headline() }}</strong></span>
            @if($application->submitted_at)<span>Submitted <strong class="text-[#3b211b]">{{ $application->submitted_at->format('M d, Y') }}</strong></span>@endif
        </div>
    </header>

    <section class="rounded-2xl border border-[#eadccc] bg-[#fffdf9] p-6 shadow-sm sm:p-8" aria-labelledby="documents-title">
        <div>
            <h2 id="documents-title" class="text-lg font-bold text-[#3b211b]">Submitted documents</h2>
            <p class="mt-1 text-sm text-[#765f51]">A reviewer checks each document before approving this account.</p>
        </div>
        @if($currentDocuments->isEmpty())
            <p class="mt-5 rounded-xl bg-[#f6efe7] p-4 text-sm text-[#765f51]">No documents are attached to this application yet.</p>
        @else
            <div class="mt-4 divide-y divide-[#eadccc]">
                @foreach($currentDocuments as $document)
                    @php($documentStatus = strtoupper((string) $document->verification_status))
                    <article class="flex flex-wrap items-start justify-between gap-3 py-4">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-[#3b211b]">{{ str($document->document_type)->replace('_', ' ')->headline() }}</h3>
                            <p class="mt-1 break-all text-xs text-[#765f51]">{{ $document->original_name ?: 'Submitted document' }}</p>
                            @if($documentStatus === 'REJECTED' && $document->rejection_reason)
                                <p class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-900"><strong>Reviewer’s reason:</strong> {{ $document->rejection_reason }}</p>
                            @endif
                        </div>
                        <span @class([
                            'shrink-0 rounded-full border px-3 py-1 text-xs font-semibold',
                            'border-emerald-200 bg-emerald-50 text-emerald-800' => $documentStatus === 'VERIFIED',
                            'border-red-200 bg-red-50 text-red-800' => $documentStatus === 'REJECTED',
                            'border-amber-200 bg-amber-50 text-amber-900' => $documentStatus === 'PENDING',
                        ])>{{ str($documentStatus)->replace('_', ' ')->headline() }}</span>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @if($rejectedUploads !== [])
        <section class="rounded-2xl border border-red-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="replace-documents-title">
            <div class="rounded-xl bg-red-50 p-4 text-sm leading-6 text-red-900">
                <h2 id="replace-documents-title" class="font-bold">Action needed: upload a replacement</h2>
                <p class="mt-1">Choose a new file for each rejected document you are replacing. You can submit them together or one at a time.</p>
            </div>
            <form method="POST" enctype="multipart/form-data" action="{{ route('registration.documents.resubmit') }}" class="mt-5 grid gap-5">
                @csrf
                @foreach($rejectedUploads as $upload)
                    @php($document = $upload['document'])
                    <label class="grid gap-2 text-sm font-semibold text-[#3b211b]">
                        {{ str($document->document_type)->replace('_', ' ')->headline() }}
                        <input class="block w-full rounded-lg border border-[#dbcec1] bg-white px-3 py-2 text-sm font-normal file:mr-3 file:rounded-md file:border-0 file:bg-[#f5e9db] file:px-3 file:py-2 file:font-semibold file:text-[#561c17]" type="file" name="{{ $upload['input'] }}" accept=".jpg,.jpeg,.png,.webp,.pdf">
                        <span class="text-xs font-normal text-[#765f51]">JPG, PNG, WebP, or PDF. Maximum 5 MB.</span>
                    </label>
                @endforeach
                <div class="flex flex-wrap items-center gap-3 border-t border-[#eadccc] pt-5">
                    <button type="submit" class="lk-btn lk-btn-red">Submit replacement</button>
                    <span class="text-xs text-[#765f51]">At least one replacement file is required.</span>
                </div>
            </form>
        </section>
    @elseif($isAwaitingReview)
        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-950">
            <strong>Waiting for review</strong>
            <p>Your submitted documents are in the review queue. We’ll email you if a document needs to be replaced.</p>
        </div>
    @endif
</section>
@endsection
