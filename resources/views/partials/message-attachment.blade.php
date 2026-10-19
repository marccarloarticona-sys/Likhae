@php
    $attachmentService = app(\App\Services\Communication\MessageAttachmentService::class);
    $attachmentUrl = $attachmentService->privateUrl($message);
    $attachmentName = $attachmentService->originalNameFor($message) ?: 'Attachment';
    $attachmentMime = $attachmentService->mimeFor($message) ?: '';
@endphp
@if($attachmentUrl && str_starts_with($attachmentMime, 'image/'))
    <span class="lk-message-image-frame" data-message-attachment="true" data-message-photo-frame>
        <span class="lk-message-photo-placeholder" aria-hidden="true"></span>
        <img data-message-attachment="true" data-message-photo class="lk-message-image-attachment" data-src="{{ $attachmentUrl }}" alt="Attached image: {{ $attachmentName }}" loading="lazy" decoding="async" fetchpriority="low">
    </span>
@elseif($attachmentUrl)
    <a data-message-attachment="true" class="lk-message-file" href="{{ $attachmentUrl }}" download="{{ $attachmentName }}" aria-label="Download {{ $attachmentName }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10z"/><path d="M13 3v7h7M8 15h8M8 18h5"/></svg>
        <span class="lk-message-file-copy"><strong>{{ $attachmentName }}</strong><small>{{ strtoupper(pathinfo($attachmentName, PATHINFO_EXTENSION) ?: 'FILE') }} attachment</small></span>
        <span class="lk-message-file-download" aria-hidden="true">↓</span>
    </a>
@endif
