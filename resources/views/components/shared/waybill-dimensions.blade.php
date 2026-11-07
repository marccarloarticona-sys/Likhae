@props(['shipment' => null])

@php
    $length = $shipment?->parcel_length_cm;
    $width = $shipment?->parcel_width_cm;
    $height = $shipment?->parcel_height_cm;
    $hasDimensions = collect([$length, $width, $height])->every(fn ($value): bool => is_numeric($value) && is_finite((float) $value) && (float) $value > 0);
@endphp

<div class="box">
    <strong>Packed parcel dimensions</strong><br>
    @if($hasDimensions)
        {{ number_format((float) $length, 2) }} × {{ number_format((float) $width, 2) }} × {{ number_format((float) $height, 2) }} cm
    @else
        Not recorded yet
    @endif
</div>
