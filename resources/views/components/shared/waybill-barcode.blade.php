@props(['tracking'])

@php
    $trackingValue = (string) $tracking;
    $barcode = null;

    if (class_exists(\Picqer\Barcode\BarcodeGeneratorSVG::class)) {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        $barcode = $generator->getBarcode(
            $trackingValue,
            \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128,
            2.5,
            88,
        );
    } else {
        $barcode = \App\Support\Code128Svg::render($trackingValue, 3, 88);
    }

    // Keep the SVG markup valid when it is embedded directly into the page.
    $barcode = preg_replace('/^<\?xml[^>]*>\s*/', '', $barcode) ?? $barcode;
    $barcode = preg_replace('/^<!DOCTYPE[^>]*>\s*/', '', $barcode) ?? $barcode;
@endphp

<div class="waybill-barcode" role="img" aria-label="Waybill barcode for {{ $trackingValue }} (Code 128)" style="display:grid;justify-items:center;gap:5px;width:100%;max-width:680px;margin:8px auto 12px;padding:8px 26px;background:#fff;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
    <div class="waybill-barcode__svg" style="width:100%;">
        {!! $barcode !!}
    </div>
    <strong style="font:700 12px/1.2 monospace;letter-spacing:1.5px;">{{ $trackingValue }}</strong>
</div>
<style>
    .waybill-barcode__svg svg { display:block; width:100%; max-width:100% !important; height:auto; background:#fff; shape-rendering:crispEdges; }
</style>
