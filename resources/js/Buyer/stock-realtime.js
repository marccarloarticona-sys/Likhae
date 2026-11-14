import {startRealtimeFallback} from '../shared/realtime-fallback.js';

const watchedNodes = () => [...document.querySelectorAll('[data-stock-watch-product-id]')];
if (!watchedNodes().length) {
    // Product pages without stock controls do not need a websocket.
} else {
    const formatStock = (stock) => new Intl.NumberFormat('en-PH').format(Math.max(0, Number(stock) || 0));
    const subscribedProducts = new Set();
    let pollPromise = null;
    let lastPollAt = 0;

    const watchedIds = () => [...new Set(watchedNodes()
        .map((node) => Number(node.dataset.stockWatchProductId))
        .filter((id) => Number.isInteger(id) && id > 0))];

    const subscribeToWatchedProducts = () => {
        if (!window.Echo?.channel) return;

        watchedIds().forEach((productId) => {
            if (subscribedProducts.has(productId)) return;
            try {
                window.Echo.channel(`products.${productId}`)
                    .listen('.product.stock.updated', applyStockUpdate);
                subscribedProducts.add(productId);
            } catch (_) {
                // Polling still works when the browser cannot open a channel.
            }
        });
    };

    const updateCard = (card, payload) => {
        const count = card.querySelector('[data-stock-count]');
        if (count) {
            const stock = Math.max(0, Number(payload.total_stock) || 0);
            count.textContent = stock > 0 ? `${formatStock(stock)} available` : 'Out of stock';
        }

        const variantId = card.dataset.stockSingleVariantId;
        const variant = variantId ? payload.variants?.[variantId] : null;
        const canPurchase = Boolean(variant && payload.visible && !payload.seller_on_vacation && variant.is_active && Number(variant.stock) > 0);
        card.querySelectorAll('[data-cart-add-form] button[type="submit"], [data-catalog-buy-now-form] button[type="submit"]').forEach((button) => {
            button.disabled = !canPurchase;
            button.setAttribute('aria-disabled', String(!canPurchase));

            if (button.matches('.lk-catalog-buy-now')) button.textContent = canPurchase ? 'Buy Now' : 'Out of Stock';
            else if (button.matches('.lk-catalog-add-cart')) button.textContent = canPurchase ? 'Add to Cart' : 'Out of Stock';
            else {
                const label = button.querySelector('span');
                if (label) label.textContent = canPurchase ? 'Add to Cart' : 'Out of Stock';
            }
        });
    };

    const updateProductDetail = (detail, payload) => {
        const stocks = {};
        const currentStocks = payload.variants || {};
        let previousStocks = {};
        try { previousStocks = JSON.parse(detail.dataset.variantStocks || '{}'); } catch (_) {}

        Object.keys(previousStocks).forEach((variantId) => {
            stocks[variantId] = Number(currentStocks[variantId]?.stock || 0);
        });
        Object.entries(currentStocks).forEach(([variantId, variant]) => {
            stocks[variantId] = Number(variant.stock) || 0;
        });

        detail.dataset.variantStocks = JSON.stringify(stocks);
        detail.querySelectorAll('[data-variation-option]').forEach((button) => {
            const variantId = button.dataset.variationId;
            if (variantId) button.dataset.variationStock = String(stocks[variantId] || 0);
        });
    };

    const updateCartItem = (item, payload) => {
        const variant = payload.variants?.[item.dataset.stockWatchVariantId];

        const active = Boolean(payload.visible && !payload.seller_on_vacation && variant?.is_active);
        const stock = active ? Math.max(0, Number(variant.stock) || 0) : 0;
        item.dataset.itemStock = String(stock);
        item.dataset.itemAvailable = active ? '1' : '0';
        if (!active) item.dataset.availabilityMessage = payload.seller_on_vacation ? 'This seller is on vacation.' : 'This product is no longer available.';

        const quantity = item.querySelector('[data-cart-quantity]');
        if (quantity) {
            quantity.max = String(stock);
            quantity.disabled = !active;
        }
        item.querySelectorAll('[data-cart-select], [data-quantity-minus], [data-quantity-plus]').forEach((control) => {
            control.disabled = !active;
        });

        const availability = item.querySelector('[data-stock-availability]');
        if (availability) {
            availability.textContent = !active
                ? item.dataset.availabilityMessage
                : `${formatStock(stock)} pieces available`;
            const quantityValue = Number(quantity?.value || 1);
            const unavailable = !active || stock < quantityValue;
            availability.classList.toggle('font-semibold', unavailable);
            availability.classList.toggle('text-red-700', unavailable);
            availability.classList.toggle('text-stone-400', !unavailable);
        }
    };

    const updateCheckoutItem = (item, payload) => {
        const variant = payload.variants?.[item.dataset.stockWatchVariantId];

        const active = Boolean(payload.visible && !payload.seller_on_vacation && variant?.is_active);
        const stock = active ? Math.max(0, Number(variant.stock) || 0) : 0;
        item.dataset.liveStock = String(stock);
        item.dataset.stockActive = active ? '1' : '0';

        const count = item.querySelector('[data-stock-count]');
        if (count) count.textContent = active ? `${formatStock(stock)} available` : 'No longer available';
    };

    function applyStockUpdate(payload) {
        const productId = Number(payload?.product_id);
        if (!Number.isInteger(productId) || productId < 1) return;

        watchedNodes()
            .filter((node) => Number(node.dataset.stockWatchProductId) === productId)
            .forEach((node) => {
                if (node.matches('[data-product-card]')) updateCard(node, payload);
                if (node.matches('[data-variant-stock-product]')) updateProductDetail(node, payload);
                if (node.matches('[data-cart-item]')) updateCartItem(node, payload);
                if (node.matches('[data-checkout-stock-item]')) updateCheckoutItem(node, payload);
            });

        document.dispatchEvent(new CustomEvent('buyer:stock-updated', {detail: payload}));
    }

    const pollStock = async () => {
        if (pollPromise || Date.now() - lastPollAt < 1200) return pollPromise;
        const ids = watchedIds();
        if (!ids.length) return;

        lastPollAt = Date.now();
        pollPromise = (async () => {
            for (let offset = 0; offset < ids.length; offset += 80) {
                const endpoint = new URL('/api/v1/products/stock', window.location.origin);
                ids.slice(offset, offset + 80).forEach((id) => endpoint.searchParams.append('ids[]', String(id)));

                try {
                    const response = await fetch(endpoint, {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {'Accept': 'application/json'},
                    });
                    if (!response.ok) continue;
                    const payload = await response.json();
                    (Array.isArray(payload?.data) ? payload.data : []).forEach(applyStockUpdate);
                } catch (_) {
                    // Keep the last known count and retry on the next fallback interval.
                }
            }
        })().finally(() => { pollPromise = null; });

        return pollPromise;
    };

    const start = async () => {
        try { await import('../shared/echo.js'); } catch (_) { /* HTTP polling remains available without Echo. */ }
        subscribeToWatchedProducts();
        void pollStock();
        startRealtimeFallback(pollStock, {intervalMs: 15000});
        window.setInterval(() => {
            if (!document.hidden && window.Echo?.connector?.pusher?.connection?.state === 'connected') void pollStock();
        }, 30000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && window.Echo?.connector?.pusher?.connection?.state === 'connected') void pollStock();
        });
    };

    document.addEventListener('catalog:results-updated', subscribeToWatchedProducts);
    void start();
}
