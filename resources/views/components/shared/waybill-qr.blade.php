@props(['tracking', 'size' => 144])

@php
    $trackingValue = (string) $tracking;
    $qrSvg = null;

    if (class_exists(\Endroid\QrCode\QrCode::class) && class_exists(\Endroid\QrCode\Writer\SvgWriter::class)) {
        $qrCode = \Endroid\QrCode\QrCode::create($trackingValue)
            ->setSize(max(96, (int) $size))
            ->setMargin(2);
        $qrSvg = (new \Endroid\QrCode\Writer\SvgWriter())->write($qrCode)->getString();
        $qrSvg = preg_replace('/^<\?xml[^>]*>\s*/', '', $qrSvg) ?? $qrSvg;
    }
@endphp

<div class="waybill-qr" role="img" aria-label="Waybill QR code for {{ $trackingValue }}">
    @if($qrSvg)
        {!! $qrSvg !!}
    @else
        <span>QR unavailable</span>
    @endif
</div>
