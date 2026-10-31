@props(['shipment', 'items' => []])

@php
    $packedWeight = is_numeric($shipment->parcel_weight_kg) && is_finite((float) $shipment->parcel_weight_kg) && (float) $shipment->parcel_weight_kg > 0
        ? (float) $shipment->parcel_weight_kg
        : null;
    $itemWeights = collect($items)->map(function ($item): ?float {
        $unitWeight = $item?->productVariant?->weight_kg;
        $quantity = (int) ($item?->quantity ?? 0);

        return is_numeric($unitWeight) && is_finite((float) $unitWeight) && (float) $unitWeight > 0 && $quantity > 0
            ? round((float) $unitWeight * $quantity, 3, PHP_ROUND_HALF_UP)
            : null;
    });
    $productWeightEstimate = $itemWeights->isNotEmpty() && $itemWeights->every(fn (?float $weight): bool => $weight !== null)
        ? round($itemWeights->sum(), 3, PHP_ROUND_HALF_UP)
        : null;
@endphp

<div class="box">
    <strong>Parcel weight</strong><br>
    @if($packedWeight !== null)
        {{ number_format($packedWeight, 3) }} kg <small>(packed weight recorded)</small>
    @elseif($productWeightEstimate !== null)
        {{ number_format($productWeightEstimate, 3) }} kg <small>(product estimate; packaging not included)</small>
    @else
        Product weight not recorded
    @endif
</div>
