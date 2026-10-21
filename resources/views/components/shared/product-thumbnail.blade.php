@props(['item' => null, 'size' => '64'])

@php
    $images = $item?->product?->images ?? collect();
    $image = $images->firstWhere('is_primary', true) ?: $images->first();
    $path = $image?->file_path;
    $url = $path
        ? (\Illuminate\Support\Str::startsWith($path, ['http://', 'https://']) ? $path : '/storage/'.ltrim($path, '/'))
        : asset('images/product-placeholder.svg');
@endphp

<img src="{{ $url }}" alt="{{ $image?->alt_text ?: ($item?->product_name ?? 'Product image') }}" loading="lazy" {{ $attributes->merge(['style' => "width:{$size}px;height:{$size}px;object-fit:cover;border:1px solid #e7e5e4;border-radius:10px;background:#fafaf9;flex:none;"]) }}>
