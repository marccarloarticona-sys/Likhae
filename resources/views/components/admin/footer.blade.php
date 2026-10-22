<footer class="lk-admin-footer">
    <span>© {{ date('Y') }} LIKHAE Marketplace · Admin Center</span>
    <nav aria-label="Admin footer links">
        <a href="{{ route('admin.settings', ['tab' => 'policies']) }}">Policies</a>
        <a href="{{ route('admin.settings', ['tab' => 'audit']) }}">Audit Logs</a>
        <a href="{{ route('admin.account', ['tab' => 'security']) }}">Security</a>
    </nav>
</footer>
