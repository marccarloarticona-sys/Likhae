@props(['tracking'])

@php
    $barcode = null;

    if (class_exists(\Picqer\Barcode\BarcodeGeneratorSVG::class)) {
        $generator = new \Picqer\Barcode\BarcodeGeneratorSVG();
        $barcode = $generator->getBarcode(
            (string) $tracking,
            \Picqer\Barcode\BarcodeGenerator::TYPE_CODE_128,
            2,
            54,
        );
    } else {
        $barcode = \App\Support\Code128Svg::render((string) $tracking);
    }
@endphp

<div role="img" aria-label="Waybill barcode for {{ $tracking }}" style="display:grid;justify-items:center;gap:6px;width:100%;max-width:680px;margin:14px auto;padding:10px 12px;background:#fff;box-sizing:border-box;">
    {!! $barcode !!}
    <strong style="font:700 12px/1.2 monospace;letter-spacing:2px;">{{ $tracking }}</strong>
</div>
