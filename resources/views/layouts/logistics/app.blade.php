@php
    $logisticsThemePreference = data_get(auth()->user()?->notification_preferences, 'theme', 'system');
    if (! in_array($logisticsThemePreference, ['light', 'dark', 'system'], true)) {
        $logisticsThemePreference = 'system';
    }
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="bg-[#f7f3ec]"
    data-theme-preference="{{ $logisticsThemePreference }}"
>

<head>
    <x-shared.likhae-favicon />

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <meta
        name="csrf-token"
        content="{{ csrf_token() }}"
    >

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">

    <title>
        @yield(
            'title',
            'LIKHAE Logistics'
        )
    </title>

    {{-- =====================================================
        THEME INITIALIZER
        Default = LIGHT
    ====================================================== --}}

    <script>
        (() => {

            const theme = @json($logisticsThemePreference);
            try {
                if (theme === 'system') localStorage.removeItem('likhae-theme');
                else localStorage.setItem('likhae-theme', theme);
            } catch (_) {}
            document.documentElement.classList.toggle(
                'dark',
                theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
            );

        })();
    </script>

    @vite([
        'resources/css/logistic/app.css',
        'resources/css/shared/status-guidance.css',
        'resources/css/shared/modal-refresh.css',
        'resources/css/shared/announcement-popup.css',
        'resources/css/shared/likhae-workspace-ai.css',
        'resources/css/shared/workspace-sidebar.css',
        'resources/js/logistic/app.js',
        'resources/js/shared/likhae-workspace-ai.js'
    ])
    @if(request()->routeIs('logistics.dashboard'))
        @vite(['resources/css/shared/dashboard-weather.css', 'resources/js/shared/dashboard-weather.js'])
    @endif
    @unless(request()->routeIs('logistics.messages'))
        @vite([
            'resources/css/shared/mapbox.css',
            'resources/js/shared/mapbox.js',
            'resources/js/shared/address-pin.js'
        ])
    @endunless

    @stack('styles')

</head>

<body
    class="
        logistics-shell
        min-h-screen
        overflow-x-hidden

        bg-page
        text-ink

        antialiased

        transition-colors
        duration-300
    "
>

    <div class="min-h-screen">

        {{-- =================================================
            SIDEBAR
        ================================================== --}}

        <x-logistics.sidebar :sidebar-counts="$sidebarCounts ?? []" :notification-count="$workspaceNotificationCount ?? 0" />


        {{-- =================================================
            MOBILE OVERLAY
        ================================================== --}}

        <div
            id="sidebarOverlay"
            class="
                sl-overlay
                invisible
                fixed
                inset-0
                z-40

                bg-black/45

                opacity-0

                backdrop-blur-[2px]

                transition-all
                duration-300

                lg:hidden
            "
            data-sl-overlay
        ></div>


        {{-- =================================================
            MAIN CONTENT
        ================================================== --}}

        <main
            data-sidebar-content
            class="
                min-h-screen

                bg-page

                transition-all
                duration-300

                lg:ml-[252px]
            "
        >

            @php
                $logisticsHeaderTitle = trim(\Illuminate\Support\Str::before(trim($__env->yieldContent('title')), ' - LIKHAE')) ?: 'Dashboard';
            @endphp
            <x-logistics.header :title="$logisticsHeaderTitle" subtitle="Manage riders, parcels, and center operations." :notification-count="$workspaceNotificationCount ?? 0" :message-count="$sidebarCounts['messages'] ?? 0" />

            {{-- =================================================
                PAGE CONTENT
            ================================================== --}}

            <div
                class="
                    mx-auto
                    w-full
                    {{ request()->routeIs('logistics.profile', 'logistics.account') ? 'max-w-none' : 'max-w-[1500px]' }}

                    px-[14px]
                    py-5

                    sm:px-6
                    sm:py-6
                    lg:py-8
                    xl:py-9
                "
                data-logistics-search-content
                data-workspace-search-content
            >

                @yield('content')

            </div>

        </main>

    </div>


    {{-- =====================================================
        PAGE-SPECIFIC SCRIPTS
    ====================================================== --}}

    <x-shared.announcement-popup />
    <x-shared.notification-popover />
    @include('components.shared.confirm-action-modal')
    @php
        $workspaceAiPage = request()->route()?->getName() ?: 'logistics.dashboard';
        $workspaceAiTitle = trim($__env->yieldContent('title')) ?: 'Logistics';
    @endphp
    @if(data_get(auth()->user()?->notification_preferences, 'logistics_ai_assistant_enabled', true))
        <x-shared.workspace.ai-assistant workspace="logistics" :page="$workspaceAiPage" :page-title="$workspaceAiTitle" />
    @endif
    <x-shared.feedback-region workspace="logistics" />
    @stack('scripts')

</body>

</html>
