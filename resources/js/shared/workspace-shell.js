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
        collapseButton?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');

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
    const updateThemeToggle = () => {
        const isDark = document.documentElement.classList.contains('dark');
        themeToggle?.setAttribute('aria-pressed', String(isDark));
        themeBadge && (themeBadge.textContent = isDark ? 'ON' : 'OFF');
    };

    themeToggle?.addEventListener('click', () => {
        const isDark = document.documentElement.classList.toggle('dark');
        try {
            window.localStorage.setItem('likhae-theme', isDark ? 'dark' : 'light');
        } catch {
            // The theme still applies for this page if storage is unavailable.
        }
        updateThemeToggle();
    });
    updateThemeToggle();
};

document.addEventListener('DOMContentLoaded', initializeWorkspaceShell);
