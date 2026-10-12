<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Your LIKHAE Password</title>
</head>
<body style="margin:0;background:#f7f5f2;color:#2c2723;font-family:Arial,Helvetica,sans-serif;line-height:1.6;">
    <div style="max-width:620px;margin:0 auto;padding:32px 16px;">
        <div style="overflow:hidden;border:1px solid #e5ded6;border-radius:18px;background:#ffffff;box-shadow:0 14px 36px rgba(51,35,28,.08);">
            <div style="padding:24px 28px;background:#8f1719;text-align:center;">
                <img src="{{ asset('images/likhae-logo.png') }}" alt="LIKHAE" width="194" height="42" style="display:inline-block;width:194px;height:42px;object-fit:contain;">
            </div>
            <div style="padding:32px 28px;">
                <p style="margin:0 0 8px;color:#8f1719;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;">Account security</p>
                <h1 style="margin:0 0 16px;color:#151515;font-size:26px;line-height:1.2;">Reset Your LIKHAE Password</h1>
                <p style="margin:0 0 20px;">We received a request to reset your LIKHAE account password.</p>
                <p style="margin:0 0 24px;">Use the button below to create a new password. This link expires in <strong>{{ $expirationMinutes }} minutes</strong>.</p>
                <p style="margin:0 0 28px;text-align:center;">
                    <a href="{{ $resetUrl }}" style="display:inline-block;padding:13px 22px;border-radius:10px;background:#8f1719;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;">Reset Password</a>
                </p>
                <p style="margin:0;color:#77716b;font-size:13px;">If you did not request a password reset, you can safely ignore this email. Your password will not change.</p>
            </div>
            <div style="padding:18px 28px;border-top:1px solid #eee8e1;color:#99928a;font-size:11px;text-align:center;">LIKHAE · Your Philippine marketplace</div>
        </div>
    </div>
</body>
</html>
