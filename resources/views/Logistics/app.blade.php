<!DOCTYPE html>
<html
    lang="en"
    class="bg-[#f7f3ec]"
>

<head>
    <x-likhae-favicon />

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
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

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

            const savedTheme =
                localStorage.getItem(
                    'likhae-theme'
                );

            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {

                document.documentElement
                    .classList
                    .add('dark');

            } else {

                document.documentElement
                    .classList
                    .remove('dark');

            }

        })();
    </script>

    @vite([
        'resources/css/logistic/app.css',
        'resources/css/shared/status-guidance.css',
        'resources/css/shared/modal-refresh.css',
        'resources/css/shared/likhae-workspace-ai.css',
        'resources/css/shared/workspace-sidebar.css',
        'resources/js/logistic/app.js',
        'resources/js/shared/likhae-workspace-ai.js'
    ])
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

        <x-admin.sidebar role="logistics" />


        {{-- =================================================
            MOBILE OVERLAY
        ================================================== --}}

        <div
            id="sidebarOverlay"
            class="
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

            <header class="hidden h-[62px] items-center justify-end gap-3 border-b border-line bg-page/95 px-6 backdrop-blur-xl lg:flex">
                <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="relative grid h-9 w-9 place-items-center rounded-full border border-line bg-surface text-ink transition hover:bg-surface-hover">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    @if(($workspaceNotificationCount ?? 0) > 0)<span class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-red-600 px-1 text-[8px] font-bold leading-none text-white">{{ $workspaceNotificationCount }}</span>@endif
                </button>
                <x-workspace-account-menu label="Logistics Account" profile-route="logistics.profile" />
            </header>

            {{-- =================================================
                MOBILE HEADER
            ================================================== --}}

            <header
                class="
                    sticky
                    top-0
                    z-30

                    flex
                    h-[62px]
                    items-center
                    justify-between

                    border-b
                    border-line

                    bg-page/95

                    px-4

                    backdrop-blur-xl

                    lg:hidden
                "
            >

                {{-- OPEN SIDEBAR --}}

                <button
                    type="button"
                    id="mobileSidebarToggle"
                    aria-label="Open navigation"
                    class="
                        grid
                        h-9
                        w-9
                        place-items-center

                        rounded-lg

                        text-ink

                        transition

                        hover:bg-surface-hover
                    "
                >

                    <svg
                        viewBox="0 0 24 24"
                        class="
                            h-5
                            w-5

                            fill-none
                            stroke-current
                            stroke-[1.7]
                        "
                    >
                        <path d="M4 7h16"></path>
                        <path d="M4 12h16"></path>
                        <path d="M4 17h16"></path>
                    </svg>

                </button>


                {{-- MOBILE BRAND --}}

                <a
                    href="{{ route('logistics.dashboard') }}"
                    class="
                        flex
                        items-center
                        gap-2
                    "
                >

                    <span
                        class="
                            grid
                            h-8
                            w-8
                            place-items-center

                            rounded-lg

                            bg-primary

                            text-[12px]
                            font-black
                            text-white
                        "
                    >
                        L
                    </span>


                    <span
                        class="
                            text-[13px]
                            font-extrabold
                            tracking-[-0.04em]
                            text-ink
                        "
                    >
                        LIKHAE
                    </span>

                </a>


                <button type="button" aria-label="Notifications" aria-controls="notificationPopover" aria-expanded="false" data-notification-toggle class="relative grid h-9 w-9 place-items-center rounded-full border border-line bg-surface text-ink transition hover:bg-surface-hover">
                    <svg viewBox="0 0 24 24" class="h-4 w-4 fill-none stroke-current stroke-[1.8]" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                    @if(($workspaceNotificationCount ?? 0) > 0)<span class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-red-600 px-1 text-[8px] font-bold leading-none text-white">{{ $workspaceNotificationCount }}</span>@endif
                </button>

                {{-- MOBILE PROFILE --}}

                <x-workspace-account-menu label="Logistics Account" profile-route="logistics.profile" compact />

            </header>


            {{-- =================================================
                PAGE CONTENT
            ================================================== --}}

            <div
                class="
                    mx-auto
                    w-full
                    max-w-[1500px]

                    px-[14px]
                    py-5

                    sm:px-6
                    sm:py-6
                    lg:py-8
                    xl:py-9
                "
            >

                @yield('content')

            </div>

        </main>

    </div>


    {{-- =====================================================
        PAGE-SPECIFIC SCRIPTS
    ====================================================== --}}

    <x-notification-popover />
    @include('partials.confirm-action-modal')
    @php
        $workspaceAiPage = request()->route()?->getName() ?: 'logistics.dashboard';
        $workspaceAiTitle = trim($__env->yieldContent('title')) ?: 'Logistics';
    @endphp
    @if(data_get(auth()->user()?->notification_preferences, 'logistics_ai_assistant_enabled', true))
        <x-workspace.ai-assistant workspace="logistics" :page="$workspaceAiPage" :page-title="$workspaceAiTitle" />
    @endif
    <x-shared.feedback-region workspace="logistics" />
    @stack('scripts')

</body>

</html>
