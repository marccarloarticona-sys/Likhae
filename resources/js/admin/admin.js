import '../shared/echo.js';
import '../shared/feedback.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';

document.addEventListener('DOMContentLoaded', () => {
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const shell = one('[data-admin-shell]');
    const realtimeCard = one('[data-realtime-transport-card]');
    if (realtimeCard) {
        const mode = one('[data-transport-mode]', realtimeCard);
        const socketValue = one('[data-transport-socket]', realtimeCard);
        const serverValue = one('[data-transport-server]', realtimeCard);
        const checkedValue = one('[data-transport-checked]', realtimeCard);
        const detail = one('[data-transport-detail]', realtimeCard);
        let serverConfigured = realtimeCard.dataset.serverConfigured === 'true';
        let broadcastUnavailable = realtimeCard.dataset.broadcastUnavailable === 'true';
        let serverKnown = true;
        let refreshing = false;

        const labelForSocketState = (state) => ({
            connected: 'Connected',
            connecting: 'Connecting',
            initialized: 'Starting',
            disconnected: 'Disconnected',
            unavailable: 'Unavailable',
            failed: 'Failed',
        }[state] || 'Not connected');
        const connection = window.Echo?.connector?.pusher?.connection;
        let socketState = connection?.state || 'unavailable';

        const update = () => {
            const live = serverKnown && serverConfigured && !broadcastUnavailable && socketState === 'connected';
            const fallbackActive = serverKnown && broadcastUnavailable;
            const driver = realtimeCard.dataset.broadcastConnection || 'reverb';

            mode.textContent = live ? 'Reverb live' : 'Polling fallback';
            mode.classList.remove('is-clear', 'is-pending', 'is-failed', 'is-unknown', 'is-not-configured');
            mode.classList.add(live ? 'is-clear' : 'is-pending');
            socketValue.textContent = labelForSocketState(socketState);
            serverValue.textContent = !serverConfigured
                ? `Polling via ${driver.toUpperCase()}`
                : (fallbackActive ? 'Reverb fallback active' : (serverKnown ? 'Reverb configured' : 'Status unavailable'));

            if (!serverConfigured) {
                detail.textContent = `The server broadcaster is ${driver.toUpperCase()}; message updates use polling.`;
            } else if (fallbackActive) {
                detail.textContent = 'A recent server broadcast failed. Message updates are using polling while Reverb recovers.';
            } else if (!serverKnown) {
                detail.textContent = 'The server broadcast status could not be refreshed, so polling is shown as the safe update mode.';
            } else if (live) {
                detail.textContent = 'Reverb is connected. Message updates switch to polling automatically if the socket drops.';
            } else {
                detail.textContent = `Reverb is ${labelForSocketState(socketState).toLowerCase()}; message updates are using polling while it reconnects.`;
            }
        };

        connection?.bind('state_change', (states) => {
            socketState = states.current;
            update();
        });
        update();

        const refreshServerStatus = async () => {
            if (refreshing) return;
            refreshing = true;
            try {
                const response = await fetch(realtimeCard.dataset.statusUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('Status request failed');
                const status = await response.json();
                serverConfigured = status.configured === true;
                broadcastUnavailable = status.broadcast_unavailable === true;
                serverKnown = true;
                realtimeCard.dataset.broadcastConnection = status.broadcast_connection || 'not configured';
                if (status.checked_at) {
                    const checkedAt = new Date(status.checked_at);
                    checkedValue.textContent = Number.isNaN(checkedAt.getTime()) ? 'Not available' : checkedAt.toLocaleString();
                }
            } catch (_) {
                serverKnown = false;
            } finally {
                refreshing = false;
                update();
            }
        };

        window.setInterval(refreshServerStatus, 15000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') refreshServerStatus();
        });
    }
    const sidebar = one('[data-admin-sidebar]');
    const mobileMenu = one('[data-admin-mobile-menu]');
    const overlay = one('[data-admin-overlay]');
    const collapseToggle = one('[data-admin-sidebar-toggle]');
    const storageKey = 'likhae-admin-sidebar-collapsed';

    const setCollapsed = (collapsed) => {
        if (!shell || window.innerWidth <= 1024) return;
        shell.classList.toggle('is-collapsed', collapsed);
        collapseToggle?.setAttribute('aria-expanded', String(!collapsed));
        try { localStorage.setItem(storageKey, collapsed ? '1' : '0'); } catch (_) {}
    };

    try { setCollapsed(localStorage.getItem(storageKey) === '1'); } catch (_) {}

    collapseToggle?.addEventListener('click', () => {
        if (window.innerWidth <= 1024) {
            shell?.classList.remove('is-mobile-open');
            return;
        }
        setCollapsed(!shell?.classList.contains('is-collapsed'));
    });

    const closeMobileMenu = () => shell?.classList.remove('is-mobile-open');
    mobileMenu?.addEventListener('click', () => shell?.classList.add('is-mobile-open'));
    overlay?.addEventListener('click', closeMobileMenu);
    all('.ad-sidebar a', sidebar || document).forEach((link) => link.addEventListener('click', () => {
        if (window.innerWidth <= 1024) closeMobileMenu();
    }));

    all('[data-admin-nav-toggle]').forEach((toggle) => {
        toggle.addEventListener('click', () => {
            if (window.innerWidth > 1024 && shell?.classList.contains('is-collapsed')) {
                setCollapsed(false);
            }
            const group = toggle.closest('.ad-nav-group');
            const submenu = one('[data-admin-submenu]', group);
            if (!submenu) return;
            const willOpen = submenu.hidden;
            all('[data-admin-nav-toggle]').forEach((other) => {
                if (other === toggle || other.closest('.ad-nav-group')?.classList.contains('is-current')) return;
                other.classList.remove('is-open');
                other.setAttribute('aria-expanded', 'false');
                const otherMenu = one('[data-admin-submenu]', other.closest('.ad-nav-group'));
                if (otherMenu) otherMenu.hidden = true;
            });
            submenu.hidden = !willOpen;
            toggle.classList.toggle('is-open', willOpen);
            toggle.setAttribute('aria-expanded', String(willOpen));
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) closeMobileMenu();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMobileMenu();
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            one('.ad-search input')?.focus();
        }
    });

    const toastRegion = one('[data-toast-region]');
    const showToast = (message) => {
        if (window.lkFeedback) return window.lkFeedback.show(message);
        if (!toastRegion || !message) return;
        const toast = document.createElement('div');
        toast.className = 'ad-toast';
        toast.textContent = message;
        toastRegion.append(toast);
        window.setTimeout(() => toast.remove(), 3200);
    };

    all('[data-filter-input]').forEach((input) => {
        const target = input.dataset.filterInput ? one(input.dataset.filterInput) : input.closest('[data-filter-scope]');
        const filter = () => {
            const term = input.value.trim().toLowerCase();
            const items = all('[data-filter-item]', target || document);
            let shown = 0;
            items.forEach((item) => {
                const matches = (item.dataset.search || item.textContent).toLowerCase().includes(term);
                item.hidden = !matches;
                if (matches) shown += 1;
            });
            const count = one('[data-visible-count]', target || document);
            if (count) count.textContent = String(shown);
        };
        input.addEventListener('input', filter);
        if (input.value) filter();
    });

    all('[data-select-all]').forEach((control) => {
        control.addEventListener('change', () => {
            const table = control.closest('table');
            all('tbody input[type="checkbox"]', table || document).forEach((checkbox) => { checkbox.checked = control.checked; });
        });
    });

    all('[data-conversation]').forEach((conversation) => {
        conversation.addEventListener('click', () => {
            all('[data-conversation]').forEach((item) => item.classList.remove('is-active'));
            conversation.classList.add('is-active');
            const name = conversation.dataset.name || 'Marketplace user';
            const subject = conversation.dataset.subject || 'Support conversation';
            const title = one('[data-chat-name]');
            const detail = one('[data-chat-subject]');
            if (title) title.textContent = name;
            if (detail) detail.textContent = subject;
            showToast(`Opened conversation with ${name}.`);
        });
    });

    all('[data-chat-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const input = one('input', form);
            const body = one('[data-chat-body]');
            const message = input?.value.trim();
            if (!message || !body) return;
            const bubble = document.createElement('div');
            bubble.className = 'ad-bubble is-admin';
            bubble.textContent = message;
            const time = document.createElement('time');
            time.textContent = 'Just now';
            bubble.append(time);
            body.append(bubble);
            input.value = '';
            body.scrollTop = body.scrollHeight;
        });
    });
});
