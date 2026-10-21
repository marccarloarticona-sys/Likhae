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

    if (saved === 'dark') root.classList.add('dark');

    function syncBadge() {
        const dark = root.classList.contains('dark');
        const badge = document.getElementById('themeToggleBadge');
        if (!badge) return;
        badge.textContent = dark ? 'ON' : 'OFF';
        badge.style.background = dark ? '#c92d2f' : '#e5e5e5';
        badge.style.color      = dark ? '#fff'    : '#333';
    }

    document.addEventListener('DOMContentLoaded', function () {
        syncBadge();
        document.getElementById('themeToggle')?.addEventListener('click', function () {
            const dark = root.classList.toggle('dark');
            localStorage.setItem('likhae-theme', dark ? 'dark' : 'light');
            syncBadge();
        });
    });
})();
</script>
