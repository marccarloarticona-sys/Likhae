@extends('layouts.buyer')

@section('title', 'Home')

@push('head')
<style>
    :root {
        --lk-bg: #FBF7F2;
        --lk-bg-soft: #F6EFE7;
        --lk-bg-alt: #EFE7DE;
        --lk-card: #FFFDF9;

        --lk-border: #EADCCC;
        --lk-border-strong: #DBCEC1;

        --lk-maroon: #561C17;
        --lk-maroon-2: #642920;
        --lk-maroon-light: #7A2A22;
        --lk-maroon-dark: #3E130F;

        --lk-text: #3B211B;
        --lk-text-dark: #1C160F;
        --lk-brown: #6C4936;
        --lk-muted: #987865;
        --lk-muted-2: #A99386;

        --lk-tan: #C19771;
        --lk-gold: #C19771;
        --lk-star: #C88418;

        --lk-footer: #561C17;
        --lk-footer-dark: #3A120F;
    }

    body,
    .lk-buyer-body,
    .lk-page {
        background: var(--lk-bg) !important;
        color: var(--lk-text) !important;
    }

    .lk-buyer-body .likhae-logo--buyer-home {
        padding: 0 !important;
        background: transparent !important;
    }

    .lk-buyer-body .likhae-logo--buyer-home .likhae-logo__image--light {
        display: block !important;
    }

    .lk-buyer-body .likhae-logo--buyer-home .likhae-logo__image--dark {
        display: none !important;
    }

    .lk-page {
        min-height: 100vh !important;
        padding: 40px clamp(18px, 2.4vw, 36px) 56px !important;
    }

    .lk-section {
        margin-top: 34px !important;
    }

    .lk-kicker {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;

        margin-bottom: 8px !important;

        color: var(--lk-maroon) !important;

        font-size: 10px !important;
        font-weight: 800 !important;
        letter-spacing: 0.22em !important;
        text-transform: uppercase !important;
    }

    .lk-section-head {
        display: flex !important;
        align-items: end !important;
        justify-content: space-between !important;
        gap: 20px !important;

        margin-bottom: 18px !important;
    }

    .lk-section-head h2 {
        margin: 0 !important;

        color: var(--lk-text) !important;

        font-family: "Instrument Serif", Georgia, serif !important;
        font-size: clamp(32px, 3vw, 46px) !important;
        font-weight: 400 !important;
        line-height: 0.98 !important;
        letter-spacing: -0.035em !important;
    }

    .lk-section-head p {
        margin: 8px 0 0 !important;
        color: var(--lk-muted) !important;
        font-size: 13px !important;
        line-height: 1.7 !important;
    }

    .lk-text-link {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;

        color: var(--lk-brown) !important;

        font-size: 12px !important;
        font-weight: 700 !important;
        text-decoration: none !important;

        transition: 160ms ease !important;
    }

    .lk-text-link:hover {
        color: var(--lk-maroon) !important;
    }

    /* =====================================================
       HERO PALETTE OVERRIDE
    ====================================================== */

    .lk-hero,
    .lk-marketplace-hero,
    .lk-ed-hero {
        overflow: hidden !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 26px !important;

        background:
            radial-gradient(circle at 0% 20%, rgba(193, 151, 113, 0.18), transparent 28%),
            radial-gradient(circle at 100% 0%, rgba(86, 28, 23, 0.08), transparent 32%),
            linear-gradient(135deg, #FFFDF9 0%, #F6EFE7 56%, #EFE7DE 100%) !important;

        color: var(--lk-text) !important;

        box-shadow: 0 18px 46px rgba(86, 28, 23, 0.08) !important;
    }

    .lk-hero h1,
    .lk-marketplace-hero h1,
    .lk-ed-hero h1 {
        color: var(--lk-maroon) !important;

        font-family: "Instrument Serif", Georgia, serif !important;
        font-weight: 400 !important;
        letter-spacing: -0.05em !important;
        line-height: 0.92 !important;
    }

    .lk-hero h1 span,
    .lk-marketplace-hero h1 span,
    .lk-ed-hero h1 span {
        color: var(--lk-maroon) !important;
        font-style: italic !important;
    }

    .lk-hero p,
    .lk-marketplace-hero p,
    .lk-ed-hero p {
        color: var(--lk-text) !important;
    }

    .lk-hero-copy,
    .lk-marketplace-hero-copy,
    .lk-ed-hero-copy {
        color: var(--lk-text) !important;
    }

    .lk-hero-showcase,
    .lk-hero-product,
    .lk-marketplace-hero-visual,
    .lk-ed-hero-visual {
        background: transparent !important;
    }

    .lk-btn,
    .lk-btn-red,
    .lk-ed-btn-primary {
        border-radius: 9px !important;
        font-size: 12px !important;
        font-weight: 700 !important;
        text-decoration: none !important;
    }

    .lk-btn-red,
    .lk-ed-btn-primary {
        border: 1px solid var(--lk-maroon) !important;
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
        box-shadow: 0 8px 18px rgba(86, 28, 23, 0.16) !important;
    }

    .lk-btn-red:hover,
    .lk-ed-btn-primary:hover {
        border-color: var(--lk-maroon-dark) !important;
        background: var(--lk-maroon-dark) !important;
    }

    .lk-btn-light,
    .lk-ed-btn-outline {
        border: 1px solid var(--lk-tan) !important;
        background: rgba(255, 253, 249, 0.78) !important;
        color: var(--lk-maroon) !important;
    }

    .lk-btn-light:hover,
    .lk-ed-btn-outline:hover {
        background: var(--lk-bg-alt) !important;
        color: var(--lk-maroon-dark) !important;
    }

    /* =====================================================
       QUICK ACTIONS
    ====================================================== */

    .lk-quick-grid {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 16px !important;
    }

    .lk-quick-card {
        display: flex !important;
        align-items: center !important;
        gap: 14px !important;

        min-height: 88px !important;
        padding: 18px !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 18px !important;

        background: var(--lk-card) !important;
        color: var(--lk-text) !important;

        text-decoration: none !important;

        box-shadow: 0 8px 24px rgba(86, 28, 23, 0.045) !important;

        transition: 180ms ease !important;
    }

    .lk-quick-card:hover {
        transform: translateY(-2px) !important;
        border-color: var(--lk-tan) !important;
        box-shadow: 0 14px 34px rgba(86, 28, 23, 0.08) !important;
    }

    .lk-quick-icon {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;

        width: 48px !important;
        height: 48px !important;

        border-radius: 999px !important;

        background: #F1E4D7 !important;
        color: var(--lk-maroon) !important;

        flex-shrink: 0 !important;
    }

    .lk-quick-icon svg {
        width: 24px !important;
        height: 24px !important;
    }

    .lk-quick-card strong {
        display: block !important;
        color: var(--lk-text) !important;
        font-size: 14px !important;
        font-weight: 800 !important;
    }

    .lk-quick-card small {
        display: block !important;
        margin-top: 3px !important;
        color: var(--lk-muted) !important;
        font-size: 12px !important;
    }

    /* =====================================================
       CATEGORIES
    ====================================================== */

    .lk-category-grid {
        display: grid !important;
        grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
        gap: 14px !important;
    }

    .lk-category-card {
        min-height: 138px !important;
        padding: 18px 14px !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 17px !important;

        background: var(--lk-card) !important;
        color: var(--lk-text) !important;

        text-align: center !important;
        text-decoration: none !important;

        box-shadow: 0 7px 22px rgba(86, 28, 23, 0.045) !important;

        transition: 180ms ease !important;
    }

    .lk-category-card:hover {
        transform: translateY(-3px) !important;
        border-color: var(--lk-tan) !important;
        box-shadow: 0 14px 32px rgba(86, 28, 23, 0.08) !important;
    }

    .lk-category-icon {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;

        width: 52px !important;
        height: 52px !important;

        margin-bottom: 14px !important;

        border-radius: 999px !important;

        background: #F1E4D7 !important;
        color: var(--lk-maroon) !important;
    }

    .lk-category-icon svg {
        width: 25px !important;
        height: 25px !important;

        fill: none !important;
        stroke: currentColor !important;
        stroke-width: 1.7 !important;
    }

    .lk-category-card strong {
        display: block !important;

        color: var(--lk-text) !important;

        font-size: 13px !important;
        font-weight: 800 !important;
    }

    .lk-category-card small {
        display: block !important;

        margin-top: 5px !important;

        color: var(--lk-muted) !important;

        font-size: 11px !important;
        font-weight: 600 !important;
    }

    /* =====================================================
       PRODUCT CARDS
    ====================================================== */

    .lk-product-grid {
        display: grid !important;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 16px !important;
    }

    .lk-product-card {
        overflow: hidden !important;

        border: 1px solid var(--lk-border) !important;
        border-radius: 15px !important;

        background: var(--lk-card) !important;
        color: var(--lk-text) !important;

        box-shadow: 0 6px 20px rgba(86, 28, 23, 0.045) !important;

        transition: 180ms ease !important;
    }

    .lk-product-card:hover {
        transform: translateY(-3px) !important;
        border-color: var(--lk-tan) !important;
        box-shadow: 0 14px 32px rgba(86, 28, 23, 0.08) !important;
    }

    .lk-product-media,
    .lk-product-placeholder {
        background: #F3ECE4 !important;
    }

    .lk-product-body {
        background: var(--lk-card) !important;
    }

    .lk-product-category {
        color: var(--lk-brown) !important;
        font-size: 10px !important;
        font-weight: 700 !important;
    }

    .lk-product-name,
    .lk-product-title-link {
        color: var(--lk-text) !important;
        font-size: 14px !important;
        font-weight: 800 !important;
        text-decoration: none !important;
    }

    .lk-product-title-link:hover {
        color: var(--lk-maroon) !important;
    }

    .lk-product-seller,
    .lk-product-sold {
        color: var(--lk-muted) !important;
    }

    .lk-product-price-row strong,
    .lk-product-price,
    .lk-product-card strong[class*="price"] {
        color: var(--lk-maroon) !important;
    }

    .lk-product-price-row del {
        color: var(--lk-muted-2) !important;
    }

    .lk-product-discount {
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
    }

    .lk-btn-view,
    .lk-product-card button,
    .lk-product-card .lk-btn {
        border-color: var(--lk-maroon) !important;
    }

    .lk-btn-view {
        background: transparent !important;
        color: var(--lk-maroon) !important;
    }

    .lk-btn-view:hover {
        background: var(--lk-maroon) !important;
        color: #FFFFFF !important;
    }

    /* =====================================================
       FLASH PICKS / DEAL BANNER
    ====================================================== */

    .lk-deal-banner {
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto auto !important;
        align-items: center !important;
        gap: 24px !important;

        padding: 34px 42px !important;

        border: 1px solid rgba(255, 255, 255, 0.16) !important;
        border-radius: 22px !important;

        background:
            radial-gradient(circle at 92% 18%, rgba(255, 255, 255, 0.16), transparent 24%),
            linear-gradient(135deg, var(--lk-maroon) 0%, var(--lk-maroon-2) 52%, var(--lk-maroon-dark) 100%) !important;

        color: #FFF7EF !important;

        box-shadow: 0 18px 44px rgba(86, 28, 23, 0.18) !important;
    }

    .lk-deal-banner .lk-kicker {
        color: #E8C8B2 !important;
    }

    .lk-deal-banner h2 {
        margin: 0 !important;

        color: #FFFFFF !important;

        font-family: "Instrument Serif", Georgia, serif !important;
        font-size: clamp(36px, 4vw, 58px) !important;
        font-weight: 400 !important;
        line-height: 0.95 !important;
        letter-spacing: -0.04em !important;
    }

    .lk-deal-banner p {
        margin: 8px 0 0 !important;
        color: #F4DED4 !important;
        font-size: 13px !important;
        line-height: 1.7 !important;
    }

    .lk-countdown {
        display: inline-flex !important;
        align-items: center !important;
        gap: 8px !important;

        padding: 8px 12px !important;

        border: 1px solid rgba(255, 255, 255, 0.22) !important;
        border-radius: 14px !important;

        background: rgba(255, 255, 255, 0.08) !important;
    }

    .lk-countdown div {
        min-width: 48px !important;
        text-align: center !important;
    }

    .lk-countdown b {
        display: block !important;
        color: #FFFFFF !important;
        font-size: 18px !important;
        font-weight: 800 !important;
        line-height: 1 !important;
    }

    .lk-countdown small {
        display: block !important;
        margin-top: 4px !important;
        color: #E8C8B2 !important;
        font-size: 9px !important;
        text-transform: uppercase !important;
        letter-spacing: 0.12em !important;
    }

    .lk-countdown span {
        color: #E8C8B2 !important;
        font-weight: 800 !important;
    }

    .lk-btn-gold {
        border: 1px solid #FFF7EF !important;
        background: #FFF7EF !important;
        color: var(--lk-maroon) !important;
        box-shadow: 0 10px 22px rgba(0, 0, 0, 0.08) !important;
    }

    .lk-btn-gold:hover {
        background: #F6EFE7 !important;
        color: var(--lk-maroon-dark) !important;
    }

    /* =====================================================
       EMPTY STATES
    ====================================================== */

    .lk-page .rounded-2xl {
        border-color: var(--lk-border) !important;
        background: var(--lk-card) !important;
        color: var(--lk-text) !important;
    }

    .lk-page .text-stone-900 {
        color: var(--lk-text) !important;
    }

    .lk-page .text-stone-500 {
        color: var(--lk-muted) !important;
    }

    .lk-page .bg-red-50 {
        background: #F1E4D7 !important;
    }

    .lk-page .text-red-800,
    .lk-page .text-red-900 {
        color: var(--lk-maroon) !important;
    }

    .lk-page .bg-red-900 {
        background: var(--lk-maroon) !important;
    }

    .lk-page .hover\:bg-red-950:hover {
        background: var(--lk-maroon-dark) !important;
    }

    .lk-platform-events-empty {
        padding: 28px clamp(20px, 3vw, 38px);
        border: 1px solid var(--lk-border);
        border-radius: 22px;
        background:
            radial-gradient(circle at 94% 12%, rgba(193, 151, 113, 0.2), transparent 28%),
            linear-gradient(135deg, #FFFDF9 0%, #F6EFE7 64%, #EFE7DE 100%);
        box-shadow: 0 12px 32px rgba(86, 28, 23, 0.07);
    }

    .lk-platform-events-empty h3 {
        margin: 5px 0 0;
        color: var(--lk-text);
        font-family: "Instrument Serif", Georgia, serif;
        font-size: clamp(26px, 3vw, 36px);
        font-weight: 400;
        line-height: 1.05;
    }

    .lk-platform-events-empty p {
        max-width: 620px;
        margin: 8px 0 0;
        color: var(--lk-muted);
        font-size: 12px;
        line-height: 1.7;
    }

    .lk-platform-events-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
    .lk-platform-events-grid.is-single-event { grid-template-columns: minmax(0, 1fr); }
    .lk-event-card { position: relative; overflow: hidden; display: grid; gap: 20px; padding: clamp(22px, 3vw, 34px); border: 0; border-radius: 24px; background: radial-gradient(circle at 86% 18%, rgba(193,151,113,.24), transparent 24%), linear-gradient(115deg, #561C17 0%, #642920 56%, #3E130F 100%); color: #FFF8F2; box-shadow: 0 18px 42px rgba(86,28,23,.18); }
    .lk-event-card__top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .lk-event-card__eyebrow { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    .lk-event-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 11px; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .lk-event-status.is-active { background: #E8F3EA; color: #256F4A; }
    .lk-event-status.is-upcoming { background: #F8EDD9; color: #8A5A12; }
    .lk-event-card__hero { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
    .lk-event-card__title { margin: 0; color: #FFF8F2; font: 400 clamp(34px, 4vw, 52px)/1 "Instrument Serif", Georgia, serif; letter-spacing: -.025em; }
    .lk-event-card__offer { min-width: 170px; padding: 13px 24px; border: 1px solid rgba(233,199,165,.5); border-radius: 18px; background: rgba(255,248,242,.1); text-align: center; }
    .lk-event-card__offer strong { display: block; color: #FFF8F2; font-size: clamp(34px, 4vw, 48px); font-weight: 800; letter-spacing: -.04em; line-height: 1; }
    .lk-event-card__offer span { display: block; margin-top: 5px; color: #E9C7A5; font-size: 10px; font-weight: 900; letter-spacing: .22em; }
    .lk-event-card__details { display: grid; grid-template-columns: minmax(150px, .8fr) minmax(150px, .8fr) minmax(300px, 1.8fr); gap: 18px; margin: 0; }
    .lk-event-card__details > div { padding-top: 13px; border-top: 1px solid rgba(255,248,242,.2); }
    .lk-event-card__details dt { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
    .lk-event-card__details dd { margin: 5px 0 0; color: #FFF8F2; font-size: 14px; font-weight: 700; line-height: 1.5; }
    .lk-event-card__footer { display: flex; justify-content: flex-end; }
    .lk-event-card__action { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 44px; padding: 0 20px; border-radius: 999px; background: #E9C7A5; color: #3E130F; font-size: 12px; font-weight: 900; text-decoration: none; transition: transform .18s ease, background .18s ease; }
    .lk-event-card__action:hover { transform: translateY(-1px); background: #F3D9BD; }

    /* =====================================================
       DARK MODE SAFE OVERRIDE
    ====================================================== */

    html.dark body,
    html.dark .lk-buyer-body,
    html.dark .lk-page {
        background: #171210 !important;
        color: #FFF8F2 !important;
    }

    html.dark .lk-platform-events-empty {
        border-color: #49342B;
        background:
            radial-gradient(circle at 94% 12%, rgba(193, 151, 113, 0.1), transparent 28%),
            linear-gradient(135deg, #241A17 0%, #211815 64%, #1E1714 100%);
    }

    html.dark .lk-platform-events-empty h3 {
        color: #FFF8F2;
    }

    html.dark .lk-platform-events-empty p {
        color: #C8B7AD;
    }

    @media (max-width: 700px) {
        .lk-platform-events-grid { grid-template-columns: minmax(0, 1fr); }
        .lk-event-card__details { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .lk-event-card__validity { grid-column: 1 / -1; }
    }

    @media (max-width: 520px) {
        .lk-event-card__hero { align-items: flex-start; flex-direction: column; gap: 16px; }
        .lk-event-card__offer { min-width: 0; }
        .lk-event-card__details { grid-template-columns: minmax(0, 1fr); }
        .lk-event-card__validity { grid-column: 1; }
        .lk-event-card__footer { justify-content: flex-start; }
    }

    html.dark .lk-section-head h2,
    html.dark .lk-quick-card strong,
    html.dark .lk-category-card strong {
        color: #FFF8F2 !important;
    }

    html.dark .lk-section-head p,
    html.dark .lk-quick-card small,
    html.dark .lk-category-card small {
        color: #C8B7AD !important;
    }

    html.dark .lk-quick-card,
    html.dark .lk-category-card,
    html.dark .lk-product-card,
    html.dark .lk-page .rounded-2xl {
        background: #241A17 !important;
        border-color: #49342B !important;
        color: #FFF8F2 !important;
    }

    html.dark .lk-quick-icon,
    html.dark .lk-category-icon {
        background: #382820 !important;
        color: #EBA99D !important;
    }

    /* =====================================================
       RESPONSIVE
    ====================================================== */

    @media (max-width: 1180px) {
        .lk-category-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }

        .lk-product-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        }

        .lk-deal-banner {
            grid-template-columns: 1fr !important;
        }
    }

    @media (max-width: 900px) {
        .lk-page {
            padding: 30px clamp(18px, 2.4vw, 24px) 44px !important;
        }

        .lk-section-head {
            align-items: flex-start !important;
            flex-direction: column !important;
        }

        .lk-quick-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .lk-category-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .lk-product-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .lk-deal-banner {
            padding: 28px 22px !important;
        }
    }

    @media (max-width: 560px) {
        .lk-page {
            padding-top: 24px !important;
            padding-right: 16px !important;
            padding-left: 16px !important;
        }

        .lk-quick-grid,
        .lk-category-grid,
        .lk-product-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        }

        .lk-countdown {
            width: 100% !important;
            justify-content: center !important;
        }
    }
</style>
@endpush

@section('content')
@php
    /*
    |--------------------------------------------------------------------------
    | Product collection
    |--------------------------------------------------------------------------
    */

    if (isset($buyerProducts) && method_exists($buyerProducts, 'items')) {
        $products = collect($buyerProducts->items());
    } else {
        $products = collect($buyerProducts ?? []);
    }

    $featuredProducts = $products->take(4);
    $recommendedProducts = $products->skip(4)->take(4);

    /*
    |--------------------------------------------------------------------------
    | Front-end-safe optional routes
    |--------------------------------------------------------------------------
    */

    $hasWishlistRoute = \Illuminate\Support\Facades\Route::has('buyer.wishlist');

    $wishlistUrl = $hasWishlistRoute
        ? route('buyer.wishlist')
        : '#';

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    */

    $categories = [
        [
            'name' => 'Fashion',
            'slug' => 'womens-apparel',
            'icon' => '<path d="M8 4h8l4 4-3 3-2-2v11H9V9l-2 2-3-3 4-4Z"/>',
        ],
        [
            'name' => 'Electronics',
            'slug' => 'electronics-and-gadgets',
            'icon' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 7h6M10 17h4"/>',
        ],
        [
            'name' => 'Home',
            'slug' => 'home-and-garden',
            'icon' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/>',
        ],
        [
            'name' => 'Books',
            'slug' => 'books-and-media',
            'icon' => '<path d="M4 5a3 3 0 0 1 3-2h5v17H7a3 3 0 0 0-3 2V5Z"/><path d="M20 5a3 3 0 0 0-3-2h-5v17h5a3 3 0 0 1 3 2V5Z"/>',
        ],
        [
            'name' => 'Beauty',
            'slug' => 'health-and-beauty',
            'icon' => '<path d="M9 3h6v5l3 4v9H6v-9l3-4V3Z"/><path d="M9 8h6M9 14h6"/>',
        ],
        [
            'name' => 'Sports',
            'slug' => 'sports-and-outdoors',
            'icon' => '<circle cx="12" cy="12" r="9"/><path d="M5 7c4 2 10 2 14 0M5 17c4-2 10-2 14 0M12 3c-3 5-3 13 0 18"/>',
        ],
        [
            'name' => 'Toys',
            'slug' => 'kids-and-baby-toys',
            'icon' => '<path d="M8 9V6a4 4 0 0 1 8 0v3"/><rect x="4" y="9" width="16" height="11" rx="2"/><path d="M9 14h6M12 11v6"/>',
        ],
        [
            'name' => 'Handmade Goods',
            'slug' => 'handmade-goods',
            'icon' => '<path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M8 12h8M8 16h5"/>',
        ],
    ];
@endphp

<div class="lk-page lk-home-page">

    {{-- Hero --}}
    <x-buyer.marketplace-hero
        :products="$heroProducts ?? collect()"
        :browse-url="route('buyer.products')"
        :categories-url="route('buyer.products')"
        :orders-url="route('buyer.orders')"
    />

    {{-- Quick actions --}}
    <section class="lk-section" aria-labelledby="quick-actions-heading">
        <div class="sr-only">
            <h2 id="quick-actions-heading">Quick Actions</h2>
        </div>

        <div class="lk-quick-grid">
            <a
                href="{{ route('buyer.orders') }}"
                class="lk-quick-card"
            >
                <div class="lk-quick-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M6 2h12l2 4v16H4V6z"/>
                        <path d="M6 6h12"/>
                        <path d="M8 11h8"/>
                        <path d="M8 15h6"/>
                    </svg>
                </div>

                <div>
                    <strong>My Purchases</strong>
                    <small>Track and review</small>
                </div>
            </a>

            <a
                href="{{ $wishlistUrl }}"
                class="lk-quick-card"
                @if (!$hasWishlistRoute)
                    data-frontend-placeholder
                    onclick="return false;"
                @endif
            >
                <div class="lk-quick-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78L12 21.23l8.84-8.84a5.5 5.5 0 0 0 0-7.78Z"/>
                    </svg>
                </div>

                <div>
                    <strong>Wishlist</strong>
                    <small>Saved products</small>
                </div>
            </a>

            <a
                href="{{ route('buyer.messages') }}"
                class="lk-quick-card"
            >
                <div class="lk-quick-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M4 5h16v11H8l-4 4z"/>
                        <path d="M8 9h8"/>
                        <path d="M8 13h5"/>
                    </svg>
                </div>

                <div>
                    <strong>Messages</strong>
                    <small>Chat with sellers</small>
                </div>
            </a>

            <a
                href="{{ route('buyer.rewards') }}"
                class="lk-quick-card"
            >
                <div class="lk-quick-icon">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.8"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/>
                        <path d="M2 7h20v5H2z"/>
                        <path d="M12 7v14"/>
                        <path d="M12 7H7.5A2.5 2.5 0 1 1 10 4.5z"/>
                        <path d="M12 7h4.5A2.5 2.5 0 1 0 14 4.5z"/>
                    </svg>
                </div>

                <div>
                    <strong>Vouchers</strong>
                    <small>Available vouchers</small>
                </div>
            </a>
        </div>
    </section>

    {{-- Categories --}}
    <section
        class="lk-section"
        aria-labelledby="categories-heading"
    >
        <div class="lk-section-head">
            <div>
                <span class="lk-kicker">Browse</span>

                <h2 id="categories-heading">
                    Shop by Category
                </h2>

                <p>
                    Explore products from different collections.
                </p>
            </div>

            <a
                href="{{ route('buyer.products') }}"
                class="lk-text-link"
            >
                View All
                <span aria-hidden="true">→</span>
            </a>
        </div>

        <div class="lk-category-grid">
            @foreach ($categories as $category)
                <a
                    href="{{ route('buyer.products', ['category' => $category['slug']]) }}"
                    class="lk-category-card"
                >
                    <div class="lk-category-icon">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            {!! $category['icon'] !!}
                        </svg>
                    </div>

                    <strong>{{ $category['name'] }}</strong>
                    <small>Explore</small>
                </a>
            @endforeach
        </div>
    </section>

    {{-- Featured products --}}
    <section
        class="lk-section"
        aria-labelledby="featured-products-heading"
    >
        <div class="lk-section-head">
            <div>
                <span class="lk-kicker">Curated</span>

                <h2 id="featured-products-heading">
                    Featured Products
                </h2>

                <p>
                    Top picks from trusted sellers.
                </p>
            </div>

            <a
                href="{{ route('buyer.products') }}"
                class="lk-text-link"
            >
                See All
                <span aria-hidden="true">→</span>
            </a>
        </div>

        @if ($featuredProducts->isNotEmpty())
            <div class="lk-product-grid">
                @foreach ($featuredProducts as $product)
                    <x-buyer.product-card :product="$product"/>
                @endforeach
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-12 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-red-800">
                    <svg
                        width="27"
                        height="27"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M6 8h12l1 13H5z"/>
                        <path d="M9 10V6a3 3 0 0 1 6 0v4"/>
                    </svg>
                </div>

                <h3 class="mt-4 text-sm font-semibold text-stone-900">
                    Featured products are coming soon
                </h3>

                <p class="mt-1 text-xs text-stone-500">
                    Products from trusted local sellers will appear here.
                </p>
            </div>
        @endif
    </section>

    {{-- Flash picks --}}
    <section
        id="flash-picks"
        class="lk-section scroll-mt-24"
        aria-labelledby="flash-picks-heading"
    >
        <div class="lk-deal-banner">
            <div>
                <span class="lk-kicker">
                    Current Discounts
                </span>

                <h2 id="flash-picks-heading">
                    Current Deals
                </h2>

                <p>
                    Browse products with current seller discounts.
                </p>
            </div>

            <a
                href="{{ route('buyer.flash-deals') }}"
                class="lk-btn lk-btn-gold"
            >
                Shop Deals
            </a>
        </div>
    </section>

    {{-- Current discounted products --}}
    <section
        class="lk-section"
        aria-labelledby="discounted-products-heading"
    >
        <div class="lk-section-head">
            <div>
                <span class="lk-kicker">Seller Discounts</span>

                <h2 id="discounted-products-heading">
                    Deals For You
                </h2>

                <p>
                    Available products with a current variant discount.
                </p>
            </div>

            <a
                href="{{ route('buyer.flash-deals') }}"
                class="lk-text-link"
            >
                See All
                <span aria-hidden="true">→</span>
            </a>
        </div>

        @if ($dealProducts->isNotEmpty())
            <div class="lk-product-grid">
                @foreach ($dealProducts as $product)
                    <x-buyer.product-card :product="$product" deal />
                @endforeach
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center">
                <h3 class="text-sm font-semibold text-stone-900">
                    No discounted products are available right now
                </h3>

                <p class="mt-1 text-xs text-stone-500">
                    Check back later for current seller discounts.
                </p>
            </div>
        @endif
    </section>

    <section class="lk-section" aria-labelledby="platform-events-heading">
        <div class="lk-section-head">
            <div>
                <span class="lk-kicker">LIKHAE Marketplace</span>
                <h2 id="platform-events-heading">Platform Events</h2>
                <p>Marketplace-wide campaigns and events.</p>
            </div>
            <a class="lk-text-link" href="{{ route('buyer.platform-events.index') }}">View Offers <span aria-hidden="true">→</span></a>
        </div>

        @if ($platformCampaigns->isNotEmpty())
            <div class="lk-platform-events-grid {{ $platformCampaigns->count() === 1 ? 'is-single-event' : '' }}">
                @foreach ($platformCampaigns as $campaign)
                    @include('Buyer.platform-events.partials.campaign-card', ['campaign' => $campaign])
                @endforeach
            </div>
        @else
            <div class="lk-platform-events-empty" role="status">
                <span class="lk-kicker">Platform Events</span>
                <h3>No scheduled events right now</h3>
                <p>When a marketplace-wide event is scheduled, its dates and details will appear here.</p>
            </div>
        @endif
    </section>

    {{-- Recommended products --}}
    <section
        class="lk-section"
        aria-labelledby="recommended-products-heading"
    >
        <div class="lk-section-head">
            <div>
                <span class="lk-kicker">For You</span>

                <h2 id="recommended-products-heading">
                    Recommended For You
                </h2>

                <p>
                    Products based on popular buyer choices.
                </p>
            </div>

            <a
                href="{{ route('buyer.products', ['sort' => 'best-rated']) }}"
                class="lk-text-link"
            >
                See All
                <span aria-hidden="true">→</span>
            </a>
        </div>

        @if ($recommendedProducts->isNotEmpty())
            <div class="lk-product-grid">
                @foreach ($recommendedProducts as $product)
                    <x-buyer.product-card :product="$product"/>
                @endforeach
            </div>
        @else
            <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center">
                <h3 class="text-sm font-semibold text-stone-900">
                    More recommendations will appear here
                </h3>

                <p class="mt-1 text-xs text-stone-500">
                    Explore products to help us prepare recommendations for you.
                </p>

                <a
                    href="{{ route('buyer.products') }}"
                    class="mt-4 inline-flex items-center justify-center rounded-xl bg-red-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950"
                >
                    Explore Products
                </a>
            </div>
        @endif
    </section>
</div>
@endsection
