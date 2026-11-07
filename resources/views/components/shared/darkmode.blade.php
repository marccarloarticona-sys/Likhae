{{-- =====================================================
    DARK MODE TOGGLE � shared across all role sidebars
    Reads/writes localStorage key: likhae-theme
    Syncs badge in sidebar with current state
====================================================== --}}
<script>
(function () {
    const root = document.documentElement;

    // Restore on load (also set by <head> initializer if present)
    const saved = localStorage.getItem('likhae-theme') || 'light';
    const themeToggle = document.getElementById('themeToggle');
    const themePreferenceUrl = themeToggle?.dataset.themePreferenceUrl || root.dataset.buyerThemeUpdateUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    if (saved === 'dark') root.classList.add('dark');

    function syncBadge() {
        const dark = root.classList.contains('dark');
        const badge = document.getElementById('themeToggleBadge');
        themeToggle?.setAttribute('aria-pressed', String(dark));
        if (badge) {
            badge.textContent = dark ? badge.dataset.labelOn : badge.dataset.labelOff;
            badge.style.background = dark ? '#c92d2f' : '#e5e5e5';
            badge.style.color      = dark ? '#fff'    : '#333';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncBadge();
        themeToggle?.addEventListener('click', async function () {
            const previousTheme = root.classList.contains('dark') ? 'dark' : 'light';
            const nextTheme = previousTheme === 'dark' ? 'light' : 'dark';
            root.classList.toggle('dark', nextTheme === 'dark');
            try {
                localStorage.setItem('likhae-theme', nextTheme);
            } catch {
                // The theme still applies for this page if storage is unavailable.
            }
            syncBadge();

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
                root.classList.toggle('dark', previousTheme === 'dark');
                try {
                    localStorage.setItem('likhae-theme', previousTheme);
                } catch {
                    // The account preference remains the source of truth.
                }
                syncBadge();
                console.error(error);
            }
        });
    });
})();
</script>
