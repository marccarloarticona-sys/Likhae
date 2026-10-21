<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product warning</title>
</head>
<body style="margin:0;background:#f5f5f4;color:#292524;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
    <div style="max-width:680px;margin:32px auto;padding:0 16px">
        <div style="overflow:hidden;border:1px solid #e7e5e4;border-radius:16px;background:#fff">
            <div style="padding:28px 32px;background:#9a5b11;color:#fff">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">LIKHAE Seller Notice</p>
                <h1 style="margin:0;font-size:25px;line-height:1.25">Your product received a warning</h1>
            </div>
            <div style="padding:28px 32px">
                <p>Hello {{ $product->sellerProfile?->business_name ?: $product->sellerProfile?->user?->first_name ?: 'Seller' }},</p>
                <p>An administrator reviewed the product below and recorded a warning. The listing remains active while you make the requested correction.</p>
                <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">
                    <tr><td style="padding:8px 0;color:#78716c">Product</td><td style="padding:8px 0;font-weight:700">{{ $product->name }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Product ID</td><td style="padding:8px 0">#{{ $product->id }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Warning category</td><td style="padding:8px 0">{{ $category }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Compliance case</td><td style="padding:8px 0">{{ $case->case_number }}</td></tr>
                </table>
                <div style="margin:22px 0;padding:16px 18px;border-left:4px solid #9a5b11;border-radius:8px;background:#fff6de">
                    <strong>Reason</strong>
                    <p style="margin:6px 0 0;white-space:pre-line">{{ $reason }}</p>
                </div>
                <h2 style="margin:24px 0 8px;font-size:17px">Required corrective action</h2>
                <p style="white-space:pre-line">{{ $correctiveAction }}</p>
                @if($evidence)<p><strong>Supporting reference:</strong><br>{{ $evidence }}</p>@endif
                <p style="margin:26px 0"><a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;border-radius:9px;background:#641f19;color:#fff;text-decoration:none;font-weight:700">Review compliance case</a></p>
                <p style="margin:24px 0 0;color:#78716c;font-size:13px">This warning remains in the Seller compliance history for follow-up review.</p>
            </div>
        </div>
    </div>
</body>
</html>
