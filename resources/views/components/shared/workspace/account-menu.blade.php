@props([
    'label',
    'profileRoute',
    'compact' => false,
])

@php
    $accountUser = auth()->user();
    $accountName = $accountUser?->name ?? 'Account';
    $accountInitials = collect(explode(' ', trim($accountName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: 'LK';
    $accountPhoto = data_get($accountUser, 'profile_photo_path');
@endphp

@once
    <style>
        .workspace-account { position: relative; flex: 0 0 auto; font-family: inherit; }
        .workspace-account__trigger { display: flex; min-height: 42px; align-items: center; gap: 8px; padding: 3px 6px; border: 0; border-radius: 999px; background: transparent; color: inherit; cursor: pointer; list-style: none; text-align: left; }
        .workspace-account__trigger::-webkit-details-marker { display: none; }
        .workspace-account__trigger:hover, .workspace-account[open] > .workspace-account__trigger { background: rgba(120, 71, 50, .08); }
        .workspace-account__trigger:focus-visible { outline: 2px solid #a93e30; outline-offset: 2px; }
        .workspace-account__avatar { display: grid; width: 34px; height: 34px; flex: 0 0 34px; place-items: center; border-radius: 50%; background: #7d241b; color: #fff; font-size: 10px; font-weight: 800; }
        .workspace-account__avatar img { width: 100%; height: 100%; border-radius: inherit; object-fit: cover; }
        .workspace-account__copy { display: grid; min-width: 0; gap: 1px; }
        .workspace-account__copy strong { max-width: 150px; overflow: hidden; color: #38231c; font-size: 11px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
        .workspace-account__copy small { color: #8d7669; font-size: 9px; line-height: 1.2; }
        .workspace-account__chevron { width: 14px; height: 14px; flex: 0 0 14px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; transition: transform .16s ease; }
        .workspace-account[open] .workspace-account__chevron { transform: rotate(180deg); }
        .workspace-account__panel { position: absolute; top: calc(100% + 8px); right: 0; z-index: 100; width: 224px; padding: 7px; border: 1px solid #eadccc; border-radius: 13px; background: #fffdf9; box-shadow: 0 14px 34px rgba(44, 26, 18, .17); color: #38231c; }
        .workspace-account__link, .workspace-account__logout { display: flex; width: 100%; min-height: 40px; align-items: center; gap: 9px; padding: 0 10px; border: 0; border-radius: 8px; background: transparent; color: inherit; font: inherit; font-size: 11px; font-weight: 700; text-align: left; text-decoration: none; cursor: pointer; }
        .workspace-account__link:hover, .workspace-account__logout:hover { background: #f6efe7; }
        .workspace-account__logout { color: #8d2c23; }
        .workspace-account__divider { height: 1px; margin: 5px 3px; background: #eadccc; }
        .workspace-account--compact .workspace-account__copy { display: none; }
        .workspace-account--compact .workspace-account__trigger { gap: 4px; }
        .dark .workspace-account__copy strong { color: #f5efe8; }
        .dark .workspace-account__copy small { color: #b8a69c; }
        .dark .workspace-account__trigger:hover, .dark .workspace-account[open] > .workspace-account__trigger { background: rgba(243, 198, 186, .1); }
        .dark .workspace-account__panel { border-color: #49342b; background: #241a17; color: #f5efe8; }
        .dark .workspace-account__link:hover, .dark .workspace-account__logout:hover { background: #382820; }
        .dark .workspace-account__divider { background: #49342b; }
        @media (max-width: 640px) {
            .workspace-account__copy strong { max-width: 96px; }
        }
    </style>
    <script>
        document.addEventListener('click', event => {
            document.querySelectorAll('.workspace-account[open]').forEach(menu => {
                if (!menu.contains(event.target)) menu.open = false;
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const menu = document.querySelector('.workspace-account[open]');
            if (!menu) return;
            menu.open = false;
            menu.querySelector('summary')?.focus();
        });
    </script>
@endonce

<details class="workspace-account {{ $compact ? 'workspace-account--compact' : '' }}">
    <summary class="workspace-account__trigger" aria-label="{{ $accountName }}, {{ $label }}">
        <span class="workspace-account__avatar" aria-hidden="true"><x-shared.profile-image :path="$accountPhoto" role="buyer" alt="" /></span>
        <span class="workspace-account__copy">
            <strong>{{ $accountName }}</strong>
            <small>{{ $label }}</small>
        </span>
        <svg class="workspace-account__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>
    </summary>
    <div class="workspace-account__panel">
        <a class="workspace-account__link" href="{{ route($profileRoute) }}">Account settings</a>
        <div class="workspace-account__divider"></div>
        <form method="POST" action="{{ route('logout') }}" data-confirm-title="Log out?" data-confirm-message="You will be signed out of your LIKHAE account." data-confirm-label="Log out">
            @csrf
            <button type="submit" class="workspace-account__logout">Sign out</button>
        </form>
    </div>
</details>
