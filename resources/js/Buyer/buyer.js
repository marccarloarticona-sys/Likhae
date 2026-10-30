import '../shared/feedback.js';
import '../shared/shop-share.js';
import { startRealtimeFallback } from '../shared/realtime-fallback.js';

if (document.querySelector('[data-postal-address]')) {
    import('../address/forms.js').catch((error) => console.error('Buyer address fields could not be initialized.', error));
}

if (document.querySelector('[data-hero-carousel]')) {
    import('./hero-carousel.js').catch((error) => console.error('Buyer hero carousel could not be initialized.', error));
}

document.addEventListener('DOMContentLoaded', () => {
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];
    const updateFlashSaleCountdown = (element) => {
        const endAt = Date.parse(element.dateTime || element.getAttribute('datetime') || '');
        if (!Number.isFinite(endAt)) return;
        const remaining = endAt - Date.now();
        if (remaining <= 0) {
            element.textContent = 'Sale ended · refresh to see the current price';
            return;
        }

        const totalSeconds = Math.floor(remaining / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const clock = [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
        element.textContent = days > 0 ? `Ends in ${days}d ${clock}` : `Ends in ${clock}`;
    };
    const updateFlashSaleStartCountdown = (element) => {
        const startsAt = Date.parse(element.dateTime || element.getAttribute('datetime') || '');
        if (!Number.isFinite(startsAt)) return;
        const remaining = startsAt - Date.now();
        if (remaining <= 0) {
            element.textContent = 'Flash Sale is starting - refresh to update the price';
            return;
        }

        const totalSeconds = Math.floor(remaining / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const clock = [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':');
        element.textContent = days > 0 ? `Starts in ${days}d ${clock}` : `Starts in ${clock}`;
    };
    const syncFlashSaleCountdowns = () => {
        all('[data-flash-sale-countdown]').forEach(updateFlashSaleCountdown);
        all('[data-flash-sale-start-countdown]').forEach(updateFlashSaleStartCountdown);
    };
    syncFlashSaleCountdowns();
    window.setInterval(syncFlashSaleCountdowns, 1000);
    const body = document.body;
    const sidebar = one('[data-lk-sidebar]');
    const mobileButton = one('[data-lk-mobile-menu]');
    const collapseButton = one('[data-lk-sidebar-toggle]');
    const overlay = one('[data-lk-overlay]');
    const toast = one('#lkBuyerToast');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const buyerHomeAutoCollapse = body.classList.contains('lk-buyer-home-sidebar-hover');
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
        if (window.lkFeedback) return window.lkFeedback.show(message);
        if (!toast || !message) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(() => toast.classList.remove('is-visible'), 2800);
    };
    window.lkBuyerToast = showToast;

    all('[data-home-voucher-claim]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const button = one('button[type="submit"]', form);
            const card = form.closest('.lk-home-voucher-card');
            const feedback = one('[data-voucher-feedback]', card || form);
            if (!button || button.disabled) return;

            const originalLabel = button.textContent;
            button.disabled = true;
            button.textContent = 'Claiming…';
            if (feedback) feedback.textContent = '';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(result.message || 'This voucher could not be claimed. Refresh and try again.');

                button.textContent = 'Claimed';
                if (feedback) feedback.textContent = result.message || 'Voucher collected. Checkout will confirm eligibility.';
                showToast('Voucher collected');
            } catch (error) {
                button.disabled = false;
                button.textContent = originalLabel;
                if (feedback) feedback.textContent = error.message || 'This voucher could not be claimed. Try again.';
            }
        });
    });

    let marketplaceSearchOptionId = 0;
    all('[data-marketplace-search]').forEach((form) => {
        const input = one('input[name="q"]', form);
        const dropdown = one('[data-market-search-dropdown]', form);
        const heading = one('[data-market-search-heading]', form);
        const options = one('[data-market-search-options]', form);
        const emptyMessage = one('[data-market-search-empty]', form);
        const clearHistory = one('[data-market-search-clear]', form);
        if (!input || !dropdown || !heading || !options || !emptyMessage || !clearHistory) return;

        const historyKey = 'likhae-marketplace-recent-searches-v1';
        const readHistory = () => {
            try {
                const stored = JSON.parse(window.localStorage.getItem(historyKey) || '[]');
                return Array.isArray(stored) ? stored.filter((term) => typeof term === 'string').slice(0, 6) : [];
            } catch (_) {
                return [];
            }
        };
        const saveSearch = (value) => {
            const term = value.trim().slice(0, 120);
            if (!term) return;
            const searches = readHistory().filter((recent) => recent.toLowerCase() !== term.toLowerCase());
            searches.unshift(term);
            try { window.localStorage.setItem(historyKey, JSON.stringify(searches.slice(0, 6))); } catch (_) {}
        };
        const setOpen = (open) => {
            dropdown.hidden = !open;
            input.setAttribute('aria-expanded', String(open));
            if (!open) input.removeAttribute('aria-activedescendant');
        };
        const renderOptions = (items, title) => {
            heading.textContent = title;
            options.replaceChildren();
            items.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.id = `marketplaceSearchOption-${++marketplaceSearchOptionId}`;
                button.className = 'lk-search-dropdown__option';
                button.setAttribute('role', 'option');
                button.setAttribute('aria-selected', 'false');
                button.dataset.searchQuery = item.query || item.label;

                const label = document.createElement('span');
                label.className = 'lk-search-dropdown__label';
                label.textContent = item.label;
                const type = document.createElement('span');
                type.className = 'lk-search-dropdown__type';
                type.textContent = item.type;
                button.append(label, type);
                options.append(button);
            });
            emptyMessage.hidden = true;
            clearHistory.hidden = title !== 'Recent searches' || items.length === 0;
            setOpen(items.length > 0 || !emptyMessage.hidden);
        };
        const showEmptyMessage = (message, title = 'Suggestions') => {
            heading.textContent = title;
            options.replaceChildren();
            emptyMessage.textContent = message;
            emptyMessage.hidden = false;
            clearHistory.hidden = true;
            setOpen(true);
        };
        const showRecentSearches = () => {
            const recent = readHistory().map((term) => ({ label: term, query: term, type: 'Recent' }));
            if (recent.length) renderOptions(recent, 'Recent searches');
            else setOpen(false);
        };

        let searchTimer;
        let requestId = 0;
        let activeOption = -1;
        const findSuggestions = async (query) => {
            const currentRequest = ++requestId;
            const url = new URL(form.dataset.suggestionsUrl, window.location.origin);
            url.searchParams.set('q', query);
            if (form.dataset.suggestionSeller) {
                url.searchParams.set('seller_id', form.dataset.suggestionSeller);
            }
            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!response.ok) throw new Error('Suggestion request failed');
                const payload = await response.json();
                if (currentRequest !== requestId || input.value.trim() !== query) return;
                const suggestions = Array.isArray(payload.suggestions) ? payload.suggestions : [];
                if (suggestions.length) renderOptions(suggestions, 'Suggestions');
                else showEmptyMessage(`No matches yet. Press Enter to search for “${query}”.`);
            } catch (_) {
                if (currentRequest === requestId) setOpen(false);
            }
        };
        const chooseOption = (button) => {
            if (!button) return;
            input.value = button.dataset.searchQuery || '';
            saveSearch(input.value);
            setOpen(false);
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        };
        const setActiveOption = (index) => {
            const buttons = all('.lk-search-dropdown__option', options);
            if (!buttons.length) return;
            activeOption = (index + buttons.length) % buttons.length;
            buttons.forEach((button, buttonIndex) => {
                const active = buttonIndex === activeOption;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', String(active));
            });
            input.setAttribute('aria-activedescendant', buttons[activeOption].id);
        };

        input.addEventListener('focus', () => {
            const query = input.value.trim();
            if (query.length >= 2) findSuggestions(query);
            else if (query.length === 0) showRecentSearches();
        });
        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            activeOption = -1;
            input.removeAttribute('aria-activedescendant');
            const query = input.value.trim();
            if (!query) {
                showRecentSearches();
                return;
            }
            if (query.length < 2) {
                showEmptyMessage('Type at least 2 characters to see suggestions.');
                return;
            }
            searchTimer = window.setTimeout(() => findSuggestions(query), 180);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && !dropdown.hidden) {
                event.preventDefault();
                setActiveOption(activeOption + 1);
            } else if (event.key === 'ArrowUp' && !dropdown.hidden) {
                event.preventDefault();
                setActiveOption(activeOption < 0 ? 0 : activeOption - 1);
            } else if (event.key === 'Enter' && activeOption >= 0 && !dropdown.hidden) {
                event.preventDefault();
                chooseOption(one('.lk-search-dropdown__option.is-active', options));
            } else if (event.key === 'Escape') {
                setOpen(false);
            }
        });
        options.addEventListener('click', (event) => {
            const button = event.target.closest('.lk-search-dropdown__option');
            if (button) chooseOption(button);
        });
        clearHistory.addEventListener('click', () => {
            try { window.localStorage.removeItem(historyKey); } catch (_) {}
            setOpen(false);
        });
        form.addEventListener('submit', () => {
            window.clearTimeout(searchTimer);
            saveSearch(input.value);
            setOpen(false);
        });
        document.addEventListener('click', (event) => {
            if (!form.contains(event.target)) setOpen(false);
        });
    });

    const initializePriceRange = (range) => {
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
    };
    all('[data-price-range]').forEach(initializePriceRange);

    all('[data-live-filters]').forEach((form) => {
        let searchTimer;
        const submitFilters = () => {
            window.clearTimeout(searchTimer);
            if (typeof form.requestSubmit === 'function') form.requestSubmit();
            else form.submit();
        };

        form.addEventListener('change', (event) => {
            if (event.target.matches('select, input[type="range"], input[type="radio"]')) submitFilters();
        });
        form.addEventListener('input', (event) => {
            if (!event.target.matches('input[type="search"], input[type="text"]')) return;
            if (form.hasAttribute('data-marketplace-search') && event.target.matches('input[name="q"]')) {
                window.clearTimeout(searchTimer);
                return;
            }
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(submitFilters, 500);
        });
        form.addEventListener('submit', () => window.clearTimeout(searchTimer));
    });

    all('[data-catalog-filter]').forEach((filter) => {
        const toggle = one('[data-catalog-filter-toggle]', filter);
        const panel = one('.lk-toolbar-filter-panel', filter);
        if (!toggle || !panel) return;

        const setFilterOpen = (open) => {
            filter.classList.toggle('is-open', open);
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', String(open));
        };

        toggle.addEventListener('click', () => setFilterOpen(panel.hidden));
        document.addEventListener('click', (event) => {
            if (!filter.contains(event.target)) setFilterOpen(false);
        });
        filter.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || panel.hidden) return;
            setFilterOpen(false);
            toggle.focus();
        });
    });

    let catalogGrid = one('.lk-products-main .lk-product-grid');
    const catalogViewLinks = all('.lk-products-toolbar .lk-view-toggle');
    const applyCatalogView = (view) => {
        const useListView = view === 'list';
        catalogGrid?.classList.toggle('is-list-view', useListView);
        if (catalogGrid) {
            all('.lk-product-card--palette', catalogGrid).forEach((card) => {
                card.classList.toggle('is-compact', useListView);
            });
        }
        catalogViewLinks.forEach((link) => {
            const isActive = new URL(link.href, window.location.href).searchParams.get('view') === view;
            link.classList.toggle('active', isActive);
            if (isActive) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    };

    if (catalogViewLinks.length) {
        applyCatalogView(new URL(window.location.href).searchParams.get('view') === 'list' ? 'list' : 'grid');

        catalogViewLinks.forEach((link) => {
            link.addEventListener('click', (event) => {
                if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                const nextUrl = new URL(link.href, window.location.href);
                const view = nextUrl.searchParams.get('view') === 'list' ? 'list' : 'grid';
                applyCatalogView(view);
                window.history.pushState({ catalogView: view }, '', nextUrl);
            });
        });

        window.addEventListener('popstate', () => {
            applyCatalogView(new URL(window.location.href).searchParams.get('view') === 'list' ? 'list' : 'grid');
        });
    }

    const catalogFilterForm = one('[data-catalog-ajax-filters]');
    const catalogSortForm = one('[data-catalog-ajax-sort]');
    let catalogResults = one('[data-catalog-results]');
    const catalogResultCount = one('[data-catalog-result-count]');
    const catalogFilter = one('[data-catalog-filter]');
    const catalogFilterToggle = one('[data-catalog-filter-toggle]', catalogFilter || document);
    const catalogFilterPanel = one('.lk-toolbar-filter-panel', catalogFilter || document);
    const catalogCategoryNav = one('[data-catalog-category-nav]');
    const catalogResultKey = (url) => {
        const normalized = new URL(url, window.location.href);
        normalized.searchParams.delete('view');
        normalized.searchParams.sort();
        return `${normalized.pathname}?${normalized.searchParams.toString()}`;
    };
    const normalizeCatalogUrl = (url) => {
        const normalized = new URL(url, window.location.href);
        ['q', 'min_price', 'max_price', 'province', 'municipality'].forEach((name) => {
            if (!normalized.searchParams.get(name)?.trim()) normalized.searchParams.delete(name);
        });
        if (normalized.searchParams.get('category') === 'all') normalized.searchParams.delete('category');
        if (normalized.searchParams.get('rating') === '0') normalized.searchParams.delete('rating');
        if (normalized.searchParams.get('sort') === 'latest') normalized.searchParams.delete('sort');
        if (normalized.searchParams.get('view') === 'grid') normalized.searchParams.delete('view');
        return normalized;
    };
    let lastCatalogResultKey = catalogResultKey(window.location.href);
    let catalogRequestController = null;
    let catalogRequestId = 0;

    const syncCatalogFilterFields = (url) => {
        if (!catalogFilterForm) return;
        ['q', 'category', 'sort', 'view'].forEach((name) => {
            const input = one(`input[type="hidden"][name="${name}"]`, catalogFilterForm);
            const value = url.searchParams.get(name);
            if (value === null) {
                input?.remove();
                return;
            }
            if (input) input.value = value;
            else {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = value;
                catalogFilterForm.prepend(hidden);
            }
        });
    };

    const syncCatalogSortFields = (url) => {
        if (!catalogSortForm) return;
        all('input[type="hidden"]', catalogSortForm).forEach((input) => input.remove());
        url.searchParams.forEach((value, name) => {
            if (name === 'sort' || name === 'page') return;
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = name;
            hidden.value = value;
            catalogSortForm.prepend(hidden);
        });
        const sortSelect = one('select[name="sort"]', catalogSortForm);
        if (sortSelect) sortSelect.value = url.searchParams.get('sort') || 'latest';
    };

    const syncCatalogFilterValues = (documentWithResults) => {
        if (!catalogFilterForm) return;
        const nextForm = documentWithResults.querySelector('[data-catalog-ajax-filters]');
        if (!nextForm) return;

        all('input[name], select[name]', catalogFilterForm).forEach((control) => {
            const matchingControls = all('[name]', nextForm).filter((candidate) => candidate.name === control.name && candidate.type === control.type);
            if (control.type === 'radio') {
                control.checked = matchingControls.some((candidate) => candidate.value === control.value && candidate.checked);
                return;
            }
            const nextControl = matchingControls[0];
            if (nextControl) control.value = nextControl.value;
        });
        all('input[type="range"]', catalogFilterForm).forEach((control) => {
            const nextControl = nextForm.querySelector(`#${CSS.escape(control.id)}`);
            if (!nextControl) return;
            control.value = nextControl.value;
            control.setAttribute('aria-valuetext', nextControl.getAttribute('aria-valuetext') || '');
        });
        all('[data-price-min-output], [data-price-max-output]', catalogFilterForm).forEach((output) => {
            const nextOutput = nextForm.querySelector(`[${output.hasAttribute('data-price-min-output') ? 'data-price-min-output' : 'data-price-max-output'}]`);
            if (nextOutput) output.textContent = nextOutput.textContent;
        });
        const currentPriceRange = one('[data-price-range]', catalogFilterForm);
        const nextPriceRange = nextForm.querySelector('[data-price-range]');
        if (currentPriceRange && nextPriceRange) {
            const focusedRangeControlId = currentPriceRange.contains(document.activeElement)
                ? document.activeElement.id
                : '';
            const refreshedPriceRange = nextPriceRange.cloneNode(true);
            currentPriceRange.replaceWith(refreshedPriceRange);
            initializePriceRange(refreshedPriceRange);
            if (focusedRangeControlId) one(`#${CSS.escape(focusedRangeControlId)}`, refreshedPriceRange)?.focus();
        }

        const errorSummary = one('[data-catalog-ajax-error]', catalogFilterForm);
        if (errorSummary) {
            const messages = all('.lk-filter-error[role="alert"]', nextForm).map((error) => error.textContent.trim()).filter(Boolean);
            all('.lk-filter-error[role="alert"]', catalogFilterForm)
                .filter((error) => error !== errorSummary)
                .forEach((error) => error.remove());
            errorSummary.textContent = [...new Set(messages)].join(' ');
            errorSummary.hidden = messages.length === 0;
        }
    };

    const syncCatalogCategoryNav = (documentWithResults) => {
        if (!catalogCategoryNav) return;
        const nextCategoryNav = documentWithResults.querySelector('[data-catalog-category-nav]');
        if (!nextCategoryNav) return;

        const currentOpenCategory = one('details[data-category-slug][open]', catalogCategoryNav)?.dataset.categorySlug;
        catalogCategoryNav.innerHTML = nextCategoryNav.innerHTML;
        const serverOpenCategory = one('details[data-category-slug][open]', catalogCategoryNav)?.dataset.categorySlug;
        const openCategory = serverOpenCategory || currentOpenCategory;
        all('details[data-category-slug]', catalogCategoryNav).forEach((category) => {
            category.open = category.dataset.categorySlug === openCategory;
        });
    };

    const syncCatalogToolbar = (documentWithResults, url) => {
        const nextCount = documentWithResults.querySelector('[data-catalog-result-count]');
        if (nextCount && catalogResultCount) catalogResultCount.innerHTML = nextCount.innerHTML;

        const nextBadge = documentWithResults.querySelector('.lk-toolbar-filter-count');
        const currentBadge = one('.lk-toolbar-filter-count', catalogFilterToggle || document);
        if (nextBadge && currentBadge) currentBadge.textContent = nextBadge.textContent;
        else if (nextBadge && catalogFilterToggle) catalogFilterToggle.append(nextBadge.cloneNode(true));
        else currentBadge?.remove();

        const nextFilter = documentWithResults.querySelector('[data-catalog-filter]');
        const nextIsOpen = Boolean(nextFilter?.classList.contains('is-open') || (catalogFilterPanel && !catalogFilterPanel.hidden));
        if (catalogFilter && catalogFilterPanel && catalogFilterToggle) {
            catalogFilter.classList.toggle('is-open', nextIsOpen);
            catalogFilterPanel.hidden = !nextIsOpen;
            catalogFilterToggle.setAttribute('aria-expanded', String(nextIsOpen));
        }

        const nextViewLinks = all('.lk-products-toolbar .lk-view-toggle', documentWithResults);
        catalogViewLinks.forEach((link) => {
            const nextLink = nextViewLinks.find((candidate) => candidate.getAttribute('aria-label') === link.getAttribute('aria-label'));
            if (nextLink) link.href = nextLink.href;
        });
        const activeView = url.searchParams.get('view') === 'list' ? 'list' : 'grid';
        catalogViewLinks.forEach((link) => {
            const isActive = new URL(link.href, window.location.href).searchParams.get('view') === activeView;
            link.classList.toggle('active', isActive);
            if (isActive) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });

        syncCatalogFilterValues(documentWithResults);
        syncCatalogFilterFields(url);
        syncCatalogSortFields(url);
        all('input[type="search"][name="q"]').forEach((input) => {
            input.value = url.searchParams.get('q') || '';
        });
    };

    const refreshCatalogResults = async (requestedUrl, { pushHistory = true } = {}) => {
        if (!catalogResults || !catalogResultCount) return;

        const url = normalizeCatalogUrl(requestedUrl);
        catalogRequestController?.abort();
        const controller = new AbortController();
        catalogRequestController = controller;
        const requestId = ++catalogRequestId;
        catalogResults.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok) throw new Error('The product results could not be refreshed.');

            const responseDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
            const nextResults = responseDocument.querySelector('[data-catalog-results]');
            if (!nextResults || !responseDocument.querySelector('[data-catalog-result-count]')) {
                throw new Error('The product results could not be refreshed.');
            }
            if (requestId !== catalogRequestId) return;

            catalogResults.innerHTML = nextResults.innerHTML;
            catalogResults.setAttribute('aria-busy', 'false');
            catalogGrid = one('.lk-products-main .lk-product-grid');
            syncCatalogToolbar(responseDocument, url);
            syncCatalogCategoryNav(responseDocument);
            applyCatalogView(url.searchParams.get('view') === 'list' ? 'list' : 'grid');

            if (pushHistory && url.href !== window.location.href) {
                window.history.pushState({ catalogResults: true }, '', url);
            }
            lastCatalogResultKey = catalogResultKey(url);
            document.dispatchEvent(new CustomEvent('catalog:results-updated', { detail: { root: catalogResults } }));
        } catch (error) {
            if (error?.name !== 'AbortError') {
                showToast(error instanceof Error ? error.message : 'The product results could not be refreshed.');
            }
        } finally {
            if (requestId === catalogRequestId) {
                catalogResults.setAttribute('aria-busy', 'false');
                catalogRequestController = null;
            }
        }
    };

    if (catalogFilterForm) {
        catalogFilterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const url = new URL(catalogFilterForm.action, window.location.href);
            const parameters = new URLSearchParams(new FormData(catalogFilterForm));
            const currentView = new URL(window.location.href).searchParams.get('view');
            if (currentView === 'grid' || currentView === 'list') parameters.set('view', currentView);
            parameters.delete('page');
            url.search = parameters.toString();
            refreshCatalogResults(url);
        });
    }

    if (catalogSortForm) {
        catalogSortForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const url = new URL(catalogSortForm.action, window.location.href);
            const parameters = new URLSearchParams(new FormData(catalogSortForm));
            parameters.delete('page');
            url.search = parameters.toString();
            refreshCatalogResults(url);
        });
    }

    if (catalogCategoryNav) {
        catalogCategoryNav.addEventListener('click', (event) => {
            const summary = event.target instanceof Element ? event.target.closest('summary') : null;
            const requestedCategory = summary?.closest('details[data-category-slug]');
            if (requestedCategory && !requestedCategory.open) {
                all('details[data-category-slug][open]', catalogCategoryNav)
                    .filter((category) => category !== requestedCategory)
                    .forEach((category) => { category.open = false; });
            }

            const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            refreshCatalogResults(link.href);
        });
    }

    const clearCatalogFiltersLink = one('[data-catalog-clear-filter]');
    if (clearCatalogFiltersLink) {
        clearCatalogFiltersLink.addEventListener('click', (event) => {
            if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            refreshCatalogResults(clearCatalogFiltersLink.href);
        });
    }

    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        if (catalogResultKey(url) !== lastCatalogResultKey) refreshCatalogResults(url, { pushHistory: false });
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
        if (buyerHomeAutoCollapse) body.classList.toggle('lk-buyer-home-sidebar-expanded', !collapsed);
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

    if (desktop.matches) setCollapsed(buyerHomeAutoCollapse || storedCollapsed(), false);
    if (buyerHomeAutoCollapse && sidebar) {
        sidebar.addEventListener('pointerenter', () => setCollapsed(false, false));
        sidebar.addEventListener('pointerleave', () => {
            if (!sidebar.contains(document.activeElement)) setCollapsed(true, false);
        });
        sidebar.addEventListener('focusin', () => setCollapsed(false, false));
        sidebar.addEventListener('focusout', (event) => {
            if (!sidebar.contains(event.relatedTarget) && !sidebar.matches(':hover')) {
                setCollapsed(true, false);
            }
        });
    }
    mobileButton?.addEventListener('click', () => setMobileOpen(!sidebar?.classList.contains('lk-sidebar-open')));
    overlay?.addEventListener('click', () => setMobileOpen(false));
    collapseButton?.addEventListener('click', () => {
        if (!desktop.matches) {
            setMobileOpen(false);
            return;
        }
        setCollapsed(!sidebar?.classList.contains('lk-sidebar-collapsed'), !buyerHomeAutoCollapse);
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
        body.classList.remove('lk-buyer-home-sidebar-expanded');
        if (event.matches) setCollapsed(buyerHomeAutoCollapse || storedCollapsed(), false);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setMobileOpen(false);
        if (event.key === 'Escape') setAccountMenu(false);
    });

    const authDialog = one('[data-auth-dialog]');
    document.addEventListener('click', (event) => {
        const button = event.target instanceof Element ? event.target.closest('[data-auth-required]') : null;
        if (!button) return;
        event.preventDefault();
        const message = button.dataset.authMessage || 'Sign in or create a Buyer account to continue.';
        const copy = one('[data-auth-message]', authDialog || document);
        if (copy) copy.textContent = message;
        if (authDialog?.showModal) authDialog.showModal();
        else window.location.href = button.dataset.loginUrl || '/login';
    });

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
        const product = selected.closest('[data-variant-stock-product]');
        let flashSales = {};
        try { flashSales = JSON.parse(product?.dataset.variationFlashSales || '{}'); } catch (_) {}
        const flashSale = flashSales[selected.dataset.variationId || ''] || {};

        return {
            id: selected.dataset.variationId || '',
            value: selected.dataset.variationValue || '',
            stock: Number(selected.dataset.variationStock || 0),
            price: Number(selected.dataset.variationPrice || 0),
            originalPrice: Number(selected.dataset.variationOriginalPrice || selected.dataset.variationPrice || 0),
            discountType: selected.dataset.variationDiscountType || 'none',
            discountValue: Number(selected.dataset.variationDiscountValue ?? selected.dataset.variationDiscount ?? 0),
            discountAmount: Number(selected.dataset.variationDiscountAmount || 0),
            flashSaleStatus: flashSale.status || '',
            flashSaleActive: flashSale.active ?? selected.dataset.variationFlashSaleActive === '1',
            flashSalePrice: Number(flashSale.price ?? 0),
            flashSaleRemaining: Number(flashSale.remaining ?? selected.dataset.variationFlashSaleRemaining ?? 0),
            flashSaleStartsAt: flashSale.starts_at || '',
            flashSaleEndsAt: flashSale.ends_at || selected.dataset.variationFlashSaleEndsAt || '',
            flashSaleLimit: flashSale.limit ?? selected.dataset.variationFlashSaleLimit ?? '',
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
        if (variation.flashSaleActive && variation.flashSaleRemaining > 0 && requestedQuantity > variation.flashSaleRemaining) {
            showToast(`Only ${variation.flashSaleRemaining} promotional unit${variation.flashSaleRemaining === 1 ? '' : 's'} remain for this variation.`);
            return;
        }
        if (variation.flashSaleActive && Number(variation.flashSaleLimit) > 0 && requestedQuantity > Number(variation.flashSaleLimit)) {
            showToast(`This flash sale allows up to ${variation.flashSaleLimit} unit${Number(variation.flashSaleLimit) === 1 ? '' : 's'} per Buyer.`);
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
        if (variation.flashSaleActive && variation.flashSaleRemaining > 0 && requestedQuantity > variation.flashSaleRemaining) {
            event.preventDefault();
            showToast(`Only ${variation.flashSaleRemaining} promotional unit${variation.flashSaleRemaining === 1 ? '' : 's'} remain for this variation.`);
            return;
        }
        if (variation.flashSaleActive && Number(variation.flashSaleLimit) > 0 && requestedQuantity > Number(variation.flashSaleLimit)) {
            event.preventDefault();
            showToast(`This flash sale allows up to ${variation.flashSaleLimit} unit${Number(variation.flashSaleLimit) === 1 ? '' : 's'} per Buyer.`);
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

    const syncCatalogPurchaseQuantity = (form) => {
        const card = form.closest('[data-product-card]');
        const quantityInput = one('[data-catalog-quantity]', card || document);
        if (!quantityInput) return true;

        const quantity = Number(quantityInput.value);
        const minimum = Number(quantityInput.min || 1);
        const maximum = Number(quantityInput.max || 99);
        if (!Number.isInteger(quantity) || quantity < minimum) {
            showToast('Enter a valid quantity.');
            quantityInput.focus();
            return false;
        }
        if (quantity > maximum) {
            showToast(`Only ${maximum} item${maximum === 1 ? '' : 's'} available.`);
            quantityInput.focus();
            return false;
        }

        const quantityField = one('input[name="quantity"]', form);
        if (quantityField) quantityField.value = String(quantity);
        return true;
    };

    document.addEventListener('click', (event) => {
        const stepButton = event.target instanceof Element ? event.target.closest('[data-catalog-quantity-step]') : null;
        if (!stepButton || stepButton.disabled) return;
        const card = stepButton.closest('[data-product-card]');
        const input = one('[data-catalog-quantity]', card || document);
        if (!input) return;
        const minimum = Number(input.min || 1);
        const maximum = Number(input.max || 99);
        const step = Number(stepButton.dataset.catalogQuantityStep || 0);
        input.value = String(Math.min(maximum, Math.max(minimum, Number(input.value || minimum) + step)));
    });

    document.addEventListener('change', (event) => {
        const input = event.target instanceof HTMLInputElement && event.target.matches('[data-catalog-quantity]')
            ? event.target
            : null;
        if (!input) return;
        const minimum = Number(input.min || 1);
        const maximum = Number(input.max || 99);
        input.value = String(Math.min(maximum, Math.max(minimum, Number(input.value || minimum))));
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement && event.target.matches('[data-cart-add-form]')
            ? event.target
            : null;
        if (!form) return;
        event.preventDefault();

        const button = one('button[type="submit"]', form);
        if (!button || button.disabled || !syncCatalogPurchaseQuantity(form)) return;

        const fields = Object.fromEntries(new FormData(form).entries());
        await addCartWithConfirmation(form.action, fields, button);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement && event.target.matches('[data-catalog-buy-now-form]')
            ? event.target
            : null;
        if (!form) return;
        if (!syncCatalogPurchaseQuantity(form)) {
            event.preventDefault();
            return;
        }
        const button = one('button[type="submit"]', form);
        if (button) button.disabled = true;
    });

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
            const flashSaleBadge = one('[data-variation-flash-sale-badge]', product);
            const flashSaleStock = one('[data-variation-flash-sale-stock]', product);
            const flashSaleRemaining = one('[data-flash-sale-remaining]', product);
            const flashSaleLimit = one('[data-flash-sale-limit]', product);
            const flashSaleCountdown = one('[data-flash-sale-countdown]', product);
            const flashSaleAvailable = Boolean(variation?.flashSaleActive && variation.flashSaleRemaining > 0 && stock > 0);
            if (flashSaleBadge) flashSaleBadge.hidden = !flashSaleAvailable;
            if (flashSaleStock) flashSaleStock.hidden = !flashSaleAvailable;
            if (flashSaleRemaining) flashSaleRemaining.textContent = new Intl.NumberFormat('en-PH').format(variation?.flashSaleRemaining || 0);
            if (flashSaleLimit) {
                flashSaleLimit.hidden = !variation?.flashSaleLimit;
                flashSaleLimit.textContent = variation?.flashSaleLimit
                    ? `Limit ${new Intl.NumberFormat('en-PH').format(Number(variation.flashSaleLimit))} per Buyer`
                    : '';
            }
            if (flashSaleCountdown) {
                const endAt = variation?.flashSaleEndsAt || '';
                flashSaleCountdown.dateTime = endAt;
                if (endAt) flashSaleCountdown.setAttribute('datetime', endAt);
                else flashSaleCountdown.removeAttribute('datetime');
                flashSaleCountdown.hidden = !flashSaleAvailable || !endAt;
                if (!flashSaleCountdown.hidden) updateFlashSaleCountdown(flashSaleCountdown);
            }
            const upcomingFlashSale = one('[data-variation-flash-sale-upcoming]', product);
            const upcomingFlashSalePrice = one('[data-flash-sale-upcoming-price]', product);
            const upcomingFlashSaleStock = one('[data-flash-sale-upcoming-stock]', product);
            const flashSaleStartCountdown = one('[data-flash-sale-start-countdown]', product);
            const flashSaleScheduled = variation?.flashSaleStatus === 'SCHEDULED' && variation.flashSaleRemaining > 0 && stock > 0;
            if (upcomingFlashSale) upcomingFlashSale.hidden = !flashSaleScheduled;
            if (upcomingFlashSalePrice && flashSaleScheduled) {
                upcomingFlashSalePrice.textContent = `₱${variation.flashSalePrice.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
            }
            if (upcomingFlashSaleStock && flashSaleScheduled) {
                upcomingFlashSaleStock.textContent = `${new Intl.NumberFormat('en-PH').format(variation.flashSaleRemaining)} promotional units allocated`;
            }
            if (flashSaleStartCountdown) {
                const startAt = variation?.flashSaleStartsAt || '';
                flashSaleStartCountdown.dateTime = startAt;
                if (startAt) flashSaleStartCountdown.setAttribute('datetime', startAt);
                else flashSaleStartCountdown.removeAttribute('datetime');
                flashSaleStartCountdown.hidden = !flashSaleScheduled || !startAt;
                if (!flashSaleStartCountdown.hidden) updateFlashSaleStartCountdown(flashSaleStartCountdown);
            }
            const remainingAllowance = product.dataset.purchaseLimitRemaining === undefined
                ? null
                : Number(product.dataset.purchaseLimitRemaining);
            const flashSaleQuantityLimit = flashSaleAvailable
                ? Math.min(variation.flashSaleRemaining, Number(variation.flashSaleLimit) > 0 ? Number(variation.flashSaleLimit) : stock)
                : stock;
            const maxQuantity = Math.max(0, Math.min(stock, remainingAllowance ?? stock, flashSaleQuantityLimit));
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

    const bindCatalogProductActions = (root = document) => {
        all('[data-add-cart]', root).forEach((button) => button.addEventListener('click', () => {
            const source = button.dataset.quantitySource ? one(button.dataset.quantitySource) : null;
            const quantity = source?.value || 1;
            showToast(`${quantity} item${Number(quantity) === 1 ? '' : 's'} added to your cart.`);
        }));

        all('[data-wishlist]', root).forEach((button) => {
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
    };

    bindCatalogProductActions();
    document.addEventListener('catalog:results-updated', (event) => {
        if (event.detail?.root instanceof Element) bindCatalogProductActions(event.detail.root);
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
                const titleGroup = document.createElement('span');
                titleGroup.className = 'flex min-w-0 flex-wrap items-center gap-2';
                titleGroup.appendChild(title);
                if (item.priority || item.category === 'announcements') {
                    const priority = document.createElement('span');
                    priority.className = 'rounded-full border border-red-200 bg-red-50 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300';
                    priority.textContent = 'Priority';
                    titleGroup.appendChild(priority);
                }
                const time = document.createElement('time');
                time.className = 'text-[10px] text-stone-400';
                time.textContent = formatPhilippineTime(item.created_at);
                top.append(titleGroup, time);
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

            const labelForRow = (row) => row.status === 'COMPLETED' && row.event?.auto_received
                ? 'Automatically confirmed receipt'
                : timelineLabels[row.status];
            const rows = [];
            shipment.events.forEach((event) => {
                const row = {status: event.cancelled ? 'CANCELLED' : event.status, event};
                if (!timelineLabels[row.status]) return;

                const previous = rows[rows.length - 1];
                if (previous && previous.status === row.status && labelForRow(previous) === labelForRow(row)) return;
                rows.push(row);
            });
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
                label.textContent = labelForRow(row);

                item.append(date, dot, label);
                return item;
            });
            timeline.replaceChildren(...items);
            timeline.dataset.shipmentCurrentStatus = shipment.status;
            timeline.dataset.shipmentCancelled = String(shipment.status === 'CANCELLED');

            const guidance = document.querySelector(`[data-shipment-guidance-id="${shipment.id}"]`);
            if (guidance) {
                const guidanceByStatus = {
                    PLACED: ['Placed', 'Wait for the seller to prepare and confirm the parcel.', 'Seller'],
                    CONFIRMED: ['Confirmed', 'Wait for the seller to prepare the parcel.', 'Seller'],
                    PREPARING: ['Preparing', 'Wait for the seller to finish preparing the parcel.', 'Seller'],
                    READY_FOR_PICKUP: ['Ready for Pickup', 'Logistics will assign a pickup rider.', 'Logistics'],
                    PICKUP_ASSIGNED: ['Pickup Assigned', 'The pickup rider will collect the parcel from the seller.', 'Pickup rider'],
                    PICKED_UP: ['Picked Up', 'The sorting center will scan the parcel into its facility.', 'Logistics'],
                    AT_SORTING_CENTER: ['At Sorting Center', 'The parcel will be sorted toward your delivery area.', 'Logistics'],
                    SORTED: ['Sorted', 'A delivery rider will be assigned in your area.', 'Logistics'],
                    ASSIGNED_TO_RIDER: ['Assigned To Rider', 'The delivery rider will accept and start the route.', 'Delivery rider'],
                    OUT_FOR_DELIVERY: ['Out For Delivery', 'Keep your phone available while the rider attempts delivery.', 'Delivery rider'],
                    DELIVERY_FAILED: ['Delivery Failed', 'Check for a new attempt or contact the delivery team if needed.', 'Logistics / delivery rider'],
                    DELIVERED: ['Delivered', 'Wait for the remaining parcels; confirm receipt when the order is ready.', 'Buyer'],
                    COMPLETED: ['Completed', 'You can leave a review for the delivered products.', 'Buyer'],
                    RETURNED: ['Returned', 'Check Returns & Refunds for the seller review and refund update.', 'Seller / refund payer'],
                    CANCELLED: ['Cancelled', 'No further delivery action is required for this parcel.', 'No action pending'],
                };
                const [status, next, actor] = guidanceByStatus[shipment.status] || [headline(shipment.status), 'Follow the tracking timeline for the next scan.', 'Logistics'];
                const statusNode = guidance.querySelector('[data-guidance-status]');
                const autoReceived = shipment.status === 'COMPLETED' && shipment.events?.some((event) => event.auto_received);
                const cancelledOrderDisplay = statusNode?.textContent.trim() === 'Cancelled' && shipment.status !== 'CANCELLED';
                const displayStatus = cancelledOrderDisplay
                    ? 'Cancelled'
                    : (shipment.status === 'CANCELLED'
                        ? 'Cancelled'
                        : (shipment.status === 'RETURNED'
                            ? (guidance.dataset.shipmentReturnedLabel || 'Returned')
                            : (autoReceived ? 'Auto Received' : status)));
                statusNode?.replaceChildren(document.createTextNode(displayStatus));
                guidance.querySelector('[data-guidance-next]')?.replaceChildren(document.createTextNode(next));
                guidance.querySelector('[data-guidance-actor]')?.replaceChildren(document.createTextNode(actor));
            }
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
                    if (shipment.status === 'COMPLETED') return 'Buyer confirmed receipt';
                    if (shipment.status === 'DELIVERED') return 'Parcel has been delivered';
                    if (shipment.status === 'DELIVERY_FAILED' && shipment.return_workflow_open) {
                        return shipment.return_disposition === 'REDELIVERY'
                            ? 'Redelivery is being arranged'
                            : 'Return to seller is in progress';
                    }
                    if (shipment.status === 'DELIVERY_FAILED' && shipment.return_workflow_status === 'SELLER_RECEIVED') {
                        return 'Seller has received the returned parcel';
                    }
                    const inTransitLeg = (shipment.transfer_legs || []).find((leg) => leg.status === 'IN_TRANSIT');
                    const originName = inTransitLeg?.origin_center_name || shipment.current_center_name || 'the origin hub';
                    const destinationName = inTransitLeg?.destination_center_name || 'the next hub';
                    if (inTransitLeg?.arrival_scanned_at) return `Parcel has arrived at ${destinationName}. Hub receipt is being verified.`;
                    if (inTransitLeg) return `Parcel has departed from ${originName} and is on the way to ${destinationName}`;
                    const centerName = shipment.current_center_name || '';
                    const centerLabel = centerName
                        ? `${centerName}${/(sorting\s+(centre|center)|hub)/i.test(centerName) ? '' : ' sorting centre'}`
                        : 'the sorting centre';
                    if (['AT_SORTING_CENTER', 'SORTED'].includes(shipment.status)) return `Parcel has arrived at ${centerLabel}`;
                    if (['OUT_FOR_DELIVERY', 'ASSIGNED_TO_RIDER'].includes(shipment.status)) return 'Parcel is out for delivery';
                    if (shipment.status === 'PICKED_UP') return 'Parcel has been picked up from seller';
                    if (shipment.status === 'READY_FOR_PICKUP') return 'Ready to ship';
                    if (shipment.status === 'DELIVERY_FAILED') return 'Delivery attempt was unsuccessful';
                    if (shipment.status === 'PLACED') return 'Waiting for seller to accept your order';
                    return 'Seller is preparing your parcel';
                };
                (payload.orders || []).forEach((order) => {
                    const currentTabKey = (orderPage.dataset.orderTabKey || 'all').toUpperCase().replaceAll('-', '_');
                    if (['TO_RECEIVE', 'COMPLETED', 'RETURNS'].includes(currentTabKey)) {
                        const tabChangedOnPage = (order.shipments || []).some((shipment) => {
                            const row = orderPage.querySelector(`[data-shipment-tab-key-id="${shipment.id}"]`);
                            return row && row.dataset.shipmentTabKey !== shipment.tab_key;
                        });
                        if (tabChangedOnPage) {
                            window.location.reload();
                            return;
                        }
                    }

                    const confirmableShipmentIds = new Set((order.confirmable_shipment_ids || []).map(Number));
                    if (detailOrderId === Number(order.id) && order.actions?.confirmable_shipment_ids) {
                        order.actions.confirmable_shipment_ids.forEach((id) => confirmableShipmentIds.add(Number(id)));
                    }
                    orderPage.querySelectorAll('[data-confirm-shipment-action]').forEach((form) => {
                        if (!confirmableShipmentIds.has(Number(form.dataset.confirmShipmentAction))) form.hidden = true;
                        else form.hidden = false;
                    });

                    document.querySelectorAll(`[data-order-status-id="${order.id}"]`).forEach((element) => { element.textContent = headline(displayStatus(order)); });
                    (order.seller_orders || []).forEach((sellerOrder) => {
                        document.querySelectorAll(`[data-seller-order-status-id="${sellerOrder.id}"]`).forEach((element) => {
                            element.textContent = sellerOrder.display_status || element.textContent;
                        });
                    });
                    (order.shipments || []).forEach((shipment) => {
                        const buyerUpdate = trackingUpdate(shipment);
                        document.querySelectorAll(`[data-shipment-status-id="${shipment.id}"]`).forEach((element) => {
                            element.textContent = buyerUpdate;
                            const updateRow = element.closest('[data-shipping-update]');
                            if (updateRow) updateRow.hidden = !buyerUpdate;
                        });

                        renderShipmentTimeline(shipment);
                    });

                    if (detailOrderId === Number(order.id) && order.actions) {
                        const receivedAction = orderPage.querySelector('[data-order-received-action]');
                        const returnAction = orderPage.querySelector('[data-order-return-action]');
                        const detailActions = orderPage.querySelector('[data-order-detail-actions]');
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
                                : (order.actions.can_confirm_received ? 'Check the delivered items before confirming receipt.' : '');
                            detailMessage.hidden = detailMessage.textContent === '';
                        }
                        if (detailActions) {
                            detailActions.hidden = Boolean(detailMessage?.hidden)
                                && !order.actions.can_confirm_received
                                && !order.actions.can_request_return;
                        }
                        if (order.actions.can_confirm_received) {
                            orderPage.querySelectorAll('[data-shipment-guidance-id]').forEach((guidance) => {
                                const status = guidance.querySelector('[data-guidance-status]')?.textContent || '';
                                if (status.toLowerCase() === 'delivered') {
                                    const next = guidance.querySelector('[data-guidance-next]');
                                    if (next) next.textContent = 'Check the parcel, then confirm receipt for the order.';
                                }
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
