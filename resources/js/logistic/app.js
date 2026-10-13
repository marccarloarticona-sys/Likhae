import { initializeParcelScanners } from '../shared/parcel-scanner';
import '../address/forms.js';
import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';

document.addEventListener('DOMContentLoaded', () => {

    const root = document.documentElement;

    const sidebar = document.getElementById('logisticsSidebar');
    const sidebarOpenButton = document.getElementById('mobileSidebarToggle');
    const sidebarCloseButton = document.getElementById('mobileSidebarClose');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    const themeToggle = document.getElementById('logisticsThemeToggle');
    const themeToggleTitle = document.getElementById('themeToggleTitle');
    const themeToggleDescription = document.getElementById('themeToggleDescription');
    const themeMoonIcon = document.getElementById('themeMoonIcon');
    const themeSunIcon = document.getElementById('themeSunIcon');
    const themeToggleTrack = document.getElementById('themeToggleTrack');

    const logoutButton = document.getElementById('logisticsLogoutButton');


    function openSidebar() {
        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.remove('-translate-x-full');
        sidebar.classList.add('translate-x-0');

        sidebarOverlay.classList.remove('invisible', 'opacity-0');
        sidebarOverlay.classList.add('visible', 'opacity-100');

        document.body.classList.add('overflow-hidden');
    }


    function closeSidebar() {
        if (!sidebar || !sidebarOverlay) {
            return;
        }

        sidebar.classList.remove('translate-x-0');
        sidebar.classList.add('-translate-x-full');

        sidebarOverlay.classList.remove('visible', 'opacity-100');
        sidebarOverlay.classList.add('invisible', 'opacity-0');

        document.body.classList.remove('overflow-hidden');
    }


    sidebarOpenButton?.addEventListener('click', openSidebar);
    sidebarCloseButton?.addEventListener('click', closeSidebar);
    sidebarOverlay?.addEventListener('click', closeSidebar);


    sidebar?.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth < 1024) {
                closeSidebar();
            }
        });
    });


    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeSidebar();
        }
    });


    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) {
            sidebarOverlay?.classList.remove('visible', 'opacity-100');
            sidebarOverlay?.classList.add('invisible', 'opacity-0');
            document.body.classList.remove('overflow-hidden');
        }
    });


    const THEME_KEY = 'likhae-theme';


    function getCurrentTheme() {
        return root.classList.contains('dark') ? 'dark' : 'light';
    }


    function updateThemeButton() {
        if (!themeToggle) {
            return;
        }

        const isDark = getCurrentTheme() === 'dark';

        themeToggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');

        if (themeToggleTitle) {
            themeToggleTitle.textContent = 'Appearance';
        }

        if (themeToggleDescription) {
            themeToggleDescription.textContent = 'Light / Dark Mode';
        }

        if (themeMoonIcon && themeSunIcon) {
            if (isDark) {
                themeMoonIcon.classList.add('hidden');
                themeSunIcon.classList.remove('hidden');
            } else {
                themeMoonIcon.classList.remove('hidden');
                themeSunIcon.classList.add('hidden');
            }
        }

        if (themeToggleTrack) {
            themeToggleTrack.textContent = isDark ? 'ON' : 'OFF';
            themeToggleTrack.className = isDark
                ? 'rounded-full bg-primary px-3 py-1 text-xs font-bold text-white'
                : 'rounded-full bg-page-secondary px-3 py-1 text-xs font-bold text-ink';
        }
    }


    function applyTheme(theme) {
        if (theme === 'dark') {
            root.classList.add('dark');
        } else {
            root.classList.remove('dark');
        }

        localStorage.setItem(THEME_KEY, theme);
        updateThemeButton();
    }


    themeToggle?.addEventListener('click', () => {
        const nextTheme = getCurrentTheme() === 'dark' ? 'light' : 'dark';
        applyTheme(nextTheme);
    });


    updateThemeButton();

    initializeParcelScanners();


    logoutButton?.addEventListener('click', () => {
        const confirmed = window.confirm('Do you want to log out?');

        if (!confirmed) {
            return;
        }

        window.location.href = '/';
    });

});
