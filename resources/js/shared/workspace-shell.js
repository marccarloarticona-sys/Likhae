const initializeWorkspaceShell = () => {
    const sidebar = document.querySelector('[data-sl-sidebar]');
    const overlay = document.querySelector('[data-sl-overlay]');
    const mobileButton = document.querySelector('[data-sl-mobile-menu]');
    const collapseButton = document.querySelector('[data-sl-sidebar-toggle]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const workspace = sidebar?.dataset.sidebarWorkspace || 'workspace';
    const collapsedStorageKey = `likhae-${workspace}-sidebar-collapsed`;

    const readCollapsed = () => {
        try {
            return window.localStorage.getItem(collapsedStorageKey) === 'true';
        } catch {
            return false;
        }
    };

    const setMobileOpen = (open) => {
        if (!sidebar) return;

        const isOpen = open && !desktop.matches;
        sidebar.classList.toggle('is-open', isOpen);
        overlay?.classList.toggle('is-open', isOpen);
        document.body.classList.toggle('sl-mobile-open', isOpen);
        mobileButton?.setAttribute('aria-expanded', String(isOpen));
        if (mobileButton) {
            const label = isOpen ? mobileButton.dataset.closeLabel : mobileButton.dataset.openLabel;
            label && mobileButton.setAttribute('aria-label', label);
        }
    };

    const setCollapsed = (collapsed, persist = true) => {
        if (!sidebar || !desktop.matches) collapsed = false;

        document.body.classList.toggle('sl-sidebar-collapsed', collapsed);
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        collapseButton?.setAttribute('aria-label', collapsed ? collapseButton.dataset.openLabel : collapseButton.dataset.collapseLabel);

        if (persist) {
            try {
                window.localStorage.setItem(collapsedStorageKey, String(collapsed));
            } catch {
                // The sidebar still works if storage is unavailable.
            }
        }
    };

    if (sidebar) {
        setCollapsed(readCollapsed(), false);

        mobileButton?.addEventListener('click', () => {
            setMobileOpen(!sidebar.classList.contains('is-open'));
        });
        overlay?.addEventListener('click', () => setMobileOpen(false));
        collapseButton?.addEventListener('click', () => {
            setCollapsed(!document.body.classList.contains('sl-sidebar-collapsed'));
        });

        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setMobileOpen(false));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') setMobileOpen(false);
        });

        desktop.addEventListener('change', (event) => {
            setMobileOpen(false);
            setCollapsed(event.matches && readCollapsed(), false);
        });
    }

    const themeToggle = document.getElementById('themeToggle');
    const themeBadge = document.getElementById('themeToggleBadge');
    const themePreferenceUrl = themeToggle?.dataset.themePreferenceUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const updateThemeToggle = () => {
        const isDark = document.documentElement.classList.contains('dark');
        themeToggle?.setAttribute('aria-pressed', String(isDark));
        themeBadge && (themeBadge.textContent = isDark ? themeBadge.dataset.labelOn : themeBadge.dataset.labelOff);
    };

    themeToggle?.addEventListener('click', async () => {
        const previousTheme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
        const nextTheme = previousTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.classList.toggle('dark', nextTheme === 'dark');
        try {
            window.localStorage.setItem('likhae-theme', nextTheme);
        } catch {
            // The theme still applies for this page if storage is unavailable.
        }
        updateThemeToggle();

        if (!themePreferenceUrl) return;

        try {
            const response = await fetch(themePreferenceUrl, {
                method: 'PATCH',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ theme: nextTheme }),
            });
            if (!response.ok) throw new Error('Could not save the appearance preference.');
            const result = await response.json();
            if (result.theme !== nextTheme) throw new Error('The saved appearance preference did not match.');
        } catch (error) {
            document.documentElement.classList.toggle('dark', previousTheme === 'dark');
            try {
                window.localStorage.setItem('likhae-theme', previousTheme);
            } catch {
                // The account preference remains the source of truth.
            }
            updateThemeToggle();
            console.error(error);
        }
    });
    updateThemeToggle();
};

document.addEventListener('DOMContentLoaded', initializeWorkspaceShell);
