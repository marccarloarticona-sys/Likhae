<p>Hello {{ $userName ?: 'Rider' }},</p>

<p><strong>{{ $title }}</strong></p>

<p>{{ $body }}</p>

<p>Open your rider dashboard to review the parcel details and next steps.</p>

@if($actionUrl)
    <p><a href="{{ url($actionUrl) }}">{{ $actionLabel }}</a></p>
@endif

<p>LIKHAE Logistics</p>
