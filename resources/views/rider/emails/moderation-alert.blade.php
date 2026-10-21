<p>Hello {{ $userName ?: 'Rider' }},</p>

<p><strong>{{ $title }}</strong></p>

<p>Your rider account has been updated by the logistics team.</p>

<p>{{ $body }}</p>

@if($actionUrl)
    <p><a href="{{ url($actionUrl) }}">{{ $actionLabel }}</a></p>
@endif

<p>If you have questions about this decision, contact your logistics center.</p>

<p>LIKHAE Rider Support</p>
