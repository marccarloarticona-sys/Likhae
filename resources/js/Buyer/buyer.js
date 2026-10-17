import '../address/forms.js';
import { startRealtimeFallback } from '../shared/realtime-fallback.js';
import './hero-carousel.js';

document.addEventListener('DOMContentLoaded', () => {
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const body = document.body;
    const sidebar = one('[data-lk-sidebar]');
    const mobileButton = one('[data-lk-mobile-menu]');
    const collapseButton = one('[data-lk-sidebar-toggle]');
    const overlay = one('[data-lk-overlay]');
    const toast = one('#lkBuyerToast');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const storageKey = 'likhae-buyer-sidebar-collapsed';
    const accountMenu = one('[data-account-menu]');
    const accountMenuToggle = one('[data-account-menu-toggle]', accountMenu || document);
    const accountMenuPanel = one('[data-account-menu-panel]', accountMenu || document);

    all('[data-review-comment]').forEach((textarea) => {
        const form = textarea.closest('form');
        const counter = form ? one('[data-review-comment-count]', form) : null;
        if (!counter) return;

        const updateReviewCommentCount = () => {
            counter.textContent = `${Array.from(textarea.value).length} / 500`;
        };

        textarea.addEventListener('input', updateReviewCommentCount);
        updateReviewCommentCount();
    });

    const showToast = (message) => {
        if (!toast || !message) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => toast.classList.remove('is-visible'), 2800);
    };
    window.lkBuyerToast = showToast;

    all('[data-price-range]').forEach((range) => {
        const minSlider = one('[data-price-min-slider]', range);
        const maxSlider = one('[data-price-max-slider]', range);
        const minInput = one('[data-price-min-input]', range);
        const maxInput = one('[data-price-max-input]', range);
        const minOutput = one('[data-price-min-output]', range);
        const maxOutput = one('[data-price-max-output]', range);
        const fill = one('[data-price-range-fill]', range);
        if (!minSlider || !maxSlider || !minInput || !maxInput) return;

        const boundsMin = Number(range.dataset.rangeMin || minSlider.min || 0);
        const boundsMax = Number(range.dataset.rangeMax || maxSlider.max || 1);
        const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP', minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const clamp = (value) => Math.min(boundsMax, Math.max(boundsMin, Number(value)));
        const setPriceText = (output, slider, value) => {
            const price = Math.max(0, Number(value));
            if (output) output.textContent = currency.format(price);
            slider.setAttribute('aria-valuetext', currency.format(price));
        };
        const updateFill = (minimum, maximum) => {
            const span = boundsMax - boundsMin;
            const start = span > 0 ? ((minimum - boundsMin) / span) * 100 : 0;
            const end = span > 0 ? ((maximum - boundsMin) / span) * 100 : 100;
            if (fill) {
                fill.style.left = `${start}%`;
                fill.style.width = `${Math.max(0, end - start)}%`;
            }
        };
        const setSubmittedValue = (input, value) => { input.value = Number(value).toFixed(2); };
        const raiseHandle = (active) => {
            minSlider.style.zIndex = active === minSlider ? '3' : '2';
            maxSlider.style.zIndex = active === maxSlider ? '3' : '2';
        };
        const syncFromSliders = (changed) => {
            let minimum = Number(minSlider.value);
            let maximum = Number(maxSlider.value);
            if (minimum > maximum) {
                if (changed === minSlider) {
                    maximum = minimum;
                    maxSlider.value = String(maximum);
                } else {
                    minimum = maximum;
                    minSlider.value = String(minimum);
                }
            }
            setSubmittedValue(minInput, minimum);
            setSubmittedValue(maxInput, maximum);
            setPriceText(minOutput, minSlider, minimum);
            setPriceText(maxOutput, maxSlider, maximum);
            updateFill(minimum, maximum);
        };

        if (range.dataset.rangeAvailable !== 'true') {
            minSlider.disabled = true;
            maxSlider.disabled = true;
        }
        const currentMin = minInput.value.trim() !== '' && Number.isFinite(Number(minInput.value)) ? clamp(minInput.value) : Number(minSlider.value);
        const currentMax = maxInput.value.trim() !== '' && Number.isFinite(Number(maxInput.value)) ? clamp(maxInput.value) : Number(maxSlider.value);
        if (currentMin <= currentMax) {
            minSlider.value = String(currentMin);
            maxSlider.value = String(currentMax);
            if (minInput.value.trim() !== '') setSubmittedValue(minInput, minSlider.value);
            if (maxInput.value.trim() !== '') setSubmittedValue(maxInput, maxSlider.value);
        } else {
            minSlider.value = String(boundsMin);
            maxSlider.value = String(boundsMax);
            minInput.value = '';
            maxInput.value = '';
        }
        setPriceText(minOutput, minSlider, minSlider.value);
        setPriceText(maxOutput, maxSlider, maxSlider.value);
        updateFill(Number(minSlider.value), Number(maxSlider.value));
        minSlider.addEventListener('focus', () => raiseHandle(minSlider));
        maxSlider.addEventListener('focus', () => raiseHandle(maxSlider));
        minSlider.addEventListener('input', () => { raiseHandle(minSlider); syncFromSliders(minSlider); });
        maxSlider.addEventListener('input', () => { raiseHandle(maxSlider); syncFromSliders(maxSlider); });
    });

    all('[data-live-filters]').forEach((form) => {
        let searchTimer;
        const submitFilters = () => {
            window.clearTimeout(searchTimer);
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        };

        form.addEventListener('change', (event) => {
            if (event.target.matches('select, input[type="range"]')) submitFilters();
        });
        form.addEventListener('input', (event) => {
            if (!event.target.matches('input[type="search"], input[type="text"]')) return;
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(submitFilters, 500);
        });
        form.addEventListener('submit', () => window.clearTimeout(searchTimer));
    });

    const setAccountMenu = (open) => {
        if (!accountMenuToggle || !accountMenuPanel) return;
        accountMenuToggle.setAttribute('aria-expanded', String(open));
        accountMenuPanel.hidden = !open;
    };

    accountMenuToggle?.addEventListener('click', () => setAccountMenu(accountMenuPanel?.hidden));
    document.addEventListener('click', (event) => {
        if (accountMenu && !accountMenu.contains(event.target)) setAccountMenu(false);
    });

    const storedCollapsed = () => {
        try { return localStorage.getItem(storageKey) === 'true'; } catch (_) { return false; }
    };

    const setCollapsed = (collapsed, persist = true) => {
        if (!sidebar || !desktop.matches) return;
        sidebar.classList.toggle('lk-sidebar-collapsed', collapsed);
        body.classList.toggle('lk-sidebar-is-collapsed', collapsed);
        collapseButton?.setAttribute('aria-expanded', String(!collapsed));
        collapseButton?.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        if (persist) {
            try { localStorage.setItem(storageKey, String(collapsed)); } catch (_) {}
        }
    };

    const setMobileOpen = (open) => {
        if (!sidebar) return;
        sidebar.classList.toggle('lk-sidebar-open', open);
        overlay?.classList.toggle('lk-overlay-open', open);
        body.classList.toggle('lk-mobile-sidebar-open', open && !desktop.matches);
        mobileButton?.setAttribute('aria-expanded', String(open));
    };

    if (desktop.matches) setCollapsed(storedCollapsed(), false);
    mobileButton?.addEventListener('click', () => setMobileOpen(!sidebar?.classList.contains('lk-sidebar-open')));
    overlay?.addEventListener('click', () => setMobileOpen(false));
    collapseButton?.addEventListener('click', () => {
        if (!desktop.matches) {
            setMobileOpen(false);
            return;
        }
        setCollapsed(!sidebar?.classList.contains('lk-sidebar-collapsed'));
    });

    const submenuFor = (toggle) => toggle.nextElementSibling?.matches('[data-nav-submenu]') ? toggle.nextElementSibling : null;
    const setSubmenu = (toggle, open) => {
        const submenu = submenuFor(toggle);
        if (!submenu) return;
        toggle.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        if (open) {
            submenu.hidden = false;
            requestAnimationFrame(() => submenu.classList.add('expanded'));
        } else {
            submenu.classList.remove('expanded');
            window.setTimeout(() => { if (!submenu.classList.contains('expanded')) submenu.hidden = true; }, 190);
        }
    };

    all('[data-nav-toggle]').forEach((toggle) => {
        const submenu = submenuFor(toggle);
        if (!submenu) return;
        if (!submenu.hidden) submenu.classList.add('expanded');
        toggle.addEventListener('click', () => {
            if (desktop.matches && sidebar?.classList.contains('lk-sidebar-collapsed')) setCollapsed(false);
            const opening = toggle.getAttribute('aria-expanded') !== 'true';
            if (opening) all('[data-nav-toggle]').filter((item) => item !== toggle).forEach((item) => setSubmenu(item, false));
            setSubmenu(toggle, opening);
        });
    });

    all('.lk-sidebar a').forEach((link) => link.addEventListener('click', () => {
        if (!desktop.matches) setMobileOpen(false);
    }));

    desktop.addEventListener?.('change', (event) => {
        setMobileOpen(false);
        body.classList.remove('lk-sidebar-is-collapsed');
        sidebar?.classList.remove('lk-sidebar-collapsed');
        if (event.matches) setCollapsed(storedCollapsed(), false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setMobileOpen(false);
        if (event.key === 'Escape') setAccountMenu(false);
    });

    const authDialog = one('[data-auth-dialog]');
    all('[data-auth-required]').forEach((button) => button.addEventListener('click', (event) => {
        event.preventDefault();
        const message = button.dataset.authMessage || 'Sign in or create a Buyer account to continue.';
        const copy = one('[data-auth-message]', authDialog || document);
        if (copy) copy.textContent = message;
        if (authDialog?.showModal) authDialog.showModal();
        else window.location.href = button.dataset.loginUrl || '/login';
    }));

    all('[data-detail-thumbnail], [data-gallery-thumb]').forEach((thumbnail) => thumbnail.addEventListener('click', () => {
        const gallery = thumbnail.closest('[data-product-gallery]') || document;
        const mainImage = one('[data-detail-main-image], [data-gallery-main]', gallery);
        if (!mainImage || !thumbnail.dataset.image) return;
        mainImage.src = thumbnail.dataset.image;
        all('[data-detail-thumbnail], [data-gallery-thumb]', gallery).forEach((item) => {
            item.classList.toggle('is-active', item === thumbnail);
            item.classList.toggle('border-red-800', item === thumbnail);
            item.classList.toggle('border-transparent', item !== thumbnail);
            item.setAttribute('aria-pressed', String(item === thumbnail));
        });
    }));

    const syncVariantGallery = (button) => {
        const gallery = button?.closest('[data-variant-stock-product]')?.closest('section')?.querySelector('[data-product-gallery]')
            || document.querySelector('[data-product-gallery]');
        if (!gallery) return;

        let variantImages = [];
        let baseImages = [];
        try { variantImages = JSON.parse(button?.dataset.variationImages || '[]').filter(Boolean); } catch (_) { variantImages = []; }
        try { baseImages = JSON.parse(gallery.dataset.galleryBaseImages || '[]').filter(Boolean); } catch (_) { baseImages = []; }
        const images = [...variantImages, ...baseImages].filter((url, index, allImages) => allImages.indexOf(url) === index);
        if (!images.length) return;

        const mainImage = one('[data-detail-main-image], [data-gallery-main]', gallery);
        if (mainImage) mainImage.src = images[0];

        let thumbs = one('[data-gallery-thumbs]', gallery) || one('.lk-detail-thumbs', gallery);
        if (!thumbs) {
            thumbs = document.createElement('div');
            thumbs.className = 'lk-detail-thumbs';
            thumbs.dataset.galleryThumbs = 'true';
            gallery.appendChild(thumbs);
        }
        thumbs.innerHTML = '';
        thumbs.hidden = images.length < 2;
        images.forEach((url, index) => {
            const thumb = document.createElement('button');
            thumb.type = 'button';
            thumb.className = `lk-detail-thumb${index === 0 ? ' is-active' : ''}`;
            thumb.dataset.galleryThumb = 'true';
            thumb.dataset.image = url;
            thumb.setAttribute('aria-label', `View variation image ${index + 1}`);
            const image = document.createElement('img');
            image.src = url;
            image.alt = `Variation image ${index + 1}`;
            thumb.appendChild(image);
            thumb.addEventListener('click', () => {
                if (mainImage) mainImage.src = url;
                all('[data-gallery-thumb]', gallery).forEach((item) => item.classList.toggle('is-active', item === thumb));
            });
            thumbs.appendChild(thumb);
        });
    };

    all('[data-quantity-control]').forEach((control) => {
        const input = one('input', control);
        const clamp = (value) => Math.min(Number(input?.max || 99), Math.max(Number(input?.min || 1), value));
        one('[data-quantity-minus]', control)?.addEventListener('click', () => { if (input) input.value = clamp(Number(input.value || 1) - 1); });
        one('[data-quantity-plus]', control)?.addEventListener('click', () => { if (input) input.value = clamp(Number(input.value || 1) + 1); });
        input?.addEventListener('change', () => { input.value = clamp(Number(input.value || 1)); });
    });

    all('[data-variation-option]').forEach((button) => button.addEventListener('click', () => {
        const group = button.closest('[data-variation-group]');
        all('[data-variation-option]', group || document).forEach((option) => {
            const selected = option === button;
            option.setAttribute('aria-pressed', String(selected));
            option.classList.toggle('is-selected', selected);
            option.classList.toggle('border-red-800', selected);
            option.classList.toggle('bg-red-50', selected);
            option.classList.toggle('text-red-900', selected);
            option.classList.toggle('border-stone-300', !selected);
            option.classList.toggle('text-stone-700', !selected);
        });
        syncVariantGallery(button);
    }));

    all('[data-variation-picker]').forEach((picker) => {
        const product = picker.closest('[data-variant-stock-product]') || picker.parentElement;
        const variants = all('[data-variation-option]', product).map((button) => {
            let options = {};
            try { options = JSON.parse(button.dataset.optionValues || '{}'); } catch (_) { options = {}; }
            return {button, options, stock: Number(button.dataset.variationStock || 0)};
        });
        const groups = all('[data-variation-choice-group]', picker);
        const selection = () => Object.fromEntries(groups.map((group) => {
            const selected = one('[data-variation-choice][aria-pressed="true"]', group);
            return [group.dataset.optionKey, selected?.dataset.optionValue || ''];
        }));
        const matches = (variant, choices, ignoredKey = '') => Object.entries(choices)
            .every(([key, value]) => key === ignoredKey || !value || variant.options[key] === value);
        const selectChoice = (group, value) => all('[data-variation-choice]', group).forEach((choice) => {
            const selected = choice.dataset.optionValue === value;
            choice.setAttribute('aria-pressed', String(selected));
            choice.classList.toggle('is-selected', selected);
        });
        const syncDisabled = () => {
            const choices = selection();
            groups.forEach((group) => all('[data-variation-choice]', group).forEach((choice) => {
                const possible = variants.some((variant) => variant.stock > 0
                    && variant.options[group.dataset.optionKey] === choice.dataset.optionValue
                    && matches(variant, choices, group.dataset.optionKey));
                choice.disabled = !possible;
                choice.setAttribute('aria-disabled', String(!possible));
            }));
        };
        const resolve = () => {
            let choices = selection();
            const complete = groups.every((group) => Boolean(choices[group.dataset.optionKey]));
            if (!complete) {
                syncDisabled();
                return;
            }
            let variant = variants.find((candidate) => candidate.stock > 0 && matches(candidate, choices));
            variant ||= variants.find((candidate) => matches(candidate, choices));
            if (variant) variant.button.click();
            syncDisabled();
        };

        groups.forEach((group) => all('[data-variation-choice]', group).forEach((choice) => {
            choice.addEventListener('click', () => {
                if (choice.disabled) return;
                selectChoice(group, choice.dataset.optionValue || '');
                resolve();
            });
        }));
        syncDisabled();
    });

    const selectedVariation = (scope) => {
        const selected = one('[data-variation-option][aria-pressed="true"]', scope);
        if (!selected) return null;

        return {
            id: selected.dataset.variationId || '',
            value: selected.dataset.variationValue || '',
            stock: Number(selected.dataset.variationStock || 0),
            price: Number(selected.dataset.variationPrice || 0),
            originalPrice: Number(selected.dataset.variationOriginalPrice || selected.dataset.variationPrice || 0),
            discountType: selected.dataset.variationDiscountType || 'none',
            discountValue: Number(selected.dataset.variationDiscountValue ?? selected.dataset.variationDiscount ?? 0),
            discountAmount: Number(selected.dataset.variationDiscountAmount || 0),
        };
    };

    const cartJsonHeaders = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const cartErrorMessage = (payload) => {
        const validationMessage = payload?.errors
            ? Object.values(payload.errors).flat()[0]
            : null;

        return validationMessage || payload?.message || 'Could not add this product to your cart.';
    };

    const addCartWithConfirmation = async (url, fields, button = null) => {
        if (!url) return;

        if (button) button.disabled = true;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: cartJsonHeaders,
                body: new URLSearchParams(fields),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) throw new Error(cartErrorMessage(payload));

            showToast(payload.message || 'Product added to cart.');
            window.dispatchEvent(new CustomEvent('buyer:cart-added', {detail: payload}));
        } catch (error) {
            showToast(error?.message || 'Could not add this product to your cart.');
        } finally {
            if (button) button.disabled = false;
        }
    };

    all('[data-test-add-cart], [data-test-buy-now]').forEach((button) => button.addEventListener('click', async (event) => {
        event.preventDefault();
        const scope = event.currentTarget.closest('.lk-detail-info-card') || document;
        const picker = one('[data-variation-picker]', scope);
        if (picker && all('[data-variation-choice-group]', picker).some((group) => !one('[data-variation-choice][aria-pressed="true"]', group))) {
            showToast('Please select a variation.');
            return;
        }
        const variation = selectedVariation(scope);
        const cartUrl = event.currentTarget.dataset.cartUrl;

        if (!variation?.id || !cartUrl) {
            showToast(picker ? 'Please select a valid variation.' : 'Please select a variation.');
            return;
        }

        if (variation.stock < 1) {
            showToast('This variation is out of stock.');
            return;
        }

        const requestedQuantity = Number(one('[data-quantity-input]', scope)?.value || 1);
        if (!Number.isInteger(requestedQuantity) || requestedQuantity < 1) {
            showToast('Enter a valid quantity.');
            return;
        }
        if (requestedQuantity > variation.stock) {
            showToast('Requested quantity exceeds available stock.');
            return;
        }
        const remainingAllowance = scope.dataset.purchaseLimitRemaining === undefined
            ? null
            : Number(scope.dataset.purchaseLimitRemaining);
        if (remainingAllowance !== null && requestedQuantity > remainingAllowance) {
            showToast(`You can purchase up to ${remainingAllowance} more unit${remainingAllowance === 1 ? '' : 's'} of this product.`);
            return;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = cartUrl;
        form.style.display = 'none';

        const fields = {
            _token: one('meta[name="csrf-token"]')?.content || '',
            product_variant_id: variation.id,
            quantity: requestedQuantity,
        };

        if (event.currentTarget.dataset.checkout !== '1') {
            await addCartWithConfirmation(cartUrl, fields, event.currentTarget);
            return;
        }

        if (event.currentTarget.dataset.checkout === '1') fields.checkout = '1';

        Object.entries(fields).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });

        event.currentTarget.disabled = true;
        document.body.appendChild(form);
        form.submit();
    }));

    all('[data-buy-now-form]').forEach((form) => form.addEventListener('submit', (event) => {
        const scope = form.closest('.lk-detail-info-card') || document;
        const picker = one('[data-variation-picker]', scope);
        if (picker && all('[data-variation-choice-group]', picker).some((group) => !one('[data-variation-choice][aria-pressed="true"]', group))) {
            event.preventDefault();
            showToast('Please select a variation.');
            return;
        }

        const variation = selectedVariation(scope);
        if (!variation?.id) {
            event.preventDefault();
            showToast(picker ? 'Please select a valid variation.' : 'Please select a variation.');
            return;
        }
        if (variation.stock < 1) {
            event.preventDefault();
            showToast('This variation is out of stock.');
            return;
        }

        const requestedQuantity = Number(one('[data-quantity-input]', scope)?.value || 1);
        if (!Number.isInteger(requestedQuantity) || requestedQuantity < 1) {
            event.preventDefault();
            showToast('Enter a valid quantity.');
            return;
        }
        if (requestedQuantity > variation.stock) {
            event.preventDefault();
            showToast('Requested quantity exceeds available stock.');
            return;
        }
        const remainingAllowance = scope.dataset.purchaseLimitRemaining === undefined
            ? null
            : Number(scope.dataset.purchaseLimitRemaining);
        if (remainingAllowance !== null && requestedQuantity > remainingAllowance) {
            event.preventDefault();
            showToast(`You can purchase up to ${remainingAllowance} more unit${remainingAllowance === 1 ? '' : 's'} of this product.`);
            return;
        }

        const variantField = one('input[name="product_variant_id"]', form);
        const quantityField = one('input[name="quantity"]', form);
        if (variantField) variantField.value = variation.id;
        if (quantityField) quantityField.value = String(requestedQuantity);
        const button = one('[data-test-buy-now]', form);
        if (button) button.disabled = true;
    }));

    all('[data-cart-add-form]').forEach((form) => form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const button = one('button[type="submit"]', form);
        if (!button || button.disabled) return;

        const fields = Object.fromEntries(new FormData(form).entries());
        await addCartWithConfirmation(form.action, fields, button);
    }));

    all('[data-variant-stock-product]').forEach((product) => {
        let stocks = {};
        try { stocks = JSON.parse(product.dataset.variantStocks || '{}'); } catch (_) { return; }
        const sync = () => {
            const variation = selectedVariation(product);
            const stock = variation?.id ? Number(stocks[variation.id] ?? variation.stock ?? 0) : 0;
            const availability = one('[data-variant-availability]', product);
            const quantity = one('[data-quantity-input]', product);
            const price = one('.lk-detail-price', product);
            const hasStock = Object.values(stocks).some((value) => Number(value) > 0);
            if (availability) availability.textContent = variation?.id
                ? (stock ? `${stock} available` : 'Out of stock')
                : (hasStock && one('[data-variation-picker]', product) ? 'Select all variations' : 'Out of stock');
            if (price && variation?.id) price.textContent = `₱${variation.price.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            const oldPrice = one('[data-variation-old-price]', product);
            const discountBadge = one('[data-variation-discount-badge-wrapper]', product);
            const discountBadgeText = one('[data-variation-discount-badge]', product);
            const discountType = String(variation?.discountType || 'none').toLowerCase();
            const discountAmount = variation?.discountAmount || (discountType === 'fixed'
                ? Math.min(variation?.originalPrice || 0, variation?.discountValue || 0)
                : (variation?.originalPrice || 0) * Math.min(100, variation?.discountValue || 0) / 100);
            if (oldPrice && variation?.id) {
                oldPrice.textContent = `₱${variation.originalPrice.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                oldPrice.hidden = !(discountAmount > 0 && variation.originalPrice > variation.price);
            }
            if (discountBadge) {
                discountBadge.hidden = !(discountAmount > 0);
                if (discountBadgeText) {
                    discountBadgeText.textContent = discountType === 'fixed'
                        ? `₱${discountAmount.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})} OFF`
                        : `${(variation?.discountValue || 0).toLocaleString('en-PH', {maximumFractionDigits: 2})}% OFF`;
                }
            }
            const remainingAllowance = product.dataset.purchaseLimitRemaining === undefined
                ? null
                : Number(product.dataset.purchaseLimitRemaining);
            const maxQuantity = Math.max(0, Math.min(stock, remainingAllowance ?? stock));
            if (quantity) {
                quantity.max = Math.max(1, maxQuantity);
                quantity.value = Math.min(Number(quantity.value || 1), Math.max(1, maxQuantity));
            }
            const purchaseBlocked = stock < 1 || product.dataset.sellerVacation === '1' || maxQuantity < 1;
            all('[data-product-purchase]', product).forEach((control) => { control.disabled = purchaseBlocked; control.setAttribute('aria-disabled', String(purchaseBlocked)); });
        };
        all('[data-variation-option]', product).forEach((button) => button.addEventListener('click', sync));
        sync();
        syncVariantGallery(one('[data-variation-option][aria-pressed="true"]', product));
    });

    all('[data-add-cart]').forEach((button) => button.addEventListener('click', () => {
        const source = button.dataset.quantitySource ? one(button.dataset.quantitySource) : null;
        const quantity = source?.value || 1;
        showToast(`${quantity} item${Number(quantity) === 1 ? '' : 's'} added to your cart.`);
    }));

    all('[data-wishlist]').forEach((button) => {
        button.addEventListener('click', async () => {
            const productId = button.dataset.productId;
            const endpoint = button.dataset.wishlistUrl;
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (!productId || !endpoint || !token || button.disabled) return;

            const saved = button.getAttribute('aria-pressed') !== 'true';
            const matchingButtons = all('[data-wishlist]').filter((candidate) => candidate.dataset.productId === productId);
            matchingButtons.forEach((candidate) => { candidate.disabled = true; });

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ saved }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || typeof payload.saved !== 'boolean') {
                    const validationMessage = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(validationMessage || payload.message || 'Your wishlist could not be updated. Please try again.');
                }

                matchingButtons.forEach((candidate) => {
                    const productName = candidate.dataset.productName || 'product';
                    candidate.setAttribute('aria-pressed', String(payload.saved));
                    candidate.classList.toggle('is-active', payload.saved);
                    candidate.setAttribute('aria-label', `${payload.saved ? 'Remove' : 'Save'} ${productName} ${payload.saved ? 'from' : 'to'} wishlist`);
                    const icon = one('[data-wishlist-icon]', candidate);
                    const label = one('[data-wishlist-label]', candidate);
                    if (icon) icon.textContent = payload.saved ? '\u2665' : '\u2661';
                    if (label) label.textContent = payload.saved ? 'Remove from Wishlist' : 'Save to Wishlist';
                });
                showToast(payload.message || 'Wishlist updated.');
                if (!payload.saved && one('[data-wishlist-grid]')) window.location.reload();
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'Your wishlist could not be updated. Please try again.');
            } finally {
                matchingButtons.forEach((candidate) => { candidate.disabled = false; });
            }
        });
    });

    const wishlistForm = one('[data-wishlist-selection-form]');
    all('[data-remove-wishlist-item]').forEach((button) => button.addEventListener('click', () => {
        const productId = button.dataset.removeWishlistItem;
        if (!productId) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `/buyer/wishlist/${encodeURIComponent(productId)}`;
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (token) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_token';
            input.value = token;
            form.appendChild(input);
        }
        document.body.appendChild(form);
        form.submit();
    }));

    if (wishlistForm) {
        const checkboxes = all('[data-wishlist-select]', wishlistForm);
        const removeButton = one('[data-remove-selected]', wishlistForm);
        const count = one('[data-wishlist-selection-count]', wishlistForm);
        const selectAll = one('[data-select-all-wishlist]', wishlistForm);
        const syncSelection = () => {
            const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
            if (removeButton) removeButton.disabled = selected === 0;
            if (count) count.textContent = `${selected} selected`;
            if (selectAll) selectAll.textContent = selected === checkboxes.length ? 'Deselect All' : 'Select All';
        };
        checkboxes.forEach((checkbox) => checkbox.addEventListener('change', syncSelection));
        selectAll?.addEventListener('click', () => {
            const shouldSelect = checkboxes.some((checkbox) => !checkbox.checked);
            checkboxes.forEach((checkbox) => { checkbox.checked = shouldSelect; });
            syncSelection();
        });
        wishlistForm.addEventListener('submit', (event) => {
            if (!checkboxes.some((checkbox) => checkbox.checked)) event.preventDefault();
        });
        syncSelection();
    }

    const parseIds = (value) => {
        try { return JSON.parse(value || '[]').map(Number).filter(Number.isFinite); } catch (_) { return []; }
    };
    const notificationPage = one('[data-buyer-notifications-live]');
    const buyerUserId = one('[data-buyer-user-id]')?.dataset.buyerUserId;
    const formatPhilippineTime = (value) => value
        ? `${new Date(value).toLocaleTimeString('en-PH', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' })} PHT`
        : 'Just now';
    const headline = (value) => String(value || '').replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
    const safeUrl = (value) => {
        try {
            const url = new URL(value || '/buyer/notifications', window.location.origin);
            return url.origin === window.location.origin ? `${url.pathname}${url.search}${url.hash}` : '/buyer/notifications';
        } catch (_) {
            return '/buyer/notifications';
        }
    };

    let refreshNotifications = async () => {};
    if (buyerUserId && window.Echo) {
        const seenNotificationIds = new Set();
        window.Echo.private(`App.Models.User.${buyerUserId}`)
            .listen('.notification.created', (notification) => {
                const notificationId = String(notification?.id || '');
                if (notificationId && seenNotificationIds.has(notificationId)) return;
                if (notificationId) seenNotificationIds.add(notificationId);
                if (notificationPage) {
                    refreshNotifications();
                } else {
                    all('[data-buyer-notification-count]').forEach((badge) => {
                        badge.textContent = String((Number.parseInt(badge.textContent || '0', 10) || 0) + 1);
                        badge.style.display = '';
                    });
                    const unreadLabel = one('[data-notification-unread-label]');
                    if (unreadLabel) {
                        const current = Number.parseInt(unreadLabel.textContent, 10) || 0;
                        unreadLabel.textContent = `${current + 1} unread`;
                    }
                    showToast(notification?.title || 'You have a new notification.');
                }
            });
    }
    if (notificationPage?.dataset.notificationStreamUrl) {
        let polling = false;
        const notificationType = new URLSearchParams(window.location.search).get('type') || 'all';
        const renderNotifications = (rows) => {
            const list = one('[data-notification-list]', notificationPage);
            if (!list) return;
            list.replaceChildren();
            if (!rows.length) {
                const empty = document.createElement('div');
                empty.className = 'p-5';
                empty.textContent = 'No notifications in this section.';
                list.appendChild(empty);
                return;
            }
            rows.forEach((item) => {
                const link = document.createElement('a');
                link.href = safeUrl(item.open_url);
                link.className = `flex gap-3 border-b border-stone-100 p-4 transition last:border-0 hover:bg-stone-50 sm:p-5${item.read_at ? '' : ' bg-red-50/40'}`;
                link.dataset.notificationItem = 'true';
                link.dataset.notificationType = item.category || 'orders';
                const icon = document.createElement('span');
                icon.className = `flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${item.read_at ? 'bg-stone-100 text-stone-500' : 'bg-red-900 text-white'} text-sm font-bold`;
                icon.textContent = item.category === 'messages' ? '✉' : item.category === 'stores' ? '◈' : item.category === 'rewards' ? '%' : item.category === 'account' ? '○' : '□';
                if (item.category === 'announcements') icon.textContent = '!';
                const copy = document.createElement('span');
                copy.className = 'min-w-0 flex-1';
                const top = document.createElement('span');
                top.className = 'flex flex-wrap items-center justify-between gap-2';
                const title = document.createElement('strong');
                title.className = 'text-sm text-stone-900';
                title.textContent = item.title || '';
                const time = document.createElement('time');
                time.className = 'text-[10px] text-stone-400';
                time.textContent = formatPhilippineTime(item.created_at);
                top.append(title, time);
                const message = document.createElement('span');
                message.className = 'mt-1 block text-xs leading-5 text-stone-500';
                message.textContent = item.message || '';
                copy.append(top, message);
                link.append(icon, copy);
                if (!item.read_at) {
                    const unread = document.createElement('i');
                    unread.className = 'mt-2 h-2 w-2 shrink-0 rounded-full bg-red-800';
                    unread.setAttribute('aria-label', 'Unread');
                    link.appendChild(unread);
                }
                list.appendChild(link);
            });
        };
        refreshNotifications = async () => {
            if (polling) return;
            polling = true;
            try {
                const response = await fetch(notificationPage.dataset.notificationStreamUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (!response.ok) return;
                const unreadCount = Math.max(0, Number(payload.unread_count) || 0);
                all('[data-buyer-notification-count]').forEach((badge) => {
                    badge.textContent = String(unreadCount);
                    badge.style.display = unreadCount ? '' : 'none';
                });
                const unreadLabel = one('[data-notification-unread-label]');
                if (unreadLabel) unreadLabel.textContent = unreadCount ? `${unreadCount} unread` : 'You are all caught up';
                if (!payload.version || payload.version === notificationPage.dataset.notificationVersion) return;
                renderNotifications((payload.notifications || []).filter((item) => notificationType === 'all' || item.category === notificationType));
                one('[data-notification-unread-count]')?.replaceChildren(document.createTextNode(String(unreadCount)));
                notificationPage.dataset.notificationVersion = payload.version;
            } catch (_) {
                // Retry on the next fallback interval while Reverb is unavailable.
            } finally {
                polling = false;
            }
        };
        startRealtimeFallback(refreshNotifications);
    }

    const orderPage = one('[data-buyer-order-live]');
    if (orderPage?.dataset.orderStreamUrl) {
        const orderIds = parseIds(orderPage.dataset.orderIds);
        const shipmentIds = parseIds(orderPage.dataset.shipmentIds);
        const detailOrderId = Number(orderPage.dataset.orderDetailId || 0);
        const timelineLabels = {
            PLACED: 'Order placed',
            CONFIRMED: 'Seller confirmed order',
            PREPARING: 'Preparing parcel',
            READY_FOR_PICKUP: 'Ready for pickup',
            PICKED_UP: 'Picked up from seller',
            AT_SORTING_CENTER: 'Arrived at sorting center',
            SORTED: 'Sorted by destination',
            ASSIGNED_TO_RIDER: 'Delivery rider assigned',
            OUT_FOR_DELIVERY: 'Out for delivery',
            DELIVERY_FAILED: 'Delivery attempt unsuccessful',
            DELIVERED: 'Delivered',
            COMPLETED: 'Buyer confirmed receipt',
            RETURNED: 'Parcel returned',
            CANCELLED: 'Order cancelled',
        };
        let orderRequestSequence = 0;
        let orderRequestController = null;

        const renderShipmentTimeline = (shipment) => {
            if (!Array.isArray(shipment.events)) return;
            const timeline = document.querySelector(`[data-shipment-timeline="${shipment.id}"]`);
            if (!timeline) return;

            const rows = shipment.events.map((event) => ({
                status: event.cancelled ? 'CANCELLED' : event.status,
                event,
            })).filter((row) => timelineLabels[row.status]);
            if (shipment.status === 'CANCELLED' && !rows.some((row) => row.status === 'CANCELLED')) {
                rows.push({ status: 'CANCELLED', event: null });
            }
            if (!rows.some((row) => row.status === shipment.status) && timelineLabels[shipment.status]) {
                rows.push({ status: shipment.status, event: null });
            }

            let currentIndex = -1;
            rows.forEach((row, index) => {
                if (row.status === (shipment.status === 'CANCELLED' ? 'CANCELLED' : shipment.status)) currentIndex = index;
            });

            const items = rows.map((row, index) => {
                const isCurrent = index === currentIndex;
                const isComplete = Boolean(row.event);
                const item = document.createElement('li');
                item.className = `lk-shipment-step${isCurrent ? ' is-current' : (isComplete ? ' is-complete' : '')}`;
                if (isCurrent) item.setAttribute('aria-current', 'step');

                const date = document.createElement('span');
                date.className = 'lk-shipment-step__date';
                if (row.event?.occurred_at) {
                    const time = document.createElement('time');
                    time.dateTime = row.event.occurred_at;
                    time.textContent = row.event.display_time || '';
                    date.append(time);
                }

                const dot = document.createElement('span');
                dot.className = 'lk-shipment-step__dot';
                dot.setAttribute('aria-hidden', 'true');
                dot.textContent = isComplete ? '✓' : '';

                const label = document.createElement('span');
                label.className = 'lk-shipment-step__label';
                label.textContent = row.status === 'COMPLETED' && row.event?.auto_received
                    ? 'Automatically confirmed receipt'
                    : timelineLabels[row.status];

                item.append(date, dot, label);
                return item;
            });
            timeline.replaceChildren(...items);
            timeline.dataset.shipmentCurrentStatus = shipment.status;
            timeline.dataset.shipmentCancelled = String(shipment.status === 'CANCELLED');
        };

        const checkOrders = async () => {
            const requestSequence = ++orderRequestSequence;
            orderRequestController?.abort();
            orderRequestController = new AbortController();
            const streamUrl = new URL(orderPage.dataset.orderStreamUrl, window.location.origin);
            orderIds.forEach((id) => streamUrl.searchParams.append('ids[]', String(id)));
            if (detailOrderId > 0) streamUrl.searchParams.set('detail_order_id', String(detailOrderId));
            try {
                const response = await fetch(streamUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: orderRequestController.signal,
                });
                const payload = await response.json();
                if (requestSequence !== orderRequestSequence || !response.ok || !payload.version || payload.version === orderPage.dataset.orderVersion) return;
                const displayStatus = (order) => order.status === 'PROCESSING'
                    && order.shipments?.length
                    && order.shipments.every((shipment) => ['DELIVERED', 'COMPLETED'].includes(shipment.status))
                    ? 'DELIVERED' : order.status;
                const trackingUpdate = (shipment) => {
                    if (['CANCELLED', 'RETURNED'].includes(shipment.status)) return '';
                    if (['DELIVERED', 'COMPLETED'].includes(shipment.status)) return 'Parcel has been delivered';
                    const inTransitLeg = (shipment.transfer_legs || []).find((leg) => leg.status === 'IN_TRANSIT');
                    const centerName = inTransitLeg?.origin_center_name || shipment.current_center_name || '';
                    const centerLabel = centerName
                        ? `${centerName}${/(sorting\s+(centre|center)|hub)/i.test(centerName) ? '' : ' sorting centre'}`
                        : 'the sorting centre';
                    if (inTransitLeg) return `Parcel has departed from ${centerLabel}`;
                    if (['AT_SORTING_CENTER', 'SORTED'].includes(shipment.status)) return `Parcel has arrived at ${centerLabel}`;
                    if (['OUT_FOR_DELIVERY', 'ASSIGNED_TO_RIDER'].includes(shipment.status)) return 'Parcel is out for delivery';
                    if (shipment.status === 'PICKED_UP') return 'Parcel has been picked up from seller';
                    if (shipment.status === 'READY_FOR_PICKUP') return 'Ready to ship';
                    if (shipment.status === 'DELIVERY_FAILED') return 'Delivery attempt was unsuccessful';
                    if (shipment.status === 'PLACED') return 'Waiting for seller to accept your order';
                    return 'Seller is preparing your parcel';
                };
                (payload.orders || []).forEach((order) => {
                    document.querySelectorAll(`[data-order-status-id="${order.id}"]`).forEach((element) => { element.textContent = headline(displayStatus(order)); });
                    (order.shipments || []).forEach((shipment) => {
                        const buyerUpdate = trackingUpdate(shipment);
                        document.querySelectorAll(`[data-shipment-status-id="${shipment.id}"]`).forEach((element) => {
                            element.textContent = buyerUpdate;
                            const updateRow = element.closest('[data-shipping-update]');
                            if (updateRow) updateRow.hidden = !buyerUpdate;
                        });

                        document.querySelectorAll(`[data-shipment-card-status-id="${shipment.id}"]`).forEach((element) => {
                            const autoReceived = shipment.status === 'COMPLETED' && shipment.events?.some((event) => event.auto_received);
                            element.textContent = shipment.status === 'CANCELLED'
                                ? 'Cancelled'
                                : (shipment.status === 'RETURNED'
                                    ? element.dataset.shipmentReturnedLabel
                                    : (autoReceived ? 'Auto Received' : headline(shipment.status)));
                        });
                        renderShipmentTimeline(shipment);
                    });

                    if (detailOrderId === Number(order.id) && order.actions) {
                        const receivedAction = orderPage.querySelector('[data-order-received-action]');
                        const returnAction = orderPage.querySelector('[data-order-return-action]');
                        const deadline = orderPage.querySelector('[data-auto-receipt-deadline]');
                        const detailMessage = orderPage.querySelector('[data-order-detail-message]');
                        if (receivedAction) receivedAction.hidden = !order.actions.can_confirm_received;
                        if (returnAction) returnAction.hidden = !order.actions.can_request_return;
                        if (deadline) {
                            deadline.textContent = order.actions.auto_confirm_label
                                ? `Automatic receipt on ${order.actions.auto_confirm_label}`
                                : '';
                            deadline.hidden = !order.actions.auto_confirm_label;
                        }
                        if (detailMessage) {
                            detailMessage.textContent = order.actions.completed
                                ? (orderPage.querySelector('#reviews') ? 'Your order is complete. You can rate the products below.' : 'Your order was received. Refresh to open the product review forms.')
                                : (order.actions.can_confirm_received ? 'Check the delivered items before confirming receipt.' : 'Review your delivery and order details.');
                        }
                        const progressSteps = Array.from(orderPage.querySelectorAll('[data-order-progress-step]'));
                        const currentProgressLabel = order.actions.completed
                            ? 'To Rate'
                            : (displayStatus(order) === 'DELIVERED' ? 'To Receive' : null);
                        const currentProgressIndex = currentProgressLabel
                            ? progressSteps.findIndex((step) => step.dataset.orderProgressStep === currentProgressLabel)
                            : -1;
                        if (currentProgressIndex >= 0) {
                            progressSteps.forEach((step, index) => {
                                const current = index === currentProgressIndex;
                                const complete = index <= currentProgressIndex;
                                step.classList.toggle('is-current', current);
                                step.classList.toggle('is-complete', complete && !current);
                                if (current) step.setAttribute('aria-current', 'step');
                                else step.removeAttribute('aria-current');
                                const node = step.querySelector('.lk-order-progress__node');
                                if (node) node.textContent = complete ? '✓' : String(index + 1);
                            });
                        }
                    }
                });
                orderPage.dataset.orderVersion = payload.version;
            } catch (error) {
                if (error?.name === 'AbortError') return;
                // Retry on the next fallback interval while Reverb is unavailable.
            }
        };
        shipmentIds.forEach((shipmentId) => {
            window.Echo?.private(`shipments.${shipmentId}`)
                .listen('.shipment.tracking.updated', checkOrders);
        });
        startRealtimeFallback(checkOrders);
    }

});
