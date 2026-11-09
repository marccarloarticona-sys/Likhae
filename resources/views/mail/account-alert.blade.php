<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;background:#f5f5f4;color:#292524;font-family:Arial,Helvetica,sans-serif;line-height:1.6">
    <div style="max-width:640px;margin:32px auto;padding:0 16px">
        <div style="overflow:hidden;border:1px solid #e7e5e4;border-radius:16px;background:#fff">
            <header style="padding:24px 30px;background:#2b1e1a;color:#fff">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase">LIKHAE Account Notice</p>
                <h1 style="margin:0;font-size:24px;line-height:1.3">{{ $title }}</h1>
            </header>
            <main style="padding:26px 30px">
                <p>Hello {{ $userName ?: 'there' }},</p>
                <p style="white-space:pre-line">{{ $body }}</p>
                @if($actionUrl)
                    <p style="margin:26px 0"><a href="{{ url($actionUrl) }}" style="display:inline-block;padding:12px 18px;border-radius:9px;background:#641f19;color:#fff;text-decoration:none;font-weight:700">{{ $actionLabel }}</a></p>
                @endif
                <p style="margin:24px 0 0;color:#78716c;font-size:13px">This is an automated notice from LIKHAE.</p>
            </main>
        </div>
    </div>
</body>
</html>
