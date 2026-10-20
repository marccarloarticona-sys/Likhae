@props([
    'title' => 'Nothing here yet',
    'message' => 'New activity will appear here.',
    'action' => null,
    'href' => '#',
])

<div class="lk-shared-empty-state sl-empty-state">
    <span class="lk-shared-empty-state__icon">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v14H4zM8 3h8v3M8 11h8M8 15h5"/></svg>
    </span>
    <h3>{{ $title }}</h3>
    <p>{{ $message }}</p>
    @if ($action)
        <a href="{{ $href }}" class="sl-btn sl-btn-primary lk-shared-empty-state__action">{{ $action }}</a>
    @endif
</div>
