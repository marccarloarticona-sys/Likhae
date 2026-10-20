@props(['title' => 'Nothing here yet', 'message' => 'Once there is activity, it will appear here.', 'action' => null, 'href' => null])
<div class="lk-shared-empty-state">
    <span class="lk-shared-empty-state__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h16v14H4zM8 3h8v3M8 11h8M8 15h5"/></svg></span>
    <h3>{{ $title }}</h3>
    <p>{{ $message }}</p>
    @if($action && $href)
        <a class="lk-btn lk-btn-red lk-shared-empty-state__action" href="{{ $href }}">{{ $action }}</a>
    @endif
</div>
