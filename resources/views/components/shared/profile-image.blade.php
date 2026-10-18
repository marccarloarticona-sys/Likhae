@props(['path' => null, 'role' => 'buyer', 'alt' => '', 'class' => null])

@php
    $fallbackAsset = match ($role) {
        'seller' => 'images/defaults/store-logo.svg',
        'store-banner' => 'images/defaults/store-banner.svg',
        default => 'images/defaults/profile-avatar.svg',
    };
    $fallbackUrl = asset($fallbackAsset);
    $normalizedPath = is_string($path) ? trim($path) : '';
    $safeLocalPath = $normalizedPath !== ''
        && ! preg_match('~^[a-z][a-z0-9+.-]*:|^[/\\\\]|[?#]|(^|[/\\\\])\.\.?([/\\\\]|$)~i', $normalizedPath)
        ? str_replace('\\', '/', $normalizedPath)
        : null;
    $imageUrl = $safeLocalPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($safeLocalPath)
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($safeLocalPath)
        : $fallbackUrl;
@endphp

<img {{ $attributes->merge([
    'class' => $class,
    'src' => $imageUrl,
    'alt' => $alt,
    'onerror' => "this.onerror=null;this.src='{$fallbackUrl}'",
]) }}>
