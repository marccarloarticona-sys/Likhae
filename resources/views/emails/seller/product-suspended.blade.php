<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Product suspended</title>
</head>
<body style="margin:0;background:#f5f5f4;color:#292524;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
    <div style="max-width:680px;margin:32px auto;padding:0 16px">
        <div style="overflow:hidden;border:1px solid #e7e5e4;border-radius:16px;background:#fff">
            <div style="padding:28px 32px;background:#7f1d1d;color:#fff">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">LIKHAE Seller Notice</p>
                <h1 style="margin:0;font-size:25px;line-height:1.25">Your product has been suspended</h1>
            </div>
            <div style="padding:28px 32px">
                <p>Hello {{ $product->sellerProfile?->business_name ?: $product->sellerProfile?->user?->first_name ?: 'Seller' }},</p>
                <p>An administrator suspended the product below on {{ $case->opened_at?->format('F j, Y \a\t g:i A') }}. The listing is currently unavailable to buyers.</p>

                <h2 style="margin:24px 0 10px;font-size:17px">Product details</h2>
                <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px">
                    <tr><td style="padding:8px 0;color:#78716c">Product</td><td style="padding:8px 0;font-weight:700">{{ $product->name }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Product ID</td><td style="padding:8px 0">#{{ $product->id }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Category</td><td style="padding:8px 0">{{ $product->category?->name ?: 'Uncategorized' }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Active SKU(s)</td><td style="padding:8px 0">{{ $skuList ?: 'Not set' }}{{ $product->variants->where('is_active', true)->pluck('sku')->filter()->count() > 5 ? ', and more' : '' }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Price range</td><td style="padding:8px 0">{{ $priceSummary }}</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Available stock</td><td style="padding:8px 0">{{ number_format((int) $totalStock) }} units</td></tr>
                    <tr><td style="padding:8px 0;color:#78716c">Compliance case</td><td style="padding:8px 0">{{ $case->case_number }}</td></tr>
                </table>

                <div style="margin:22px 0;padding:16px 18px;border-left:4px solid #b91c1c;border-radius:8px;background:#fef2f2">
                    <strong>Reason provided by the administrator</strong>
                    <p style="margin:6px 0 0;white-space:pre-line">{{ $reason }}</p>
                </div>

                <h2 style="margin:24px 0 8px;font-size:17px">What you should do</h2>
                <ol style="padding-left:20px">
                    <li>Review the reason above and check the affected product details.</li>
                    <li>Update the listing to address the issue.</li>
                    <li>If you need clarification or believe this was a mistake, contact LIKHAE support and include case number <strong>{{ $case->case_number }}</strong>.</li>
                </ol>
                <p>Keep this case number for any follow-up. The product remains unavailable to buyers while its status is suspended.</p>
                <p style="margin:26px 0"><a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 18px;border-radius:9px;background:#7f1d1d;color:#fff;text-decoration:none;font-weight:700">Review compliance case</a></p>
                <p style="margin:24px 0 0;color:#78716c;font-size:13px">This notice was sent only to the seller account associated with this product.</p>
            </div>
        </div>
    </div>
</body>
</html>
