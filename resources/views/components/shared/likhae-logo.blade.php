@props([
    'context' => null,
])

@php
    $label = 'LIKHAE' . ($context ? ' ' . $context : '');
@endphp

<span {{ $attributes->class('likhae-logo') }} role="img" aria-label="{{ $label }}">
    <img class="likhae-logo__image likhae-logo__image--light" src="{{ asset('images/likhae-logo.png') }}" alt="" width="194" height="42" loading="eager" decoding="async" aria-hidden="true">
    <img class="likhae-logo__image likhae-logo__image--dark" src="{{ asset('images/likhae-logo-dark.png') }}" alt="" width="194" height="42" loading="eager" decoding="async" aria-hidden="true">
</span>
