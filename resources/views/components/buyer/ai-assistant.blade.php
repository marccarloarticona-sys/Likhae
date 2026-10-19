@props(['page' => 'home', 'pageTitle' => 'Buyer', 'visible' => true])

@php
    /* Only non-sensitive, canonical page metadata is put in the browser. */
    $contexts = [
        'home' => ['page' => 'home', 'features' => ['browse products', 'view categories', 'view cart', 'view orders']],
        'products' => ['page' => 'products', 'features' => ['search products', 'browse categories', 'view product details', 'choose quantity and variations', 'add to cart']],
        'cart' => ['page' => 'cart', 'features' => ['view cart items', 'change quantity', 'select variations', 'apply voucher', 'view discounts', 'checkout']],
        'orders' => ['page' => 'orders', 'features' => ['view order status', 'track parcels', 'confirm receipt', 'leave feedback', 'request return or refund']],
        'messages' => ['page' => 'messages', 'features' => ['view existing buyer messages', 'contact a seller or support']],
        'account' => ['page' => 'account', 'features' => ['update profile', 'manage addresses', 'change password', 'view reviews']],
        'wishlist' => ['page' => 'wishlist', 'features' => ['view saved products', 'remove saved products', 'add saved products to cart']],
        'rewards' => ['page' => 'rewards', 'features' => ['view vouchers', 'view points', 'view cashback']],
    ];
    $context = $contexts[$page] ?? ['page' => 'buyer', 'features' => ['browse products', 'view cart', 'view orders', 'manage your account']];
    $context['role'] = 'buyer';
    $context['pageTitle'] = $pageTitle;
    $aiResponseSound = (bool) data_get(auth()->user()?->notification_preferences, 'ai_response_sound', false);
@endphp

<script>window.LIKHAE_BUYER_AI_PAGE_CONTEXT = @json($context);</script>
<section class="likhae-buyer-ai-widget" data-likhae-buyer-ai data-chat-url="{{ route('buyer.ai.chat') }}" data-ai-assistant-enabled="{{ $visible ? '1' : '0' }}" data-ai-response-sound="{{ $aiResponseSound ? '1' : '0' }}" @unless($visible) hidden @endunless>
    <button id="likhaeBuyerAiHead" class="likhae-buyer-ai-head" type="button" aria-label="Open LIKHAE AI Assistant" aria-controls="likhaeBuyerAiWindow" aria-expanded="false" title="LIKHAE AI Assistant · Drag to move">
        <img class="likhae-buyer-ai-head-icon" src="{{ asset('images/buyer/likhae-ai-logo.png') }}" alt=""><span class="likhae-buyer-ai-online-dot" aria-hidden="true"></span>
    </button>
    <div id="likhaeBuyerAiWindow" class="likhae-buyer-ai-window" role="dialog" aria-modal="false" aria-label="LIKHAE AI Assistant" hidden>
        <header class="likhae-buyer-ai-header">
            <img class="likhae-buyer-ai-avatar" src="{{ asset('images/buyer/likhae-ai-logo.png') }}" alt="">
            <div class="likhae-buyer-ai-title"><strong>LIKHAE AI Assistant</strong><span><i></i><b data-likhae-buyer-ai-state>Offline</b></span></div>
            <div class="likhae-buyer-ai-window-actions">
                <button id="likhaeBuyerAiToggle" type="button" aria-label="Turn LIKHAE AI Assistant on" aria-pressed="false" title="AI is offline — turn on">&#128683;</button>
                <button id="likhaeBuyerAiMinimize" type="button" aria-label="Minimize LIKHAE AI Assistant" title="Minimize">−</button>
                <button id="likhaeBuyerAiClose" type="button" aria-label="Close LIKHAE AI Assistant" title="Close">×</button>
            </div>
        </header>
        <div id="likhaeBuyerAiMessages" class="likhae-buyer-ai-messages" aria-live="polite"></div>
        <form id="likhaeBuyerAiForm" class="likhae-buyer-ai-composer">
            <label class="sr-only" for="likhaeBuyerAiInput">Ask LIKHAE AI</label>
            <textarea id="likhaeBuyerAiInput" rows="1" maxlength="1000" placeholder="Ask LIKHAE AI…"></textarea>
            <button id="likhaeBuyerAiSend" type="submit" aria-label="Send message"><span aria-hidden="true">➤</span></button>
        </form>
    </div>
</section>
