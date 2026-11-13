@extends('layouts.buyer.app')

@section('title', 'Home')

@push('head')
<link rel="preload" as="image" href="{{ asset('images/buyer/home-hero-editorial-v3.webp') }}" type="image/webp" fetchpriority="high">
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

        font-family: Georgia, "Instrument Serif", serif !important;
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

        font-family: Georgia, "Instrument Serif", serif !important;
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
        grid-template-columns: repeat(6, minmax(112px, 1fr)) !important;
        gap: 8px !important;
        overflow-x: auto;
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

    .lk-quick-card:focus-visible {
        outline: 3px solid var(--lk-tan) !important;
        outline-offset: 3px !important;
    }

    .lk-home-marketing-section {
        margin-top: 28px !important;
    }

    .lk-home-marketing-section .lk-section-head {
        margin-bottom: 14px !important;
    }

    .lk-home-voucher-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .lk-home-voucher-card {
        display: flex;
        min-width: 0;
        flex-direction: column;
        justify-content: space-between;
        gap: 14px;
        padding: 16px;
        border: 1px solid var(--lk-border);
        border-radius: 18px;
        background: var(--lk-card);
        box-shadow: var(--lk-shadow-soft);
    }

    .lk-home-voucher-card h3 { margin: 5px 0 0; color: var(--lk-text); font-size: 14px; font-weight: 800; }
    .lk-home-voucher-card p { margin: 4px 0 0; color: var(--lk-muted); font-size: 12px; }
    .lk-home-voucher-card strong { color: var(--lk-maroon); font-size: 18px; }
    .lk-home-voucher-card button,
    .lk-home-voucher-card a { align-self: flex-start; }
    .lk-home-voucher-feedback { min-height: 1.25em; color: var(--lk-brown); font-size: 11px; }

    .lk-home-page .lk-home-offers {
        display: grid;
        gap: 16px;
        margin-top: 28px !important;
        padding: clamp(16px, 2.2vw, 26px);
        border: 1px solid var(--lk-home-line, #eadfd4);
        border-radius: 24px;
        background: var(--lk-home-paper, #fffdf9);
        box-shadow: 0 14px 34px rgba(86, 28, 23, .055);
    }
    .lk-home-page .lk-home-offers__header,
    .lk-home-page .lk-home-offer-card__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
    }
    .lk-home-page .lk-home-offers__header h2 {
        margin: 3px 0 0;
        color: var(--lk-home-ink, #171311);
        font-size: clamp(21px, 2.3vw, 28px);
        font-weight: 850;
        letter-spacing: -.035em;
    }
    .lk-home-page .lk-home-offers__header p {
        margin: 5px 0 0;
        color: var(--lk-home-muted, #6b625c);
        font-size: 12px;
    }
    .lk-home-page .lk-home-offers__refresh {
        display: inline-flex;
        min-height: 40px;
        flex: 0 0 auto;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 0 14px;
        border: 1px solid var(--lk-home-line, #eadfd4);
        border-radius: 999px;
        background: var(--lk-home-paper, #fffdf9);
        color: var(--lk-home-copy-maroon, #561c17);
        font-size: 11px;
        font-weight: 800;
        cursor: pointer;
        transition: background 160ms ease, border-color 160ms ease, transform 160ms ease;
    }
    .lk-home-page .lk-home-offers__refresh:hover:not(:disabled) {
        transform: translateY(-1px);
        border-color: var(--lk-home-terracotta, #bd3e2b);
        background: #f7eee7;
    }
    .lk-home-page .lk-home-offers__refresh:focus-visible,
    .lk-home-page .lk-home-offer-card__link:focus-visible {
        outline: 3px solid var(--lk-home-terracotta, #bd3e2b);
        outline-offset: 3px;
    }
    .lk-home-page .lk-home-offers__refresh:disabled { opacity: .65; cursor: wait; }
    .lk-home-page .lk-home-offers__refresh svg { width: 16px; height: 16px; }
    .lk-home-page [data-home-offers][aria-busy="true"] .lk-home-offers__refresh svg { animation: lk-home-offers-spin 800ms linear infinite; }
    .lk-home-page .lk-home-offers__grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 225px), 1fr));
        align-items: stretch;
        gap: 13px;
    }
    .lk-home-page .lk-home-offers__product { display: flex; min-width: 0; flex-direction: column; gap: 8px; }
    .lk-home-page .lk-home-offers__type {
        display: inline-flex;
        width: fit-content;
        align-items: center;
        min-height: 22px;
        padding: 0 9px;
        border-radius: 999px;
        background: #f3e5dc;
        color: var(--lk-home-copy-maroon, #561c17);
        font-size: 9px;
        font-weight: 850;
        letter-spacing: .075em;
        text-transform: uppercase;
    }
    .lk-home-page .lk-home-offer-card {
        display: flex;
        min-width: 0;
        flex-direction: column;
        align-items: stretch;
        gap: 0;
        padding: 0;
        overflow: hidden;
        border: 1px solid var(--lk-home-line, #eadfd4);
        border-radius: 19px;
        background: var(--lk-home-paper, #fffdf9);
        color: var(--lk-home-ink, #171311);
        box-shadow: 0 8px 22px rgba(86, 28, 23, .045);
    }
    .lk-home-page .lk-home-offer-visual {
        position: relative;
        isolation: isolate;
        width: 100%;
        height: 244px;
        flex: 0 0 244px;
        overflow: hidden;
        background: linear-gradient(140deg, #f4e5d8, #ead5c5 54%, #f8efe7);
    }
    .lk-home-page .lk-home-offer-visual__images {
        display: grid;
        width: 100%;
        height: 100%;
        grid-template-columns: repeat(var(--offer-image-count, 2), minmax(0, 1fr));
        gap: 3px;
        background: #f1e5db;
    }
    .lk-home-page .lk-home-offer-visual__image {
        position: relative;
        display: grid;
        min-width: 0;
        min-height: 0;
        overflow: hidden;
        place-items: center;
        background: linear-gradient(145deg, #fffaf4, #ead9c9);
    }
    .lk-home-page .lk-home-offer-visual__image img,
    .lk-home-page .lk-home-offer-visual__background-image {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 320ms cubic-bezier(.2, .7, .2, 1);
    }
    .lk-home-page .lk-home-offer-visual__placeholder-art {
        display: grid;
        width: 100%;
        height: 100%;
        place-items: center;
        color: #8d4a3a;
        background:
            radial-gradient(circle at 50% 35%, rgba(255, 255, 255, .95), transparent 34%),
            linear-gradient(145deg, #f7e7db, #e6cbb8);
    }
    .lk-home-page .lk-home-offer-visual__placeholder-art svg { width: 78px; height: 78px; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round; stroke-linejoin: round; }
    .lk-home-page .lk-home-offer-visual__placeholder-art--gift { color: #7e3228; background: radial-gradient(circle at 50% 34%, #fff8ee, transparent 36%), linear-gradient(145deg, #f7e2d4, #e8c5b2); }
    .lk-home-page .lk-home-offer-visual__placeholder-art--gift svg { width: 90px; height: 90px; }
    @media (hover: hover) {
        .lk-home-page .lk-home-offer-card:hover .lk-home-offer-visual__image img,
        .lk-home-page .lk-home-offer-card:hover .lk-home-offer-visual__background-image { transform: scale(1.045); }
    }
    .lk-home-page .lk-home-offer-visual__top {
        position: absolute;
        z-index: 2;
        inset: 11px 11px auto;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 8px;
    }
    .lk-home-page .lk-home-offer-visual__top .lk-home-offers__type {
        min-height: 24px;
        border: 1px solid rgba(255, 255, 255, .68);
        background: rgba(255, 253, 249, .93);
        box-shadow: 0 3px 12px rgba(35, 22, 17, .08);
        backdrop-filter: blur(8px);
    }
    .lk-home-page .lk-home-offer-visual__top .lk-home-offer-card__stock {
        padding: 6px 8px;
        border: 1px solid rgba(255, 255, 255, .16);
        border-radius: 999px;
        background: rgba(55, 31, 25, .78);
        color: #fffaf5;
        font-size: 8px;
        line-height: 1.2;
        text-align: center;
        backdrop-filter: blur(8px);
    }
    .lk-home-page .lk-home-offer-visual__image-label {
        position: absolute;
        right: 9px;
        bottom: 9px;
        padding: 5px 8px;
        border: 1px solid rgba(255, 255, 255, .55);
        border-radius: 999px;
        background: rgba(55, 31, 25, .78);
        color: #fffaf5;
        font-size: 8px;
        font-weight: 800;
        line-height: 1;
        backdrop-filter: blur(8px);
    }
    .lk-home-page .lk-home-offer-visual__plus {
        position: absolute;
        z-index: 1;
        top: 50%;
        left: 50%;
        display: grid;
        width: 30px;
        height: 30px;
        transform: translate(-50%, -50%);
        place-items: center;
        border: 2px solid rgba(255, 255, 255, .92);
        border-radius: 50%;
        background: var(--lk-home-copy-maroon, #561c17);
        color: white;
        font-size: 20px;
        font-weight: 500;
        box-shadow: 0 5px 15px rgba(49, 26, 20, .22);
    }
    .lk-home-page .lk-home-offer-visual--voucher {
        background:
            radial-gradient(circle at 88% 15%, rgba(239, 193, 138, .5), transparent 27%),
            linear-gradient(135deg, #3a1512 0%, var(--voucher-accent, #742b21) 58%, #a85637 100%);
    }
    .lk-home-page .lk-home-offer-visual__voucher-art {
        position: relative;
        display: flex;
        width: 100%;
        height: 100%;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        gap: 3px;
        padding: 24px;
        overflow: hidden;
        color: #fffaf5;
        background:
            radial-gradient(circle at 85% 18%, rgba(241, 197, 149, .28), transparent 28%),
            linear-gradient(125deg, #3a1512, var(--voucher-accent, #742b21) 72%, #b66c48);
    }
    .lk-home-page .lk-home-offer-visual__voucher-art::after {
        position: absolute;
        inset: 14px;
        border: 1px dashed rgba(255, 246, 231, .43);
        border-radius: 15px;
        content: "";
        pointer-events: none;
    }
    .lk-home-page .lk-home-offer-visual__voucher-art > span,
    .lk-home-page .lk-home-offer-visual__voucher-art > small { z-index: 1; color: #f4d7c0; font-size: 8px; font-weight: 850; letter-spacing: .16em; }
    .lk-home-page .lk-home-offer-visual__voucher-art > .lk-home-offer-visual__voucher-brand { max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .lk-home-page .lk-home-offer-visual__voucher-art > strong { z-index: 1; color: #fffaf5; font-size: clamp(27px, 3vw, 39px); font-weight: 900; line-height: 1.05; letter-spacing: -.04em; }
    .lk-home-page .lk-home-offer-visual__voucher-art svg { position: absolute; top: 50%; right: 24px; width: 68px; height: 68px; transform: translateY(-50%); color: rgba(255, 242, 224, .66); }
    .lk-home-page .lk-home-offer-card__body {
        display: flex;
        min-width: 0;
        min-height: 208px;
        flex: 1 1 auto;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
        padding: 16px;
    }
    .lk-home-page .lk-home-offer-card__body > div:first-child { width: 100%; }
    .lk-home-page .lk-home-offer-card h3 { margin: 0; color: var(--lk-home-ink, #171311); font-size: 15px; font-weight: 850; line-height: 1.35; }
    .lk-home-page .lk-home-offer-card p { margin: 0; color: var(--lk-home-muted, #6b625c); font-size: 11px; line-height: 1.55; }
    .lk-home-page .lk-home-offer-card p strong { color: var(--lk-home-copy-maroon, #561c17); }
    .lk-home-page .lk-home-offer-card .lk-home-offer-card__saving { color: var(--lk-home-muted, #6b625c); }
    .lk-home-page .lk-home-offer-card__seller { margin-top: auto !important; font-weight: 700; }
    .lk-home-page .lk-home-offer-card__stock { color: var(--lk-home-muted, #6b625c); font-size: 9px; font-weight: 750; text-align: right; }
    .lk-home-page .lk-home-offer-card__actions { display: grid; width: 100%; gap: 9px; margin-top: auto; }
    .lk-home-page .lk-home-offer-card__actions form { margin: 0; }
    .lk-home-page .lk-home-offer-card__actions .lk-btn { width: 100%; justify-content: center; margin-top: 0 !important; }
    .lk-home-page .lk-home-offer-card__link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: auto;
        color: var(--lk-home-copy-maroon, #561c17);
        font-size: 10px;
        font-weight: 850;
        text-decoration: none;
    }
    .lk-home-page .lk-home-offer-card__link:hover { color: var(--lk-home-terracotta, #bd3e2b); }
    .lk-home-page .lk-home-offers .lk-home-voucher-card { justify-content: flex-start; }
    .lk-home-page .lk-home-offers .lk-home-voucher-card button,
    .lk-home-page .lk-home-offers .lk-home-voucher-card a.lk-btn { margin-top: 0; }
    .lk-home-page .lk-home-offers__empty {
        grid-column: 1 / -1;
        padding: 26px 18px;
        border: 1px dashed var(--lk-home-line, #eadfd4);
        border-radius: 18px;
        color: var(--lk-home-ink, #171311);
        text-align: center;
    }
    .lk-home-page .lk-home-offers__empty p { margin: 5px 0 0; color: var(--lk-home-muted, #6b625c); font-size: 12px; }
    .lk-home-page .lk-home-offers__status { min-height: 1em; margin: 0; color: var(--lk-home-muted, #6b625c); font-size: 11px; }
    @keyframes lk-home-offers-spin { to { transform: rotate(360deg); } }
    @media (max-width: 560px) {
        .lk-home-page .lk-home-offers__header { align-items: stretch; flex-direction: column; }
        .lk-home-page .lk-home-offers__refresh { width: 100%; }
        .lk-home-page .lk-home-offers__grid { grid-template-columns: 1fr; }
        .lk-home-page .lk-home-offer-visual { height: 218px; flex-basis: 218px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .lk-home-page [data-home-offers][aria-busy="true"] .lk-home-offers__refresh svg { animation: none; }
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

        font-family: Georgia, "Instrument Serif", serif !important;
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
        font-family: Georgia, "Instrument Serif", serif;
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
    .lk-event-card { position: relative; overflow: hidden; display: grid; gap: 20px; padding: clamp(22px, 3vw, 34px); border: 0; border-top: 4px solid var(--lk-event-accent, #E9C7A5); border-radius: 24px; background: radial-gradient(circle at 86% 18%, rgba(193,151,113,.24), transparent 24%), linear-gradient(115deg, #561C17 0%, #642920 56%, #3E130F 100%); color: #FFF8F2; box-shadow: 0 18px 42px rgba(86,28,23,.18); }
    .lk-event-card__image { width: 100%; max-height: 260px; aspect-ratio: 2.5 / 1; border-radius: 16px; object-fit: cover; background: rgba(255,248,242,.08); }
    .lk-event-card__top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .lk-event-card__eyebrow { color: #E9C7A5; font-size: 10px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; }
    .lk-event-status { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 11px; font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .lk-event-status.is-active { background: #E8F3EA; color: #256F4A; }
    .lk-event-status.is-upcoming { background: #F8EDD9; color: #8A5A12; }
    .lk-event-card__hero { display: flex; align-items: center; justify-content: space-between; gap: 24px; }
    .lk-event-card__title { margin: 0; color: #FFF8F2; font: 400 clamp(34px, 4vw, 52px)/1 Georgia, "Instrument Serif", serif; letter-spacing: -.025em; }
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
            grid-template-columns: repeat(6, minmax(112px, 1fr)) !important;
            overflow-x: auto;
        }

        .lk-quick-card {
            min-height: 56px !important;
            gap: 6px !important;
            padding: 7px !important;
        }

        .lk-quick-icon {
            width: 32px !important;
            height: 32px !important;
            flex-basis: 32px !important;
        }

        .lk-quick-card strong {
            font-size: 10px !important;
        }

        .lk-quick-card small {
            font-size: 9px !important;
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

        .lk-quick-grid {
            grid-template-columns: repeat(6, minmax(112px, 1fr)) !important;
            overflow-x: auto;
        }

        .lk-countdown {
            width: 100% !important;
            justify-content: center !important;
        }
    }

    .lk-home-page .lk-home-product-grid,
    .lk-home-page .lk-home-recommendation-grid {
        display: grid !important;
        grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
        gap: 14px !important;
    }

    .lk-home-page .lk-recommendation-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }

    .lk-home-page .lk-home-recommendation-more {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        min-height: 48px;
        color: var(--lk-home-muted, #6b625c);
        font-size: 12px;
        text-align: center;
    }

    .lk-home-page .lk-home-recommendation-more button {
        padding: 7px 12px;
        border: 1px solid var(--lk-home-line, #eadfd4);
        border-radius: 999px;
        background: var(--lk-home-paper, #fffdf9);
        color: var(--lk-home-copy-maroon, #561c17);
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }

    .lk-home-page .lk-home-recommendation-more button:hover {
        background: #f4e6df;
    }

    .lk-home-page .lk-home-recommendation-pagination {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        margin: 8px auto 0;
    }

    .lk-home-page .lk-home-recommendation-pagination[hidden] {
        display: none !important;
    }

    .lk-home-page .lk-home-recommendation-pagination button {
        min-height: 40px;
        padding: 8px 16px;
        border: 1px solid var(--lk-home-line, #eadfd4);
        border-radius: 999px;
        background: var(--lk-home-paper, #fffdf9);
        color: var(--lk-home-copy-maroon, #561c17);
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition: background .15s ease, border-color .15s ease, opacity .15s ease;
    }

    .lk-home-page .lk-home-recommendation-pagination button:hover:not(:disabled) {
        border-color: var(--lk-home-copy-maroon, #561c17);
        background: #f4e6df;
    }

    .lk-home-page .lk-home-recommendation-pagination button:focus-visible {
        outline: 2px solid var(--lk-home-copy-maroon, #561c17);
        outline-offset: 3px;
    }

    .lk-home-page .lk-home-recommendation-pagination button:disabled {
        cursor: not-allowed;
        opacity: .45;
    }

    .lk-home-page .lk-home-recommendation-pagination span {
        min-width: 64px;
        color: var(--lk-home-muted, #6b625c);
        font-size: 12px;
        font-weight: 700;
        text-align: center;
    }

    @media (max-width: 1279px) and (min-width: 768px) {
        .lk-home-page .lk-home-product-grid,
        .lk-home-page .lk-home-recommendation-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        }
    }

    @media (max-width: 767px) {
        .lk-home-page .lk-home-product-grid,
        .lk-home-page .lk-home-recommendation-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
        }
    }

    /* Use available home-content width so the buyer sidebar is accounted for. */
    @container (min-width: 1280px) {
        .lk-home-page .lk-home-product-grid,
        .lk-home-page .lk-home-recommendation-grid {
            grid-template-columns: repeat(6, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
    }

    @container (min-width: 768px) and (max-width: 1279px) {
        .lk-home-page .lk-home-product-grid,
        .lk-home-page .lk-home-recommendation-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            gap: 14px !important;
        }
    }

    @container (max-width: 767px) {
        .lk-home-page .lk-home-product-grid,
        .lk-home-page .lk-home-recommendation-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 10px !important;
        }
    }

    /* Buyer Home editorial storefront. Keep these rules inside the main page content. */
    .lk-home-page {
        --lk-home-ivory: #fbf8f3;
        --lk-home-paper: #fffdf9;
        --lk-home-ink: #171311;
        --lk-home-muted: #6b625c;
        --lk-home-terracotta: #bd3e2b;
        --lk-home-copy-maroon: #561c17;
        --lk-home-button-maroon: #561c17;
        --lk-home-button-maroon-hover: #3e130f;
        --lk-home-line: #eadfd4;
        container-type: inline-size;
        padding: clamp(16px, 2vh, 24px) clamp(18px, 2vw, 30px) 32px !important;
        background: var(--lk-home-ivory) !important;
        color: var(--lk-home-ink) !important;
    }

    .lk-home-page .lk-home-editorial-hero {
        position: relative;
        display: flex;
        align-items: center;
        min-height: clamp(320px, 39vh, 420px);
        overflow: hidden;
        padding: clamp(28px, 3.2vw, 52px);
        border: 0;
        border-radius: 18px;
        background-color: #f5e8da;
        background-image: linear-gradient(90deg, rgba(255, 251, 246, .98) 0%, rgba(255, 251, 246, .96) 18%, rgba(255, 251, 246, .9) 30%, rgba(255, 251, 246, .8) 42%, rgba(255, 251, 246, .64) 54%, rgba(255, 251, 246, .45) 66%, rgba(255, 251, 246, .25) 80%, rgba(255, 251, 246, .08) 92%, rgba(255, 251, 246, 0) 100%), image-set(url('{{ asset('images/buyer/home-hero-editorial-v3.webp') }}') type('image/webp'), url('{{ asset('images/buyer/home-hero-editorial-v3.png') }}') type('image/png'));
        background-position: center right, right center;
        background-size: cover, cover;
        background-repeat: no-repeat;
        box-shadow: 0 18px 44px rgba(77, 47, 31, .1);
    }

    .lk-home-page .lk-home-editorial-copy {
        position: relative;
        z-index: 1;
        width: min(50%, 520px);
    }

    .lk-home-page .lk-home-editorial-title {
        margin: 0;
        color: var(--lk-home-ink);
        font-family: Georgia, "Instrument Serif", "Times New Roman", serif;
        font-size: clamp(56px, 5.2vw, 76px);
        font-weight: 400;
        letter-spacing: -.055em;
        line-height: .88;
    }

    .lk-home-page .lk-home-editorial-title span {
        display: block;
        color: var(--lk-home-copy-maroon);
        font-style: normal;
    }

    .lk-home-page .lk-home-editorial-description {
        max-width: 430px;
        margin: 16px 0 0;
        color: var(--lk-home-copy-maroon);
        font-size: 14px;
        line-height: 1.7;
    }

    .lk-home-page .lk-home-editorial-cta {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 18px;
        min-height: 46px;
        margin-top: 17px;
        padding: 0 24px;
        border: 1px solid var(--lk-home-button-maroon);
        border-radius: 6px;
        background: var(--lk-home-button-maroon);
        color: #fff !important;
        font-size: 13px;
        font-weight: 700;
        text-decoration: none;
        box-shadow: 0 8px 18px rgba(152, 52, 37, .18);
        transition: background-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }

    .lk-home-page .lk-home-editorial-cta:hover {
        transform: translateY(-1px);
        background: var(--lk-home-button-maroon-hover);
        box-shadow: 0 11px 22px rgba(152, 52, 37, .24);
    }

    .lk-home-page .lk-home-editorial-cta svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }

    .lk-home-page .lk-home-hero-carousel { position: relative; width: 100%; isolation: isolate; touch-action: pan-y; }
    .lk-home-page .lk-home-hero-track { display: grid; }
    .lk-home-page .lk-home-hero-slide {
        grid-area: 1 / 1;
        min-height: clamp(400px, 48vh, 560px);
        opacity: 0;
        pointer-events: none;
        transition: opacity .55s ease;
        visibility: hidden;
        z-index: 0;
    }
    .lk-home-page .lk-home-hero-slide[aria-hidden="false"] {
        opacity: 1;
        pointer-events: auto;
        visibility: visible;
        z-index: 1;
    }
    .lk-home-page .lk-home-hero-slide--event { background-image: none; }
    .lk-home-page .lk-home-hero-artwork { position: absolute; inset: 0; overflow: hidden; border-radius: inherit; }
    .lk-home-page .lk-home-hero-artwork picture,
    .lk-home-page .lk-home-hero-artwork img { display: block; width: 100%; height: 100%; }
    .lk-home-page .lk-home-hero-artwork img { object-fit: cover; object-position: center; }
    .lk-home-page .lk-home-hero-artwork::after {
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, rgba(255, 251, 246, .98) 0%, rgba(255, 251, 246, .95) 24%, rgba(255, 251, 246, .82) 43%, rgba(255, 251, 246, .45) 64%, rgba(255, 251, 246, .06) 100%);
        content: "";
    }
    .lk-home-page .lk-home-hero-event-copy { position: relative; z-index: 1; width: min(55%, 600px); }
    .lk-home-page .lk-home-hero-event-kicker {
        display: inline-block;
        margin-bottom: 11px;
        color: var(--lk-home-copy-maroon);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .16em;
        text-transform: uppercase;
    }
    .lk-home-page .lk-home-hero-event-title {
        margin: 0;
        color: var(--lk-home-ink);
        font-family: Georgia, "Instrument Serif", "Times New Roman", serif;
        font-size: clamp(44px, 5vw, 70px);
        font-weight: 400;
        letter-spacing: -.045em;
        line-height: .94;
        text-wrap: balance;
    }
    .lk-home-page .lk-home-hero-event-description {
        max-width: 450px;
        margin: 14px 0 0;
        color: var(--lk-home-copy-maroon);
        font-size: 14px;
        line-height: 1.65;
    }
    .lk-home-page .lk-home-hero-controls {
        position: absolute;
        right: 22px;
        bottom: 18px;
        z-index: 3;
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 7px;
        border: 1px solid rgba(86, 28, 23, .12);
        border-radius: 999px;
        background: rgba(255, 253, 249, .92);
        box-shadow: 0 5px 18px rgba(48, 29, 21, .12);
        backdrop-filter: blur(10px);
    }
    .lk-home-page .lk-home-hero-arrow,
    .lk-home-page .lk-home-hero-dot {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: transparent;
        color: var(--lk-home-copy-maroon);
        cursor: pointer;
    }
    .lk-home-page .lk-home-hero-arrow:hover,
    .lk-home-page .lk-home-hero-dot[aria-current="true"] { background: #f1e4d7; }
    .lk-home-page .lk-home-hero-arrow svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-linecap: round; stroke-linejoin: round; stroke-width: 1.8; }
    .lk-home-page .lk-home-hero-pagination { display: flex; align-items: center; gap: 2px; }
    .lk-home-page .lk-home-hero-dot { width: 26px; height: 26px; }
    .lk-home-page .lk-home-hero-dot::before { width: 7px; height: 7px; border-radius: 50%; background: currentColor; content: ""; }
    .lk-home-page .lk-home-hero-arrow:focus-visible,
    .lk-home-page .lk-home-hero-dot:focus-visible { outline: 3px solid #bd3e2b; outline-offset: 2px; }
    .lk-home-page .lk-home-hero-slide[aria-hidden="true"] a { visibility: hidden; }

    @media (max-width: 640px) {
        .lk-home-page .lk-home-hero-slide { min-height: 390px; }
        .lk-home-page .lk-home-editorial-hero { align-items: flex-start; padding-bottom: 70px !important; }
        .lk-home-page .lk-home-editorial-copy,
        .lk-home-page .lk-home-hero-event-copy { width: min(82%, 420px); }
        .lk-home-page .lk-home-editorial-title { font-size: clamp(48px, 13vw, 64px); }
        .lk-home-page .lk-home-hero-event-title { font-size: clamp(42px, 11vw, 58px); }
        .lk-home-page .lk-home-hero-artwork::after { background: linear-gradient(90deg, rgba(255, 251, 246, .96) 0%, rgba(255, 251, 246, .88) 42%, rgba(255, 251, 246, .45) 72%, rgba(255, 251, 246, .12) 100%); }
        .lk-home-page .lk-home-hero-controls { right: 12px; bottom: 12px; }
        .lk-home-page .lk-home-hero-arrow { width: 32px; height: 32px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .lk-home-page .lk-home-hero-slide { transition: none; }
    }

    html.dark .lk-home-page .lk-home-hero-artwork::after {
        background: linear-gradient(90deg, rgba(23, 18, 16, .96) 0%, rgba(23, 18, 16, .9) 28%, rgba(23, 18, 16, .74) 48%, rgba(23, 18, 16, .35) 70%, rgba(23, 18, 16, .08) 100%);
    }
    html.dark .lk-home-page .lk-home-hero-controls {
        border-color: rgba(255, 248, 242, .18);
        background: rgba(36, 26, 23, .94);
    }

    .lk-home-page .lk-home-category-section {
        margin-top: 0 !important;
    }

    .lk-home-page .lk-home-category-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        margin-bottom: 10px;
    }

    .lk-home-page .lk-home-category-heading h2 {
        margin: 0;
        color: var(--lk-home-ink);
        font-family: Georgia, "Instrument Serif", "Times New Roman", serif;
        font-size: clamp(25px, 2.4vw, 32px);
        font-weight: 400;
        letter-spacing: -.035em;
        line-height: 1;
    }

    .lk-home-page .lk-home-category-tools {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: clamp(10px, 1.5vw, 22px);
        color: #625b56;
        font-size: 11px;
        white-space: nowrap;
    }

    .lk-home-page .lk-home-category-cue,
    .lk-home-page .lk-home-category-count {
        display: inline-flex;
        align-items: center;
        gap: 7px;
    }

    .lk-home-page .lk-home-category-cue svg,
    .lk-home-page .lk-home-category-count svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: #8b5143;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.6;
    }

    .lk-home-page .lk-home-category-nav {
        display: inline-flex;
        align-items: center;
        gap: 9px;
    }

    .lk-home-page .lk-home-category-range {
        min-width: 58px;
        color: #4e4742;
        font-variant-numeric: tabular-nums;
        text-align: center;
    }

    .lk-home-page .lk-home-category-arrow {
        display: inline-grid;
        width: 34px;
        height: 34px;
        place-items: center;
        border: 1px solid #e5d8cc;
        border-radius: 50%;
        background: #fffdfa;
        color: #342b26;
        cursor: pointer;
        transition: border-color .16s ease, background-color .16s ease, color .16s ease;
    }

    .lk-home-page .lk-home-category-arrow:hover:not(:disabled) {
        border-color: #c27664;
        background: #f9eee8;
        color: var(--lk-home-terracotta);
    }

    .lk-home-page .lk-home-category-arrow:disabled {
        cursor: default;
        opacity: .45;
    }

    .lk-home-page .lk-home-category-arrow svg {
        width: 15px;
        height: 15px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }

    .lk-home-page .lk-home-category-pages {
        display: grid;
        gap: 14px;
    }

    .lk-home-page .lk-home-category-page[hidden] {
        display: none !important;
    }

    .lk-home-page .lk-home-category-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
    }

    .lk-home-page .lk-home-category-card {
        min-width: 0;
        overflow: hidden;
        border: 1px solid rgba(95, 64, 43, .08);
        border-radius: 10px;
        background: var(--lk-home-paper);
        color: var(--lk-home-ink);
        box-shadow: 0 5px 16px rgba(72, 49, 35, .06);
        text-decoration: none;
        transition: transform .18s ease, box-shadow .18s ease;
    }

    .lk-home-page .lk-home-category-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(72, 49, 35, .12);
    }

    .lk-home-page .lk-home-category-visual {
        display: grid;
        aspect-ratio: 1.8 / 1;
        overflow: hidden;
        place-items: center;
        border-radius: 9px;
        background: #4c211d;
    }

    .lk-home-page .lk-home-category-visual picture {
        display: block;
        width: 100%;
        height: 100%;
    }

    .lk-home-page .lk-home-category-visual img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover !important;
        object-position: center;
    }

    .lk-home-page .lk-home-category-visual img.is-illustration {
        width: 100%;
        height: 100%;
        padding: 8px;
        object-fit: contain !important;
    }

    .lk-home-page .lk-home-category-card-footer {
        display: flex;
        align-items: center;
        gap: 9px;
        min-height: 40px;
        padding: 6px 10px;
    }

    .lk-home-page .lk-home-category-name {
        min-width: 0;
        overflow: hidden;
        color: #211b17;
        font-size: 11px;
        font-weight: 600;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .lk-home-page .lk-home-category-products {
        flex: 0 0 auto;
        padding: 3px 7px;
        border-radius: 999px;
        background: #f8dfd8;
        color: #a13b2d;
        font-size: 10px;
        font-weight: 700;
        font-variant-numeric: tabular-nums;
    }

    .lk-home-page .lk-home-category-link-arrow {
        display: inline-flex;
        flex: 0 0 auto;
        margin-left: auto;
        color: #382c26;
    }

    .lk-home-page .lk-home-category-link-arrow svg {
        width: 14px;
        height: 14px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }

    .lk-home-page .lk-home-category-empty {
        padding: 24px;
        border: 1px dashed var(--lk-home-line);
        border-radius: 10px;
        background: rgba(255, 253, 249, .72);
        color: var(--lk-home-muted);
        font-size: 12px;
        text-align: center;
    }

    .lk-home-page .lk-home-category-pagination {
        display: flex;
        justify-content: center;
        gap: 6px;
        min-height: 8px;
        margin-top: 8px;
    }

    .lk-home-page .lk-home-category-dot {
        width: 7px;
        height: 7px;
        padding: 0;
        border: 0;
        border-radius: 50%;
        background: #ded8d2;
        cursor: pointer;
    }

    .lk-home-page .lk-home-category-dot[aria-current="true"] {
        background: var(--lk-home-terracotta);
    }

    .lk-home-page .lk-home-category-arrow:focus-visible,
    .lk-home-page .lk-home-category-dot:focus-visible,
    .lk-home-page .lk-home-editorial-cta:focus-visible,
    .lk-home-page .lk-home-category-card:focus-visible {
        outline: 2px solid #a94434;
        outline-offset: 3px;
    }

    .lk-home-page .lk-home-category-section + .lk-section {
        margin-top: 38px !important;
    }

    html.dark .lk-home-page {
        --lk-home-paper: #241a17;
        --lk-home-ink: #fff8f2;
        --lk-home-muted: #c8b7ad;
        --lk-home-copy-maroon: #e8b5a9;
        --lk-home-button-maroon: #8a3a2f;
        --lk-home-button-maroon-hover: #6f241c;
        --lk-home-line: #49342b;
        background: #171210 !important;
        color: #fff8f2 !important;
    }

    html.dark .lk-home-page .lk-home-editorial-hero {
        border-color: #49342b;
        background-image: linear-gradient(90deg, rgba(23, 18, 16, .94) 0%, rgba(23, 18, 16, .92) 18%, rgba(23, 18, 16, .84) 30%, rgba(23, 18, 16, .72) 42%, rgba(23, 18, 16, .56) 54%, rgba(23, 18, 16, .38) 66%, rgba(23, 18, 16, .22) 80%, rgba(23, 18, 16, .06) 92%, rgba(23, 18, 16, 0) 100%), image-set(url('{{ asset('images/buyer/home-hero-editorial-v3.webp') }}') type('image/webp'), url('{{ asset('images/buyer/home-hero-editorial-v3.png') }}') type('image/png'));
    }

    html.dark .lk-home-page .lk-home-editorial-title,
    html.dark .lk-home-page .lk-home-category-heading h2,
    html.dark .lk-home-page .lk-home-category-name {
        color: #fff8f2;
    }

    html.dark .lk-home-page .lk-home-editorial-description {
        color: var(--lk-home-copy-maroon);
    }

    html.dark .lk-home-page .lk-home-category-tools,
    html.dark .lk-home-page .lk-home-category-range {
        color: #d0c2b9;
    }

    html.dark .lk-home-page .lk-home-category-card {
        border-color: #49342b;
        background: #241a17;
    }

    html.dark .lk-home-page .lk-home-offers__refresh {
        border-color: #49342b;
        background: #241a17;
        color: #f0d0c5;
    }

    html.dark .lk-home-page .lk-home-offers__refresh:hover:not(:disabled) {
        background: #392520;
    }

    html.dark .lk-home-page .lk-home-offers__type {
        background: #4a2923;
        color: #f0c8bc;
    }

    html.dark .lk-home-page .lk-home-offer-card {
        border-color: #49342b;
        background: #241a17;
        box-shadow: 0 10px 28px rgba(0, 0, 0, .2);
    }

    html.dark .lk-home-page .lk-home-offer-card__body h3 { color: #f7eee8; }
    html.dark .lk-home-page .lk-home-offer-card__body p { color: #cbb8ac; }
    html.dark .lk-home-page .lk-home-offer-card__body p strong { color: #f0c0b6; }
    html.dark .lk-home-page .lk-home-offer-visual:not(.lk-home-offer-visual--voucher) { background: #2e211b; }
    html.dark .lk-home-page .lk-home-offer-visual__image { background: linear-gradient(145deg, #362720, #261c18); }
    html.dark .lk-home-page .lk-home-offer-visual__images { background: #241a17; }
    html.dark .lk-home-page .lk-home-offer-visual__placeholder-art { color: #e3ae96; background: radial-gradient(circle at 50% 35%, rgba(255, 255, 255, .08), transparent 36%), linear-gradient(145deg, #3b2a23, #2a1f1b); }
    html.dark .lk-home-page .lk-home-offer-visual__placeholder-art--gift { color: #f0b9a2; }
    html.dark .lk-home-page .lk-home-offer-visual__top .lk-home-offers__type { background: rgba(36, 26, 23, .92); color: #f0c8bc; border-color: rgba(255, 255, 255, .14); }

    html.dark .lk-home-page .lk-home-category-visual {
        background: #351815;
    }

    html.dark .lk-home-page .lk-home-category-arrow {
        border-color: #59463c;
        background: #241a17;
        color: #fff8f2;
    }

    html.dark .lk-home-page .lk-home-category-products {
        background: #4a2923;
        color: #f0c0b6;
    }

    @media (max-width: 920px) {
        .lk-home-page .lk-home-editorial-hero {
            min-height: clamp(340px, 43vw, 420px);
            padding: clamp(32px, 5vw, 48px);
        }

        .lk-home-page .lk-home-editorial-copy {
            width: min(55%, 470px);
        }

        .lk-home-page .lk-home-editorial-title {
            font-size: clamp(54px, 7vw, 76px);
        }

        .lk-home-page .lk-home-category-tools {
            flex-wrap: wrap;
            gap: 9px 14px;
        }
    }

    @media (max-width: 680px) {
        .lk-home-page {
            padding: 18px 16px 40px !important;
        }

        .lk-home-page .lk-home-editorial-hero {
            min-height: 380px;
            padding: 34px 24px;
            background-position: center right, 62% center;
            background-size: 100% 100%, cover;
            background-repeat: no-repeat;
            background-image: linear-gradient(90deg, rgba(255, 251, 246, .98) 0%, rgba(255, 251, 246, .96) 28%, rgba(255, 251, 246, .9) 40%, rgba(255, 251, 246, .78) 52%, rgba(255, 251, 246, .6) 64%, rgba(255, 251, 246, .4) 76%, rgba(255, 251, 246, .2) 88%, rgba(255, 251, 246, 0) 100%), image-set(url('{{ asset('images/buyer/home-hero-editorial-v3.webp') }}') type('image/webp'), url('{{ asset('images/buyer/home-hero-editorial-v3.png') }}') type('image/png'));
        }

        html.dark .lk-home-page .lk-home-editorial-hero {
            background-image: linear-gradient(90deg, rgba(23, 18, 16, .94) 0%, rgba(23, 18, 16, .92) 28%, rgba(23, 18, 16, .84) 40%, rgba(23, 18, 16, .7) 52%, rgba(23, 18, 16, .52) 64%, rgba(23, 18, 16, .34) 76%, rgba(23, 18, 16, .16) 88%, rgba(23, 18, 16, 0) 100%), image-set(url('{{ asset('images/buyer/home-hero-editorial-v3.webp') }}') type('image/webp'), url('{{ asset('images/buyer/home-hero-editorial-v3.png') }}') type('image/png'));
        }

        .lk-home-page .lk-home-editorial-copy {
            width: min(88%, 400px);
        }

        .lk-home-page .lk-home-editorial-title {
            font-size: clamp(48px, 11vw, 64px);
        }

        .lk-home-page .lk-home-category-heading {
            align-items: flex-start;
            flex-direction: column;
            gap: 11px;
        }

        .lk-home-page .lk-home-category-tools {
            width: 100%;
            justify-content: space-between;
        }

        .lk-home-page .lk-home-category-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .lk-home-page .lk-home-category-visual {
            aspect-ratio: 1.2 / 1;
        }

        .lk-home-page .lk-home-category-card-footer {
            gap: 6px;
            padding: 7px 8px;
        }

        .lk-home-page .lk-home-category-name {
            font-size: 10px;
        }

        .lk-home-page .lk-home-category-count {
            display: none;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .lk-home-page .lk-home-editorial-cta,
        .lk-home-page .lk-home-category-card,
        .lk-home-page .lk-home-category-arrow {
            transition: none;
        }
    }

    /* Keep the home page in normal document flow so the full page can scroll. */
    body.lk-buyer-home-page-flow {
        min-height: 100vh;
        height: auto;
        overflow-x: hidden;
        overflow-y: auto !important;
    }

    body.lk-buyer-home-page-flow .lk-shell {
        display: flex;
        min-height: 100vh;
        height: auto;
        flex-direction: column;
        overflow: visible;
    }

    body.lk-buyer-home-page-flow .lk-buyer-app {
        min-height: 100vh;
        overflow: visible;
    }

    body.lk-buyer-home-page-flow .lk-header {
        flex: 0 0 auto;
    }

    body.lk-buyer-home-page-flow .lk-main {
        display: block;
        flex: 1 0 auto;
        min-height: auto;
        overflow: visible;
        padding: 12px clamp(12px, 1.7vw, 24px) !important;
    }

    body.lk-buyer-home-page-flow .lk-footer {
        flex: 0 0 auto;
        min-height: 38px;
        margin-top: 0;
    }

    body.lk-buyer-home-page-flow .lk-footer:not(:has(.lk-footer-inner)) {
        min-height: 38px;
        padding: 6px clamp(12px, 1.7vw, 24px);
    }

    .lk-home-page {
        display: flex !important;
        width: 100% !important;
        height: auto !important;
        min-height: 0 !important;
        flex-direction: column;
        gap: clamp(14px, 1.7vw, 24px);
        overflow: visible !important;
        padding: 0 0 36px !important;
    }

    .lk-home-page .lk-home-editorial-hero {
        min-height: clamp(400px, 48vh, 560px) !important;
        height: auto !important;
        padding: clamp(16px, 2.5vw, 30px) !important;
        border-radius: 16px;
    }

    .lk-home-page .lk-home-editorial-title {
        font-size: clamp(38px, 4.4vw, 58px);
    }

    .lk-home-page .lk-home-editorial-description {
        margin-top: 8px;
        font-size: 12px;
        line-height: 1.45;
    }

    .lk-home-page .lk-home-editorial-cta {
        min-height: 36px;
        margin-top: 9px;
    }

    .lk-home-tabs {
        display: grid;
        min-width: 0;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 6px;
    }

    .lk-home-tabs [role="tab"] {
        min-width: 0;
        padding: 0 12px;
        border: 1px solid var(--lk-border);
        border-radius: 10px;
        background: var(--lk-card);
        color: var(--lk-brown);
        font: 700 11px/1.1 Poppins, sans-serif;
        cursor: pointer;
        transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease;
    }

    .lk-home-tabs [role="tab"][aria-selected="true"] {
        border-color: var(--lk-maroon);
        background: var(--lk-maroon);
        color: #fffaf5;
    }

    .lk-home-tabs [role="tab"]:focus-visible,
    .lk-home-panel:focus-visible {
        outline: 2px solid var(--lk-tan);
        outline-offset: 2px;
    }

    .lk-home-panel {
        min-width: 0;
        min-height: 0;
        overflow: hidden;
    }

    .lk-home-panel[hidden] {
        display: none !important;
    }

    .lk-home-panel--shop:not([hidden]) {
        display: flex;
        min-height: 0;
        flex-direction: column;
        gap: 8px;
        overflow-x: hidden;
        overflow-y: auto;
        scrollbar-width: thin;
    }

    .lk-home-panel--products:not([hidden]),
    .lk-home-panel--events:not([hidden]) {
        display: flex;
        flex-direction: column;
    }

    .lk-home-panel--deals:not([hidden]) {
        display: grid;
        grid-template-rows: auto minmax(0, 1fr);
        gap: 8px;
    }

    .lk-home-panel > .lk-section,
    .lk-home-panel--products > .lk-section,
    .lk-home-panel--events > .lk-section,
    .lk-home-panel--deals > .lk-section {
        min-width: 0;
        min-height: 0;
        margin: 0 !important;
    }

    .lk-home-panel--shop .lk-home-category-section {
        display: grid;
        grid-template-rows: auto auto auto;
        gap: 6px;
        margin: 0 !important;
    }

    .lk-home-panel--shop .lk-home-category-heading {
        margin: 0;
    }

    .lk-home-panel--shop .lk-home-category-heading h2 {
        font-size: clamp(22px, 2vw, 28px);
    }

    .lk-home-panel--shop .lk-home-category-pages,
    .lk-home-panel--shop .lk-home-category-page,
    .lk-home-panel--shop .lk-home-category-grid {
        min-height: 0;
    }

    .lk-home-panel--shop .lk-home-category-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
    }

    .lk-home-panel--shop .lk-home-category-card {
        min-height: 0;
    }

    .lk-home-panel--shop .lk-home-category-visual {
        aspect-ratio: 2.8 / 1;
    }

    .lk-home-panel--shop .lk-home-category-pagination {
        margin: 0;
    }

    .lk-home-panel--shop > .lk-section {
        margin: 0 !important;
    }

    .lk-home-page .lk-home-panel--shop .lk-home-category-section + .lk-section {
        margin-top: 0 !important;
    }

    .lk-home-panel--shop .lk-quick-grid {
        gap: 8px !important;
    }

    .lk-home-panel--shop .lk-quick-card {
        min-height: 54px !important;
        padding: 7px 10px !important;
    }

    .lk-home-panel--products > .lk-section,
    .lk-home-panel--events > .lk-section {
        display: grid;
        height: 100%;
        grid-template-rows: auto minmax(0, 1fr);
        gap: 8px;
    }

    .lk-home-panel .lk-section-head {
        align-items: center !important;
        flex-direction: row !important;
        gap: 12px !important;
        margin: 0 !important;
    }

    .lk-home-panel .lk-section-head h2 {
        font-size: clamp(23px, 2.2vw, 32px) !important;
        line-height: 1 !important;
    }

    .lk-home-panel .lk-section-head p {
        display: none;
    }

    .lk-home-panel .lk-section-head .lk-kicker {
        margin: 0 0 4px !important;
        font-size: 8px !important;
    }

    .lk-home-panel .lk-home-product-carousel {
        display: grid !important;
        width: 100%;
        height: 100%;
        min-height: 0;
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        gap: 10px !important;
        overflow: hidden !important;
        padding: 0 !important;
        scroll-snap-type: none;
    }

    .lk-home-panel .lk-home-product-carousel > .lk-product-card {
        width: 100% !important;
        min-width: 0 !important;
        height: 100% !important;
        min-height: 0 !important;
        flex: initial !important;
        scroll-snap-align: unset;
        border-radius: 14px !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-media {
        height: clamp(78px, 18vh, 150px) !important;
        min-height: 0 !important;
        flex: 0 0 auto !important;
        aspect-ratio: auto !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-palette-tag,
    .lk-home-panel .lk-product-card--palette .lk-product-heart {
        display: none !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-body {
        min-height: 0 !important;
        gap: 3px;
        padding: 8px 10px !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-category,
    .lk-home-panel .lk-product-card--palette .lk-product-seller,
    .lk-home-panel .lk-product-card--palette .lk-product-meta {
        display: none !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-title-link {
        margin-top: 0 !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-name {
        min-height: 30px !important;
        font-size: 12px !important;
        line-height: 1.2 !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-price-row {
        min-height: 22px !important;
        margin-top: 3px !important;
        gap: 5px !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-product-price-row strong {
        font-size: 14px !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-card-actions {
        gap: 5px !important;
        padding-top: 5px !important;
    }

    .lk-home-panel .lk-product-card--palette .lk-btn-view,
    .lk-home-panel .lk-product-card--palette .lk-add-cart,
    .lk-home-panel .lk-product-card--palette .lk-catalog-buy-now {
        min-height: 30px !important;
        padding: 0 6px !important;
        font-size: 9px !important;
    }

    .lk-home-panel--deals .lk-deal-banner {
        display: flex !important;
        min-height: 0 !important;
        justify-content: space-between !important;
        gap: 12px !important;
        padding: 8px 14px !important;
        border-radius: 12px !important;
    }

    .lk-home-panel--deals .lk-deal-banner .lk-kicker,
    .lk-home-panel--deals .lk-deal-banner p {
        display: none !important;
    }

    .lk-home-panel--deals .lk-deal-banner h2 {
        font-size: 23px !important;
    }

    .lk-home-panel--deals .lk-deal-banner .lk-btn {
        min-height: 32px;
        padding: 0 12px;
    }

    .lk-home-panel--deals > .lk-section:last-child {
        display: grid;
        height: 100%;
        grid-template-rows: auto minmax(0, 1fr);
        gap: 8px;
    }

    .lk-home-panel--deals .lk-platform-events-grid {
        height: 100%;
        min-height: 0;
        gap: 10px;
    }

    .lk-home-panel--events .lk-platform-events-grid {
        height: 100%;
        min-height: 0;
    }

    .lk-home-panel--events .lk-event-card {
        min-height: 0;
        gap: 8px;
        padding: 14px;
        border-radius: 14px;
    }

    .lk-home-panel--events .lk-event-card__image,
    .lk-home-panel--events .lk-event-card__details {
        display: none !important;
    }

    .lk-home-panel--events .lk-event-card__offer {
        min-width: 0;
        padding: 6px 10px;
        border-radius: 10px;
    }

    .lk-home-panel--events .lk-event-card__offer strong {
        font-size: clamp(22px, 2.5vw, 30px);
    }

    .lk-home-panel--events .lk-event-card__action {
        min-height: 30px;
        padding: 0 12px;
        font-size: 10px;
    }

    .lk-home-panel--events .lk-event-card__title {
        font-size: clamp(24px, 3vw, 36px);
    }

    .lk-home-panel--events .lk-platform-events-empty {
        display: flex;
        min-height: 0;
        height: 100%;
        flex-direction: column;
        justify-content: center;
        padding: 16px;
    }

    @media (max-width: 680px) {
        body.lk-buyer-home-page-flow .lk-main {
            padding: 8px 10px !important;
        }

        body.lk-buyer-home-page-flow .lk-footer span {
            display: none;
        }

        body.lk-buyer-home-page-flow .lk-footer nav {
            width: 100%;
            justify-content: center;
            gap: 14px;
        }

        .lk-home-page {
            grid-template-rows: clamp(96px, 19vh, 142px) 34px minmax(0, 1fr);
            gap: 8px;
        }

        .lk-home-page .lk-home-editorial-hero {
            padding: 12px 16px !important;
        }

        .lk-home-page .lk-home-editorial-copy {
            width: min(78%, 350px);
        }

        .lk-home-page .lk-home-editorial-title {
            font-size: clamp(30px, 8vw, 42px);
        }

        .lk-home-page .lk-home-editorial-description {
            font-size: 10px;
        }

        .lk-home-page .lk-home-editorial-cta {
            min-height: 30px;
            margin-top: 5px;
            padding-inline: 10px;
            font-size: 10px;
        }

        .lk-home-tabs {
            gap: 4px;
        }

        .lk-home-tabs [role="tab"] {
            padding: 0 4px;
            border-radius: 8px;
            font-size: 9px;
        }

        .lk-home-panel--shop:not([hidden]) {
            grid-template-rows: minmax(0, 1fr) auto;
            gap: 6px;
        }

        .lk-home-panel--shop .lk-home-category-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
        }

        .lk-home-panel--shop .lk-home-category-card-footer {
            min-height: 24px;
            padding: 2px 5px;
        }

        .lk-home-panel--shop .lk-home-category-name {
            font-size: 9px;
        }

        .lk-home-panel--shop .lk-quick-grid {
            gap: 5px !important;
        }

        .lk-home-panel--shop .lk-quick-card {
            min-height: 42px !important;
            gap: 5px !important;
            padding: 5px 6px !important;
        }

        .lk-home-panel--shop .lk-quick-icon {
            width: 28px !important;
            height: 28px !important;
        }

        .lk-home-panel--shop .lk-quick-icon svg {
            width: 16px !important;
            height: 16px !important;
        }

        .lk-home-panel--shop .lk-quick-card strong {
            font-size: 9px !important;
        }

        .lk-home-panel--shop .lk-quick-card small {
            display: none !important;
        }

        .lk-home-panel .lk-section-head h2 {
            font-size: 20px !important;
        }

        .lk-home-panel .lk-section-head .lk-kicker {
            font-size: 7px !important;
        }

        .lk-home-panel .lk-home-product-carousel {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 6px !important;
        }

        .lk-home-panel .lk-home-product-carousel > .lk-product-card:nth-child(n + 3) {
            display: none !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-product-media {
            height: clamp(54px, 14vh, 92px) !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-product-body {
            padding: 5px 7px !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-product-name {
            min-height: 25px !important;
            font-size: 10px !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-product-price-row {
            min-height: 18px !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-product-price-row strong {
            font-size: 12px !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-card-actions {
            padding-top: 3px !important;
        }

        .lk-home-panel .lk-product-card--palette .lk-btn-view,
        .lk-home-panel .lk-product-card--palette .lk-add-cart,
        .lk-home-panel .lk-product-card--palette .lk-catalog-buy-now {
            min-height: 24px !important;
            font-size: 8px !important;
        }

        .lk-home-panel--deals:not([hidden]) {
            grid-template-rows: 36px minmax(0, 1fr);
            gap: 6px;
        }

        .lk-home-panel--deals .lk-deal-banner {
            padding: 4px 10px !important;
        }

        .lk-home-panel--deals .lk-deal-banner h2 {
            font-size: 18px !important;
        }

        .lk-home-panel--deals .lk-deal-banner .lk-btn {
            min-height: 26px;
            font-size: 9px;
        }

        .lk-home-panel--events .lk-platform-events-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
        }

        .lk-home-panel--events .lk-event-card {
            gap: 5px;
            padding: 9px;
        }

        .lk-home-panel--events .lk-event-card__title {
            font-size: 16px;
        }

        .lk-home-panel--events .lk-event-card__hero {
            align-items: center;
            flex-direction: row;
            gap: 6px;
        }

        .lk-home-panel--events .lk-event-card__offer strong {
            font-size: 20px;
        }

        .lk-home-panel--events .lk-event-card__offer {
            padding: 4px 7px;
        }

        .lk-home-panel--events .lk-event-card__action {
            min-height: 24px;
            padding-inline: 8px;
            font-size: 8px;
        }
    }

    @media (max-height: 620px) {
        body.lk-buyer-home-page-flow .lk-main {
            padding-block: 6px !important;
        }

        .lk-home-page {
            grid-template-rows: clamp(82px, 18vh, 112px) 32px minmax(0, 1fr);
            gap: 6px;
        }

        .lk-home-page .lk-home-editorial-description {
            display: none;
        }

        .lk-home-page .lk-home-editorial-cta {
            min-height: 26px;
            margin-top: 4px;
            font-size: 9px;
        }

        .lk-home-tabs [role="tab"] {
            font-size: 9px;
        }

        body.lk-buyer-home-page-flow .lk-footer:not(:has(.lk-footer-inner)) {
            min-height: 32px;
            padding-block: 3px;
        }

        .lk-home-panel--events .lk-event-card {
            gap: 5px;
            padding: 8px;
        }

        .lk-home-panel--events .lk-event-card__title {
            font-size: 20px;
        }

        .lk-home-panel--events .lk-event-card__action {
            min-height: 24px;
        }
    }

    .lk-home-deferred-status {
        margin: 10px 0;
        color: #765f54;
        font-size: 12px;
    }

    .lk-home-deferred-status.is-error {
        color: #8a271d;
    }

    .lk-home-deferred-slot[aria-busy="true"] {
        min-height: 220px;
    }

    .lk-home-skeleton-heading {
        width: min(260px, 58%);
        height: 17px;
        border-radius: 999px;
        background: #eee5dc;
    }

    .lk-home-skeleton-copy {
        width: min(360px, 76%);
        height: 10px;
        margin-top: 8px;
        border-radius: 999px;
        background: #f1eae3;
    }

    .lk-home-skeleton-card {
        overflow: hidden;
        min-width: 0;
        border: 1px solid #eadccc;
        border-radius: 16px;
        background: #fffdf9;
    }

    .lk-home-skeleton-media,
    .lk-home-skeleton-line {
        background: linear-gradient(90deg, #f3ede6 25%, #ebe2d9 42%, #f3ede6 63%);
        background-size: 300% 100%;
        animation: lk-home-skeleton-shimmer 1.4s ease-in-out infinite;
    }

    .lk-home-skeleton-media {
        aspect-ratio: 600 / 552;
    }

    .lk-home-skeleton-body {
        display: grid;
        gap: 9px;
        min-height: 132px;
        padding: 13px;
    }

    .lk-home-skeleton-line {
        width: 72%;
        height: 9px;
        border-radius: 999px;
    }

    .lk-home-skeleton-line.is-short { width: 42%; }
    .lk-home-skeleton-line.is-long { width: 92%; }

    .lk-home-skeleton-promo {
        min-height: 112px;
        border: 1px solid #eadccc;
        border-radius: 16px;
        background: #fffdf9;
    }

    @keyframes lk-home-skeleton-shimmer {
        to { background-position-x: -300%; }
    }

    @media (prefers-reduced-motion: reduce) {
        .lk-home-skeleton-media,
        .lk-home-skeleton-line {
            animation: none;
            background: #f0e9e2;
        }
    }

    @media (max-width: 640px) {
        .lk-home-page .lk-home-hero-slide { min-height: 390px; }
        .lk-home-page .lk-home-editorial-hero {
            align-items: flex-start;
            padding: 30px 22px 70px !important;
            background-position: center right, 62% center;
        }
        .lk-home-page .lk-home-editorial-copy,
        .lk-home-page .lk-home-hero-event-copy { width: min(82%, 420px); }
        .lk-home-page .lk-home-editorial-title { font-size: clamp(48px, 13vw, 64px); }
        .lk-home-page .lk-home-hero-event-title { font-size: clamp(42px, 11vw, 58px); }
        .lk-home-page .lk-home-hero-artwork::after {
            background: linear-gradient(90deg, rgba(255, 251, 246, .96) 0%, rgba(255, 251, 246, .88) 42%, rgba(255, 251, 246, .45) 72%, rgba(255, 251, 246, .12) 100%);
        }
        .lk-home-page .lk-home-hero-controls { right: 12px; bottom: 12px; }
        .lk-home-page .lk-home-hero-arrow { width: 32px; height: 32px; }
    }
    @media (max-width: 640px) and (prefers-reduced-motion: reduce) {
        .lk-home-page .lk-home-hero-slide { transition: none; }
    }
    @media (max-width: 640px) {
        html.dark .lk-home-page .lk-home-hero-artwork::after {
            background: linear-gradient(90deg, rgba(23, 18, 16, .96) 0%, rgba(23, 18, 16, .9) 42%, rgba(23, 18, 16, .55) 72%, rgba(23, 18, 16, .14) 100%);
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

    $featuredProducts = $products->take(6);

    /*
    |--------------------------------------------------------------------------
    | Front-end-safe optional routes
    |--------------------------------------------------------------------------
    */

    $hasWishlistRoute = \Illuminate\Support\Facades\Route::has('buyer.wishlist');

    $wishlistUrl = $hasWishlistRoute
        ? route('buyer.wishlist')
        : '#';

    $categoryRecords = collect($categories ?? []);
    $homeCategories = $categoryRecords
        ->filter(fn ($category) => data_get($category, 'parent_id') === null)
        ->shuffle()
        ->values();
    $categoryChildren = $categoryRecords
        ->filter(fn ($category) => data_get($category, 'parent_id') !== null)
        ->groupBy(fn ($category) => data_get($category, 'parent_id'));
    $categoryPages = $homeCategories->chunk(4)->values();
    $categoryTotal = $homeCategories->count();
@endphp

<div class="lk-page lk-home-page">
    @php $homeHeroSlideCount = 1 + $homeCarouselEvents->count(); @endphp
    <section class="lk-home-hero-carousel" data-home-hero-carousel aria-label="LIKHAE promotions" aria-roledescription="carousel">
        <div class="lk-home-hero-track">
            <article class="lk-home-editorial-hero lk-home-hero-slide" data-home-hero-slide aria-roledescription="slide" aria-label="1 of {{ $homeHeroSlideCount }}" aria-hidden="false">
                <div class="lk-home-editorial-copy">
                    <h1 class="lk-home-editorial-title" id="buyer-home-editorial-title">
                        Everything<br>for your
                        <span>everyday.</span>
                    </h1>
                    <p class="lk-home-editorial-description">Browse the things you need, and a few things you&rsquo;ll love.</p>
                    <a class="lk-home-editorial-cta" href="{{ route('buyer.products') }}">
                        <span>Shop all products</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 5l7 7-7 7"/></svg>
                    </a>
                </div>
            </article>
            @foreach($homeCarouselEvents as $campaign)
                @php
                    $slideIndex = $loop->iteration;
                    $desktopHeroUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($campaign->homepage_carousel_desktop_image_path);
                    $mobileHeroPath = $campaign->homepage_carousel_mobile_image_path;
                    $mobileHeroUrl = $mobileHeroPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($mobileHeroPath)
                        ? \Illuminate\Support\Facades\Storage::disk('public')->url($mobileHeroPath)
                        : null;
                    $heroCtaUrl = $campaign->homepage_carousel_cta_destination === 'OFFERS'
                        ? route('buyer.platform-events.index')
                        : route('buyer.platform-events.show', $campaign);
                @endphp
                <article class="lk-home-editorial-hero lk-home-hero-slide lk-home-hero-slide--event" data-home-hero-slide aria-roledescription="slide" aria-label="{{ $slideIndex + 1 }} of {{ $homeHeroSlideCount }}" aria-hidden="true" inert>
                    <picture class="lk-home-hero-artwork">
                        @if($mobileHeroUrl)<source media="(max-width: 640px)" srcset="{{ $mobileHeroUrl }}">@endif
                        <img src="{{ $desktopHeroUrl }}" alt="{{ $campaign->homepage_carousel_headline ?: $campaign->name }}" loading="lazy" decoding="async" fetchpriority="low">
                    </picture>
                    <div class="lk-home-hero-event-copy">
                        <span class="lk-home-hero-event-kicker">LIKHAE Marketplace Event</span>
                        <h2 class="lk-home-hero-event-title">{{ $campaign->homepage_carousel_headline ?: $campaign->name }}</h2>
                        @if(filled($campaign->homepage_carousel_description))
                            <p class="lk-home-hero-event-description">{{ $campaign->homepage_carousel_description }}</p>
                        @endif
                        <a class="lk-home-editorial-cta" href="{{ $heroCtaUrl }}">
                            <span>{{ $campaign->homepage_carousel_cta_label ?: 'View Offer' }}</span>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h15M13 5l7 7-7 7"/></svg>
                        </a>
                    </div>
                </article>
            @endforeach
        </div>
        @if($homeCarouselEvents->isNotEmpty())
            <div class="lk-home-hero-controls" role="group" aria-label="Hero carousel controls">
                <button class="lk-home-hero-arrow" type="button" data-home-hero-previous aria-label="Previous promotion">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <div class="lk-home-hero-pagination" aria-label="Choose a promotion">
                    @for($slideIndex = 0; $slideIndex < $homeHeroSlideCount; $slideIndex++)
                        <button class="lk-home-hero-dot" type="button" data-home-hero-dot="{{ $slideIndex }}" aria-label="Show promotion {{ $slideIndex + 1 }}" aria-current="{{ $slideIndex === 0 ? 'true' : 'false' }}"></button>
                    @endfor
                </div>
                <button class="lk-home-hero-arrow" type="button" data-home-hero-next aria-label="Next promotion">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>
        @endif
    </section>

    <section class="lk-home-flow-section" aria-label="Shop and promotions">

    <section class="lk-home-category-section" aria-labelledby="categories-heading" data-category-carousel data-category-total="{{ $categoryTotal }}">
        <div class="lk-home-category-heading">
            <h2 id="categories-heading">Shop by category</h2>
            <div class="lk-home-category-tools">
                <span class="lk-home-category-cue" title="Category order changes when you visit Home">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                    <span>Shuffled each visit</span>
                </span>
                <span class="lk-home-category-count">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <span>4 at a time</span>
                </span>
                <div class="lk-home-category-nav" role="group" aria-label="Category carousel controls">
                    <span class="lk-home-category-range" data-category-range aria-live="polite">{{ $categoryTotal > 0 ? '1–'.min(4, $categoryTotal).' of '.$categoryTotal : '0–0 of 0' }}</span>
                    <button class="lk-home-category-arrow" type="button" data-category-previous aria-label="Previous categories" disabled>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    </button>
                    <button class="lk-home-category-arrow" type="button" data-category-next aria-label="Next categories" @disabled($categoryPages->count() < 2)>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <div class="lk-home-category-pages" data-category-pages>
            @forelse($categoryPages as $pageIndex => $pageCategories)
                <div class="lk-home-category-page" data-category-page data-page-index="{{ $pageIndex }}" role="group" aria-roledescription="slide" aria-label="Page {{ $pageIndex + 1 }} of {{ $categoryPages->count() }}" @if($pageIndex > 0) hidden @endif>
                    <div class="lk-home-category-grid">
                        @foreach($pageCategories as $category)
                            @php
                                $categoryName = (string) data_get($category, 'name', 'Category');
                                $categorySlug = (string) data_get($category, 'slug', '');
                                $categoryId = data_get($category, 'id');
                                $childrenCount = (int) $categoryChildren->get($categoryId, collect())->sum(fn ($child) => (int) data_get($child, 'products_count', 0));
                                $productCount = (int) data_get($category, 'products_count', 0) + $childrenCount;
                                $categoryImage = null;
                                $imageCandidates = collect(['image_url', 'image_path', 'image', 'thumbnail_url', 'thumbnail_path', 'thumbnail', 'cover_image_url', 'cover_image_path', 'cover_image'])
                                    ->map(fn ($attribute) => data_get($category, $attribute))
                                    ->filter(fn ($candidate) => is_string($candidate) && trim($candidate) !== '' && ! preg_match('/^(?:https?:)?\/\//i', trim($candidate)) && ! preg_match('/^[a-z][a-z0-9+.-]*:/i', trim($candidate)));
                                foreach ($imageCandidates as $candidate) {
                                    $candidate = trim($candidate);
                                    $publicPath = ltrim($candidate, '/');
                                    if (str_starts_with($publicPath, 'images/') && ! str_contains($publicPath, '..') && is_file(public_path($publicPath))) {
                                        $categoryImage = asset($publicPath);
                                        break;
                                    }
                                    $storagePath = preg_replace('#^storage/#', '', $publicPath);
                                    $storagePath = preg_replace('#^public/#', '', $storagePath);
                                    if (! str_contains($storagePath, '..') && \Illuminate\Support\Facades\Storage::disk('public')->exists($storagePath)) {
                                        $categoryImage = asset('storage/'.ltrim($storagePath, '/'));
                                        break;
                                    }
                                }
                                $defaultCategoryWebpPath = preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $categorySlug)
                                    ? 'images/buyer/categories/'.$categorySlug.'-maroon-v2.webp'
                                    : null;
                                $defaultCategoryPngPath = $defaultCategoryWebpPath ? preg_replace('/\.webp$/', '.png', $defaultCategoryWebpPath) : null;
                                $defaultCategoryImage = $defaultCategoryWebpPath && is_file(public_path($defaultCategoryWebpPath))
                                    ? asset($defaultCategoryWebpPath)
                                    : ($defaultCategoryPngPath && is_file(public_path($defaultCategoryPngPath)) ? asset($defaultCategoryPngPath) : null);
                                $defaultCategoryFallback = $defaultCategoryWebpPath && $defaultCategoryPngPath
                                    && is_file(public_path($defaultCategoryWebpPath)) && is_file(public_path($defaultCategoryPngPath))
                                    ? asset($defaultCategoryPngPath)
                                    : null;
                                $categoryImage = $defaultCategoryImage ?? $categoryImage;
                                $categoryImageFallback = $categoryImage === $defaultCategoryImage ? $defaultCategoryFallback : null;
                                $categoryImagePath = (string) (parse_url($categoryImage ?? '', PHP_URL_PATH) ?: '');
                                $categoryCardPath = preg_match('#^/images/buyer/categories/([a-z0-9-]+-maroon-v2)\.webp$#', $categoryImagePath, $categoryImageMatch)
                                    ? 'images/buyer/categories/'.$categoryImageMatch[1].'-card.webp'
                                    : null;
                                $categoryCardImage = $categoryCardPath && is_file(public_path($categoryCardPath)) ? asset($categoryCardPath) : null;
                                $isIllustration = $categoryImage && str_ends_with(mb_strtolower(parse_url($categoryImage, PHP_URL_PATH) ?: ''), '.svg');
                            @endphp
                            <a class="lk-home-category-card" href="{{ route('buyer.products', ['category' => $categorySlug]) }}">
                                <span class="lk-home-category-visual">
                                    @if($categoryImage)
                                        @if($categoryImageFallback)
                                            <picture>
                                                <source srcset="{{ $categoryCardImage ? $categoryCardImage.' 680w, '.$categoryImage.' 1698w' : $categoryImage }}" sizes="(max-width: 720px) 42vw, (max-width: 1200px) 23vw, 280px" type="image/webp">
                                                <img src="{{ $categoryImageFallback }}" @if($categoryCardImage) width="680" height="371" @endif alt="" loading="lazy" decoding="async" @class(['is-illustration' => $isIllustration])>
                                            </picture>
                                        @else
                                            <img src="{{ $categoryCardImage ?? $categoryImage }}" @if($categoryCardImage) srcset="{{ $categoryCardImage }} 680w, {{ $categoryImage }} 1698w" sizes="(max-width: 720px) 42vw, (max-width: 1200px) 23vw, 280px" width="680" height="371" @endif alt="" loading="lazy" decoding="async" @class(['is-illustration' => $isIllustration])>
                                        @endif
                                    @else
                                        <span aria-hidden="true"></span>
                                    @endif
                                </span>
                                <span class="lk-home-category-card-footer">
                                    <span class="lk-home-category-name">{{ $categoryName }}</span>
                                    <span class="lk-home-category-products">{{ $productCount }}<span class="sr-only"> products</span></span>
                                    <span class="lk-home-category-link-arrow" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                                </span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="lk-home-category-empty" role="status">No active categories are available right now.</p>
            @endforelse
        </div>

        @if($categoryPages->count() > 1)
            <div class="lk-home-category-pagination" aria-label="Choose category page">
                @foreach($categoryPages as $pageIndex => $pageCategories)
                    <button class="lk-home-category-dot" type="button" data-category-dot="{{ $pageIndex }}" aria-label="Show category page {{ $pageIndex + 1 }}" aria-current="{{ $pageIndex === 0 ? 'true' : 'false' }}"></button>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Quick actions --}}
    <section class="lk-section" aria-labelledby="quick-actions-heading">
        <div class="sr-only">
            <h2 id="quick-actions-heading">Quick Actions</h2>
        </div>

        <div class="lk-quick-grid">
            <a href="{{ route('buyer.discounts') }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20.6 13.4-7.2 7.2a2 2 0 0 1-2.8 0l-7.2-7.2a2 2 0 0 1 0-2.8l7.2-7.2A2 2 0 0 1 12 2.8h7.2a2 2 0 0 1 2 2V12a2 2 0 0 1-.6 1.4Z"/><circle cx="16.5" cy="7.5" r="1"/></svg></div>
                <div><strong>Discounts</strong><small>Products on sale</small></div>
            </a>
            <a href="{{ route('buyer.flash-deals') }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m13 2-3 8H5l6 12 3-8h5L13 2Z"/></svg></div>
                <div><strong>Flash Deals</strong><small>Limited-time offers</small></div>
            </a>
            <a href="{{ route('buyer.rewards', ['tab' => 'vouchers']) }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8a2 2 0 0 0 0 4v5a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5a2 2 0 0 1 0-4V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v1Z"/><path d="M13 5v2m0 4v2m0 4v2"/></svg></div>
                <div><strong>Vouchers</strong><small>Claim and save</small></div>
            </a>
            <a href="{{ route('buyer.free-gifts') }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7"/><path d="M2 7h20v5H2z"/><path d="M12 7v14"/><path d="M12 7H7.5A2.5 2.5 0 1 1 10 4.5z"/><path d="M12 7h4.5A2.5 2.5 0 1 0 14 4.5z"/></svg></div>
                <div><strong>Free Gifts</strong><small>Buy and get rewards</small></div>
            </a>
            <a href="{{ route('buyer.bundle-deals') }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/><path d="M12 8v5"/></svg></div>
                <div><strong>Bundle Deals</strong><small>Save when you shop together</small></div>
            </a>
            <a href="{{ route('buyer.wishlist') }}" class="lk-quick-card">
                <div class="lk-quick-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/></svg></div>
                <div><strong>Wishlist</strong><small>Your saved products</small></div>
            </a>
        </div>
    </section>

    <div
        id="home-offers"
        class="lk-home-deferred-slot"
        data-home-deferred-slot
        data-deferred-url="{{ route('buyer.home.marketing') }}"
        data-deferred-label="Marketplace offers"
        aria-busy="true"
    >
        <div data-deferred-content class="lk-home-deferred-skeleton" aria-hidden="true">
            <section class="lk-section lk-home-marketing-section">
                <div class="lk-section-head">
                    <div><div class="lk-home-skeleton-heading"></div><div class="lk-home-skeleton-copy"></div></div>
                    <span class="lk-home-skeleton-line is-short"></span>
                </div>
                <div class="lk-product-grid lk-home-product-grid">
                    @for($card = 0; $card < 5; $card++)
                        <article class="lk-home-skeleton-card"><div class="lk-home-skeleton-media"></div><div class="lk-home-skeleton-body"><span class="lk-home-skeleton-line is-short"></span><span class="lk-home-skeleton-line is-long"></span><span class="lk-home-skeleton-line"></span></div></article>
                    @endfor
                </div>
            </section>
        </div>
        <p class="lk-home-deferred-status" data-deferred-status role="status" aria-live="polite" hidden></p>
        <button class="lk-btn lk-btn-light" type="button" data-deferred-retry hidden>Try again</button>
        <noscript><p class="lk-home-deferred-status"><a href="{{ route('buyer.discounts') }}">Discounts</a> &middot; <a href="{{ route('buyer.flash-deals') }}">Flash Deals</a> &middot; <a href="{{ route('buyer.bundle-deals') }}">Bundle Deals</a> &middot; <a href="{{ route('buyer.free-gifts') }}">Free Gifts</a> &middot; <a href="{{ route('buyer.rewards', ['tab' => 'vouchers']) }}">Vouchers</a></p></noscript>
    </div>

    </section>

    <section class="lk-home-flow-section" aria-label="Featured products">
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
                <span aria-hidden="true">â†’</span>
            </a>
        </div>

        @if ($featuredProducts->isNotEmpty())
            <div class="lk-product-grid lk-home-product-grid" role="region" aria-label="Featured products">
                @foreach ($featuredProducts as $product)
                    <x-buyer.product-card :product="$product" home />
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
    </section>

    <section class="lk-home-flow-section" aria-label="Recommended products">
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
                    Based on stores you follow and products you view, save, or add to your cart. Popular picks fill in when your activity is limited.
                </p>
            </div>

            <div class="lk-recommendation-actions">
                <a
                    href="{{ route('buyer.products', ['sort' => 'best-rated']) }}"
                    class="lk-text-link"
                >
                    See All
                    <span aria-hidden="true">â†’</span>
                </a>
            </div>
        </div>

        <div class="lk-product-grid lk-home-recommendation-grid" role="region" aria-label="Recommended products" data-home-recommendation-grid data-recommendation-url="{{ route('buyer.home.recommendations') }}" data-cache-key="buyer-home-recommendations-{{ auth()->id() }}-{{ hash('sha256', session()->getId()) }}" data-next-offset="0" data-exclude-product-ids="{{ implode(',', $recommendationExcludedProductIds ?? []) }}"></div>
        <div class="lk-product-grid lk-home-recommendation-grid" data-recommendation-skeleton aria-hidden="true">
            @for($card = 0; $card < 3; $card++)
                <article class="lk-home-skeleton-card"><div class="lk-home-skeleton-media"></div><div class="lk-home-skeleton-body"><span class="lk-home-skeleton-line is-short"></span><span class="lk-home-skeleton-line is-long"></span><span class="lk-home-skeleton-line"></span></div></article>
            @endfor
        </div>
        <div class="lk-home-recommendation-more" data-recommendation-sentinel>
            <span data-recommendation-status role="status" aria-live="polite">Recommendations load automatically as you scroll.</span>
            <button type="button" data-recommendation-retry hidden>Try again</button>
        </div>
        <div class="rounded-2xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center" data-recommendation-empty hidden>
            <h3 class="text-sm font-semibold text-stone-900">More recommendations will appear here</h3>
            <p class="mt-1 text-xs text-stone-500">Explore products to help us prepare recommendations for you.</p>
            <a href="{{ route('buyer.products') }}" class="mt-4 inline-flex items-center justify-center rounded-xl bg-red-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-red-950">Explore Products</a>
        </div>
        <noscript><p class="mt-4 text-xs text-stone-500"><a href="{{ route('buyer.products', ['sort' => 'best-rated']) }}">Browse best-rated products</a></p></noscript>
    </section>
    </section>
</div>
@endsection

@push('scripts')
<script>
    (() => {
        document.querySelectorAll('[data-home-hero-carousel]').forEach((carousel) => {
            if (carousel.dataset.initialized === 'true') return;
            carousel.dataset.initialized = 'true';

            const slides = Array.from(carousel.querySelectorAll('[data-home-hero-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-home-hero-dot]'));
            const previous = carousel.querySelector('[data-home-hero-previous]');
            const next = carousel.querySelector('[data-home-hero-next]');
            const track = carousel.querySelector('.lk-home-hero-track');
            if (slides.length < 2 || !previous || !next || !track) return;

            const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');
            let activeIndex = Math.max(0, slides.findIndex((slide) => slide.getAttribute('aria-hidden') === 'false'));
            let timer = null;
            let pointerInside = false;
            let focusInside = carousel.contains(document.activeElement);
            let pageHidden = document.hidden;
            let touchStartX = null;

            const clearTimer = () => {
                if (timer !== null) window.clearTimeout(timer);
                timer = null;
            };
            const shouldPause = () => pointerInside || focusInside || pageHidden || motionPreference.matches;
            const schedule = () => {
                clearTimer();
                if (shouldPause()) return;
                timer = window.setTimeout(() => {
                    showSlide(activeIndex + 1);
                    schedule();
                }, 7000);
            };
            const showSlide = (requestedIndex) => {
                activeIndex = (requestedIndex + slides.length) % slides.length;
                slides.forEach((slide, index) => {
                    const active = index === activeIndex;
                    slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                    slide.inert = !active;
                });
                dots.forEach((dot, index) => {
                    dot.setAttribute('aria-current', index === activeIndex ? 'true' : 'false');
                });
            };

            previous.addEventListener('click', () => { showSlide(activeIndex - 1); schedule(); });
            next.addEventListener('click', () => { showSlide(activeIndex + 1); schedule(); });
            dots.forEach((dot) => dot.addEventListener('click', () => {
                showSlide(Number(dot.dataset.homeHeroDot || 0));
                schedule();
            }));
            carousel.addEventListener('pointerenter', () => { pointerInside = true; clearTimer(); });
            carousel.addEventListener('pointerleave', () => { pointerInside = false; schedule(); });
            carousel.addEventListener('focusin', () => { focusInside = true; clearTimer(); });
            carousel.addEventListener('focusout', () => {
                window.setTimeout(() => {
                    focusInside = carousel.contains(document.activeElement);
                    schedule();
                }, 0);
            });
            carousel.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') {
                    showSlide(activeIndex - 1);
                    schedule();
                } else if (event.key === 'ArrowRight') {
                    showSlide(activeIndex + 1);
                    schedule();
                }
            });
            track.addEventListener('touchstart', (event) => {
                touchStartX = event.changedTouches[0]?.clientX ?? null;
            }, { passive: true });
            track.addEventListener('touchend', (event) => {
                const endX = event.changedTouches[0]?.clientX;
                if (touchStartX === null || endX === undefined) return;
                const distance = endX - touchStartX;
                touchStartX = null;
                if (Math.abs(distance) < 45) return;
                showSlide(activeIndex + (distance < 0 ? 1 : -1));
                schedule();
            }, { passive: true });
            document.addEventListener('visibilitychange', () => {
                pageHidden = document.hidden;
                schedule();
            });
            motionPreference.addEventListener?.('change', schedule);

            showSlide(activeIndex);
            schedule();
        });

        document.querySelectorAll('[data-category-carousel]').forEach((carousel) => {
            const pages = Array.from(carousel.querySelectorAll('[data-category-page]'));
            const dots = Array.from(carousel.querySelectorAll('[data-category-dot]'));
            const previous = carousel.querySelector('[data-category-previous]');
            const next = carousel.querySelector('[data-category-next]');
            const range = carousel.querySelector('[data-category-range]');
            const total = Number(carousel.dataset.categoryTotal || 0);

            carousel.querySelectorAll('.lk-home-category-visual img').forEach((image) => {
                image.addEventListener('error', () => image.remove(), { once: true });
            });

            if (!pages.length || !previous || !next || !range) return;

            let currentPage = 0;

            const showPage = (requestedPage) => {
                currentPage = Math.max(0, Math.min(requestedPage, pages.length - 1));
                pages.forEach((page, index) => {
                    page.hidden = index !== currentPage;
                });
                dots.forEach((dot, index) => {
                    dot.setAttribute('aria-current', index === currentPage ? 'true' : 'false');
                });

                const firstCategory = currentPage * 4 + 1;
                const lastCategory = Math.min((currentPage + 1) * 4, total);
                range.textContent = `${firstCategory}–${lastCategory} of ${total}`;
                previous.disabled = currentPage === 0;
                next.disabled = currentPage === pages.length - 1;
            };

            previous.addEventListener('click', () => showPage(currentPage - 1));
            next.addEventListener('click', () => showPage(currentPage + 1));
            dots.forEach((dot) => {
                dot.addEventListener('click', () => showPage(Number(dot.dataset.categoryDot || 0)));
            });
            showPage(0);
        });

        document.querySelectorAll('[data-home-deferred-slot]').forEach((slot) => {
            const content = slot.querySelector('[data-deferred-content]');
            const status = slot.querySelector('[data-deferred-status]');
            const retryButton = slot.querySelector('[data-deferred-retry]');
            const url = slot.dataset.deferredUrl;
            const label = slot.dataset.deferredLabel || 'This section';
            if (!content || !status || !retryButton || !url) return;

            const skeleton = content.innerHTML;
            let loading = false;
            let loaded = false;
            let observer = null;

            const load = async () => {
                if (loading || loaded) return;
                loading = true;
                content.hidden = false;
                if (!content.innerHTML.trim()) content.innerHTML = skeleton;
                status.hidden = false;
                status.classList.remove('is-error');
                status.textContent = `Loading ${label.toLowerCase()}â€¦`;
                retryButton.hidden = true;
                slot.setAttribute('aria-busy', 'true');

                try {
                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!response.ok) throw new Error('Deferred section could not be loaded.');
                    const payload = await response.json();
                    if (typeof payload.html !== 'string') throw new Error('Deferred section could not be loaded.');

                    const template = document.createElement('template');
                    template.innerHTML = payload.html;
                    template.content.querySelectorAll('style').forEach((style) => style.remove());
                    content.replaceChildren(template.content);
                    content.hidden = false;
                    loaded = true;
                    status.hidden = true;
                    observer?.disconnect();
                    document.dispatchEvent(new CustomEvent('catalog:results-updated', {
                        detail: { root: content },
                    }));
                } catch {
                    content.hidden = true;
                    status.hidden = false;
                    status.classList.add('is-error');
                    status.textContent = `${label} could not be loaded.`;
                    retryButton.hidden = false;
                    observer?.unobserve(slot);
                } finally {
                    loading = false;
                    slot.setAttribute('aria-busy', 'false');
                }
            };

            retryButton.addEventListener('click', load);
            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) load();
                }, { rootMargin: '560px 0px' });
                observer.observe(slot);
            } else {
                status.hidden = false;
                status.textContent = `Load ${label.toLowerCase()} when ready.`;
                retryButton.hidden = false;
                slot.setAttribute('aria-busy', 'false');
            }
        });

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-home-offers-refresh]');
            if (!button || button.disabled) return;

            const section = button.closest('[data-home-offers]');
            const status = section?.querySelector('[data-home-offers-status]');
            const sourceUrl = section?.dataset.homeOffersUrl;
            if (!section || !status || !sourceUrl) return;

            const rotation = (Number(section.dataset.homeOffersRotation || 0) + 1) % 100000;
            const url = new URL(sourceUrl, window.location.href);
            url.searchParams.set('rotation', String(rotation));
            button.disabled = true;
            section.setAttribute('aria-busy', 'true');
            status.textContent = 'Finding fresh offersâ€¦';

            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error('Offers could not be refreshed.');
                const payload = await response.json();
                if (typeof payload.html !== 'string') throw new Error('Offers could not be refreshed.');

                const template = document.createElement('template');
                template.innerHTML = payload.html;
                template.content.querySelectorAll('style').forEach((style) => style.remove());
                const updatedSection = template.content.querySelector('[data-home-offers]');
                if (!updatedSection) throw new Error('Offers could not be refreshed.');

                updatedSection.dataset.homeOffersRotation = String(rotation);
                updatedSection.setAttribute('aria-busy', 'false');
                const updatedStatus = updatedSection.querySelector('[data-home-offers-status]');
                if (updatedStatus) updatedStatus.textContent = 'Showing fresh offers.';
                section.replaceWith(updatedSection);
                document.dispatchEvent(new CustomEvent('catalog:results-updated', {
                    detail: { root: updatedSection },
                }));
                updatedSection.querySelector('[data-home-offers-refresh]')?.focus({ preventScroll: true });
            } catch {
                section.setAttribute('aria-busy', 'false');
                button.disabled = false;
                status.textContent = 'Offers could not be refreshed. Please try again.';
            }
        });

        document.querySelectorAll('[data-home-recommendation-grid]').forEach((grid) => {
            const sentinel = grid.parentElement?.querySelector('[data-recommendation-sentinel]');
            const status = sentinel?.querySelector('[data-recommendation-status]');
            const retryButton = sentinel?.querySelector('[data-recommendation-retry]');
            const skeleton = grid.parentElement?.querySelector('[data-recommendation-skeleton]');
            const emptyState = grid.parentElement?.querySelector('[data-recommendation-empty]');
            if (!sentinel || !status || !retryButton) return;

            let loadedCount = 0;
            let isLoading = false;
            let hasMore = true;
            let retryOffset = Number(grid.dataset.nextOffset || 0);
            let retryLimit = 6;
            let observer = null;
            let checkBottom = () => {};
            const cacheKey = grid.dataset.cacheKey || '';
            let lastScrollY = window.scrollY;
            let lastScrollAt = performance.now();
            let recentScrollSpeed = 0;

            const saveRecommendations = () => {
                if (!cacheKey) return;
                try {
                    window.sessionStorage.setItem(cacheKey, JSON.stringify({
                        version: 1,
                        html: grid.innerHTML,
                        loadedCount,
                        nextOffset: retryOffset,
                        hasMore,
                    }));
                } catch {}
            };

            const restoreRecommendations = () => {
                if (!cacheKey) return false;
                try {
                    const saved = JSON.parse(window.sessionStorage.getItem(cacheKey) || 'null');
                    if (!saved
                        || saved.version !== 1
                        || typeof saved.html !== 'string'
                        || !Number.isInteger(saved.loadedCount)
                        || saved.loadedCount < 0
                        || !Number.isInteger(saved.nextOffset)
                        || saved.nextOffset !== saved.loadedCount
                        || typeof saved.hasMore !== 'boolean') {
                        return false;
                    }

                    const template = document.createElement('template');
                    template.innerHTML = saved.html;
                    template.content.querySelectorAll('style').forEach((style) => style.remove());
                    grid.append(template.content);

                    loadedCount = saved.loadedCount;
                    retryOffset = saved.nextOffset;
                    hasMore = saved.hasMore;
                    grid.dataset.loadedCount = String(loadedCount);
                    grid.dataset.nextOffset = hasMore ? String(retryOffset) : '';
                    if (skeleton) skeleton.hidden = true;
                    if (emptyState) emptyState.hidden = grid.children.length > 0;
                    status.textContent = hasMore
                        ? 'Saved recommendations restored. More load automatically as you scroll.'
                        : 'You’ve reached the end of your recommendations.';
                    document.dispatchEvent(new CustomEvent('catalog:results-updated', {
                        detail: { root: grid },
                    }));
                    return true;
                } catch {
                    try {
                        window.sessionStorage.removeItem(cacheKey);
                    } catch {}
                    return false;
                }
            };

            const currentScrollSpeed = () => recentScrollSpeed * Math.exp(-(performance.now() - lastScrollAt) / 600);
            const batchSizeForSpeed = () => {
                const speed = currentScrollSpeed();
                if (speed >= 3200) return 36;
                if (speed >= 2400) return 30;
                if (speed >= 1600) return 24;
                if (speed >= 900) return 18;
                if (speed >= 350) return 12;
                return 6;
            };
            const trackScrollSpeed = () => {
                const now = performance.now();
                const elapsed = Math.max(1, now - lastScrollAt);
                const distance = Math.abs(window.scrollY - lastScrollY);
                const decayedSpeed = currentScrollSpeed();
                recentScrollSpeed = Math.max((distance / elapsed) * 1000, decayedSpeed);
                lastScrollY = window.scrollY;
                lastScrollAt = now;
            };
            window.addEventListener('scroll', trackScrollSpeed, { passive: true });

            const loadPage = async (targetOffset, targetLimit = batchSizeForSpeed()) => {
                if (isLoading || targetOffset !== loadedCount || !hasMore) return;
                if (targetLimit < 6 || targetLimit > 36) return;

                isLoading = true;
                let loaded = false;
                retryOffset = targetOffset;
                retryLimit = targetLimit;
                status.textContent = 'Loading recommendations…';
                retryButton.hidden = true;
                grid.setAttribute('aria-busy', 'true');
                if (loadedCount === 0 && skeleton) skeleton.hidden = false;

                try {
                    const url = new URL(grid.dataset.recommendationUrl, window.location.href);
                    url.searchParams.set('recommendation_offset', String(targetOffset));
                    url.searchParams.set('recommendation_limit', String(targetLimit));
                    const excludedProductIds = grid.dataset.excludeProductIds || '';
                    if (excludedProductIds) url.searchParams.set('exclude', excludedProductIds);

                    const response = await fetch(url, {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) throw new Error('Recommendations could not be loaded.');

                    const payload = await response.json();
                    if (typeof payload.html !== 'string'
                        || typeof payload.has_more !== 'boolean'
                        || !Number.isInteger(payload.next_offset)
                        || payload.next_offset < targetOffset
                        || payload.next_offset > targetOffset + targetLimit
                        || (payload.has_more && payload.next_offset === targetOffset)) {
                        throw new Error('Recommendations could not be loaded.');
                    }
                    loaded = true;

                    const template = document.createElement('template');
                    template.innerHTML = payload.html;
                    template.content.querySelectorAll('style').forEach((style) => style.remove());
                    grid.append(template.content);
                    document.dispatchEvent(new CustomEvent('catalog:results-updated', {
                        detail: { root: grid },
                    }));

                    const loadedThisBatch = payload.next_offset - targetOffset;
                    loadedCount = payload.next_offset;
                    grid.dataset.loadedCount = String(loadedCount);
                    hasMore = payload.has_more;
                    retryOffset = loadedCount;
                    retryLimit = batchSizeForSpeed();
                    grid.dataset.nextOffset = hasMore ? String(retryOffset) : '';
                    saveRecommendations();
                    if (skeleton) skeleton.hidden = true;
                    if (emptyState) emptyState.hidden = grid.children.length > 0;

                    status.textContent = hasMore
                        ? `Loaded ${loadedThisBatch} more. More load automatically as you scroll.`
                        : 'You’ve reached the end of your recommendations.';
                    if (!grid.children.length) status.textContent = 'No recommendations are available right now.';
                    if (!hasMore) observer?.disconnect();
                } catch {
                    if (skeleton) skeleton.hidden = true;
                    status.textContent = 'Recommendations could not be loaded.';
                    retryButton.textContent = 'Try again';
                    retryButton.hidden = false;
                } finally {
                    isLoading = false;
                    grid.setAttribute('aria-busy', 'false');
                    if (loaded && hasMore) {
                        if (observer) {
                            observer.unobserve(sentinel);
                            observer.observe(sentinel);
                        }
                        window.requestAnimationFrame(checkBottom);
                    }
                }
            };

            retryButton.addEventListener('click', () => loadPage(retryOffset, retryLimit));
            checkBottom = () => {
                if (hasMore && sentinel.getBoundingClientRect().top <= window.innerHeight + 250) {
                    loadPage(retryOffset);
                }
            };
            window.addEventListener('scroll', checkBottom, { passive: true });
            window.addEventListener('resize', checkBottom);

            restoreRecommendations();

            if ('IntersectionObserver' in window) {
                observer = new IntersectionObserver((entries) => {
                    if (entries.some((entry) => entry.isIntersecting)) loadPage(retryOffset);
                }, { rootMargin: '250px 0px' });
                observer.observe(sentinel);
            } else {
                checkBottom();
            }
        });

    })();
</script>
@endpush
