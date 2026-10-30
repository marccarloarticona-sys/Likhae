@props(['workspace' => 'app', 'suppressValidationErrors' => false])

@php
    $errorBag = $errors ?? null;
    $validationMessage = $errorBag?->first();
    $hasValidationErrors = $errorBag?->any() ?? false;
    $validationFlashMessage = $suppressValidationErrors && $hasValidationErrors ? null : $validationMessage;
    $flashMessage = session('success') ?: session('status') ?: (($workspace === 'buyer' || $workspace === 'guest') ? null : (session('error') ?: $validationFlashMessage));
    $flashType = (session('error') || ($hasValidationErrors && !$suppressValidationErrors)) ? 'error' : 'success';
@endphp

<div
    @class(['lk-feedback-region', 'lk-feedback-region--' . $workspace])
    data-feedback-region
    @if($workspace === 'buyer' || $workspace === 'guest') id="lkBuyerToast" @endif
    @if($workspace === 'seller') data-sl-toast @endif
    @if($workspace === 'admin') data-toast-region @endif
    aria-live="polite"
    aria-atomic="false"
>
    @if($flashMessage)
        <div class="lk-feedback-toast is-visible is-persistent is-{{ $flashType }}" data-feedback-flash role="{{ $flashType === 'error' ? 'alert' : 'status' }}">
            <span class="lk-feedback-toast__icon" aria-hidden="true">{{ $flashType === 'error' ? '!' : '✓' }}</span>
            <span class="lk-feedback-toast__message">{{ $flashMessage }}</span>
            <button class="lk-feedback-toast__close" type="button" data-feedback-dismiss aria-label="Dismiss message">&times;</button>
        </div>
    @endif
</div>
