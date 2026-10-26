<nav class="lk-support-tabs" aria-label="Messaging sections">
    <a href="{{ $overviewUrl }}" class="{{ $section === 'overview' ? 'is-active' : '' }}" @if($section === 'overview') aria-current="page" @endif>Overview</a>
    <a href="{{ $chatsUrl }}" class="{{ $section === 'chats' ? 'is-active' : '' }}" @if($section === 'chats') aria-current="page" @endif>Live Chats</a>
</nav>
<style>
    .lk-support-tabs{display:flex;gap:7px;margin:0 0 14px;padding:5px;border:1px solid #ead5c3;border-radius:12px;background:#fffaf5}
    .lk-support-tabs a{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 15px;border-radius:9px;color:#76594b;text-decoration:none;font-size:12px;font-weight:800}
    .lk-support-tabs a:hover{background:#f6e9dd;color:#5b2018}
    .lk-support-tabs a.is-active{background:#5b2018;color:#fff;box-shadow:0 4px 12px rgba(86,28,23,.13)}
</style>
