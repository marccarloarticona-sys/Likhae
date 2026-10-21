@once
<style>
    .lk-workspace-messages-page { display: grid; min-width: 0; min-height: 0; gap: 18px; }
    .lk-workspace-messages-heading { display: grid; justify-items: start; }
    .lk-workspace-messages-heading .lk-workspace-messages-kicker { display: inline-flex; align-items: center; margin-bottom: 7px; padding: 5px 9px; border-radius: 999px; background: #F3E4DE; color: #561C17; font: 900 10px/1.2 Poppins,system-ui,sans-serif; letter-spacing: .16em; text-transform: uppercase; }
    .lk-workspace-messages-heading h1 { margin: 0; color: #3B211B; font-family: "Instrument Serif","Playfair Display",Georgia,"Times New Roman",serif; font-size: clamp(32px,3vw,46px); font-weight: 400; letter-spacing: -.04em; line-height: .98; }
    .lk-workspace-messages-heading p { max-width: 650px; margin: 8px 0 0; color: #987865; font: 400 12px/1.7 Poppins,system-ui,sans-serif; }
    @media (min-width: 768px) {
        .lk-workspace-messages-page .lk-unified-messages .lk-messages-shell { height: max(280px,min(720px,calc(100dvh - 260px))); }
    }
    @media (max-width: 767px) {
        .lk-workspace-messages-page { gap: 10px; }
        .lk-workspace-messages-heading .lk-workspace-messages-kicker,
        .lk-workspace-messages-heading p { display: none; }
        .lk-workspace-messages-heading h1 { font-size: 32px; line-height: 1; }
    }
    html.dark .lk-workspace-messages-heading h1 { color: #F5EFE8; }
    html.dark .lk-workspace-messages-heading p { color: #C8B7AD; }
</style>
@endonce

<header class="lk-workspace-messages-heading">
    <span class="lk-workspace-messages-kicker">Communication</span>
    <h1>Messages</h1>
    <p>{{ $description ?? 'Keep your conversations in one polished LIKHAE inbox.' }}</p>
</header>
