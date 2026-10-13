import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';

document.addEventListener('DOMContentLoaded', () => {
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const shell = one('[data-admin-shell]');
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
        if (!toastRegion || !message) return;
        const toast = document.createElement('div');
        toast.className = 'ad-toast';
        toast.textContent = message;
        toastRegion.append(toast);
        window.setTimeout(() => toast.remove(), 3200);
    };

    const dialog = one('[data-confirm-dialog]');
    let pendingAction = null;
    all('[data-confirm-action]').forEach((button) => {
        button.addEventListener('click', () => {
            pendingAction = button;
            if (!dialog || typeof dialog.showModal !== 'function') {
                if (window.confirm(button.dataset.confirmMessage || 'Continue with this action?')) {
                    showToast(button.dataset.successMessage || 'Action completed.');
                }
                return;
            }
            one('[data-dialog-title]', dialog).textContent = button.dataset.confirmTitle || 'Confirm action';
            one('[data-dialog-message]', dialog).textContent = button.dataset.confirmMessage || 'Please review this action before continuing.';
            const reasonWrap = one('[data-dialog-reason-wrap]', dialog);
            const reason = one('[data-dialog-reason]', dialog);
            reasonWrap.hidden = button.dataset.requireReason !== 'true';
            reason.value = '';
            dialog.showModal();
        });
    });

    dialog?.addEventListener('close', () => {
        if (dialog.returnValue !== 'confirm' || !pendingAction) {
            pendingAction = null;
            return;
        }
        const reasonRequired = pendingAction.dataset.requireReason === 'true';
        const reason = one('[data-dialog-reason]', dialog)?.value.trim();
        if (reasonRequired && !reason) {
            showToast('A reason is required. The action was not applied.');
            pendingAction = null;
            return;
        }
        showToast(pendingAction.dataset.successMessage || 'Action completed and recorded in the audit log.');
        pendingAction.closest('tr, [data-filter-item]')?.classList.add('is-reviewed');
        pendingAction = null;
    });

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
