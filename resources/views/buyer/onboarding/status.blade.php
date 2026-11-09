@extends('layouts.buyer.app')
@section('content')
@php($status=$record?->status)
<div class="mx-auto max-w-2xl px-5 py-16 text-center"><div class="border border-stone-200 bg-white p-10 shadow-sm"><p class="text-xs font-bold uppercase text-red-800">{{ str($type)->headline() }}</p>
<h1 class="mt-3 text-3xl font-bold">{{ !$record?'No application yet':match($status){'pending'=>'Being reviewed','rejected'=>'Application rejected','suspended'=>'Access suspended',default=>'Application status'} }}</h1>
<p class="mx-auto mt-4 max-w-lg text-stone-500">{{ !$record?'Start an application when you are ready.':match($status){'pending'=>'Your documents are with the review team. You will receive access after approval.','rejected'=>$record->rejection_reason ?: 'The application did not pass review.','suspended'=>'Contact support before attempting to use this workspace.',default=>'Your application is being processed.'} }}</p>
@if(!$record || $status==='rejected')<a class="mt-7 inline-block bg-red-900 px-5 py-3 font-semibold text-white" href="{{ $applyRoute }}">{{ $record?'Apply again':'Start application' }}</a>@endif
</div></div>
@endsection
