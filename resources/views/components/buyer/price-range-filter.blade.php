@props([
    'prefix',
    'minimum' => '',
    'maximum' => '',
    'priceRange' => [],
    'errors' => null,
])

@php
    $priceRange = (array) ($priceRange ?? []);
    $domainMin = max(0, (float) ($priceRange['min'] ?? 0));
    $domainMax = max($domainMin, (float) ($priceRange['max'] ?? 1));
    $step = max(0.01, (float) ($priceRange['step'] ?? 0.01));
    $minimumValue = is_scalar($minimum) ? trim((string) $minimum) : '';
    $maximumValue = is_scalar($maximum) ? trim((string) $maximum) : '';
    $validMinimum = $minimumValue !== '' && is_numeric($minimumValue) && (float) $minimumValue >= 0
        ? (float) $minimumValue
        : null;
    $validMaximum = $maximumValue !== '' && is_numeric($maximumValue) && (float) $maximumValue >= 0
        ? (float) $maximumValue
        : null;
    $sliderMin = min($domainMin, $validMinimum ?? $domainMin, $validMaximum ?? $domainMin);
    $sliderMax = max($domainMax, $validMinimum ?? $domainMax, $validMaximum ?? $domainMax);
    if ($sliderMax <= $sliderMin) {
        $sliderMax = $sliderMin + max(1, $step);
    }
    $sliderValueMin = $validMinimum ?? $domainMin;
    $sliderValueMax = $validMaximum ?? $domainMax;
    $labelMin = $validMinimum ?? $domainMin;
    $labelMax = $validMaximum ?? $domainMax;
    $priceError = $errors?->first('min_price') ?: $errors?->first('max_price');
@endphp

<div {{ $attributes->merge(['class' => 'lk-filter-card lk-price-filter']) }}>
    <div class="lk-filter-heading"><h2>Price Range</h2></div>

    <div
        class="lk-dual-price-range"
        data-price-range
        data-range-min="{{ number_format($sliderMin, 2, '.', '') }}"
        data-range-max="{{ number_format($sliderMax, 2, '.', '') }}"
        data-range-step="{{ number_format($step, 2, '.', '') }}"
        data-range-available="{{ ($priceRange['available'] ?? false) ? 'true' : 'false' }}"
    >
        <div class="lk-price-selected" aria-live="polite">
            <span>Minimum: <output data-price-min-output>₱{{ number_format($labelMin, 2) }}</output></span>
            <span>Maximum: <output data-price-max-output>₱{{ number_format($labelMax, 2) }}</output></span>
        </div>

        <div class="lk-dual-price-track" aria-hidden="true">
            <span class="lk-dual-price-fill" data-price-range-fill></span>
        </div>
        <label class="sr-only" for="{{ $prefix }}MinSlider">Minimum price slider</label>
        <input
            id="{{ $prefix }}MinSlider"
            class="lk-dual-price-handle lk-dual-price-handle--min"
            type="range"
            min="{{ number_format($sliderMin, 2, '.', '') }}"
            max="{{ number_format($sliderMax, 2, '.', '') }}"
            step="{{ number_format($step, 2, '.', '') }}"
            value="{{ number_format($sliderValueMin, 2, '.', '') }}"
            aria-label="Minimum price"
            aria-valuetext="₱{{ number_format($labelMin, 2) }}"
            data-price-min-slider
            @disabled(! ($priceRange['available'] ?? false))
        >
        <label class="sr-only" for="{{ $prefix }}MaxSlider">Maximum price slider</label>
        <input
            id="{{ $prefix }}MaxSlider"
            class="lk-dual-price-handle lk-dual-price-handle--max"
            type="range"
            min="{{ number_format($sliderMin, 2, '.', '') }}"
            max="{{ number_format($sliderMax, 2, '.', '') }}"
            step="{{ number_format($step, 2, '.', '') }}"
            value="{{ number_format($sliderValueMax, 2, '.', '') }}"
            aria-label="Maximum price"
            aria-valuetext="₱{{ number_format($labelMax, 2) }}"
            data-price-max-slider
            @disabled(! ($priceRange['available'] ?? false))
        >

        <input type="hidden" name="min_price" value="{{ $minimumValue }}" data-price-min-input>
        <input type="hidden" name="max_price" value="{{ $maximumValue }}" data-price-max-input>
    </div>

    @if($priceError)
        <p class="lk-filter-error" role="alert">{{ $priceError }}</p>
    @endif
</div>
