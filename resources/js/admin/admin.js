import '../shared/echo.js';
import '../shared/feedback.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';
import '../shared/payout-destination.js';

document.addEventListener('DOMContentLoaded', () => {
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => [...root.querySelectorAll(selector)];
    all('[data-admin-password-toggle]').forEach((toggle) => {
        const form = toggle.closest('.ad-account-panel')?.querySelector('[data-admin-password-form]');
        const fields = form ? all('[data-admin-password-field]', form) : [];
        if (!fields.length) return;

        toggle.addEventListener('click', () => {
            const show = toggle.getAttribute('aria-pressed') !== 'true';
            fields.forEach((field) => { field.type = show ? 'text' : 'password'; });
            toggle.setAttribute('aria-pressed', String(show));
            toggle.textContent = show ? 'Hide passwords' : 'Show passwords';
        });
    });

    const realtimeCard = one('[data-realtime-transport-card]');
    if (realtimeCard) {
        const mode = one('[data-transport-mode]', realtimeCard);
        const socketValue = one('[data-transport-socket]', realtimeCard);
        const serverValue = one('[data-transport-server]', realtimeCard);
        const checkedValue = one('[data-transport-checked]', realtimeCard);
        const detail = one('[data-transport-detail]', realtimeCard);
        let serverConfigured = realtimeCard.dataset.serverConfigured === 'true';
        let broadcastUnavailable = realtimeCard.dataset.broadcastUnavailable === 'true';
        let serverKnown = true;
        let refreshing = false;

        const labelForSocketState = (state) => ({
            connected: 'Connected',
            connecting: 'Connecting',
            initialized: 'Starting',
            disconnected: 'Disconnected',
            unavailable: 'Unavailable',
            failed: 'Failed',
        }[state] || 'Not connected');
        const connection = window.Echo?.connector?.pusher?.connection;
        let socketState = connection?.state || 'unavailable';

        const update = () => {
            const live = serverKnown && serverConfigured && !broadcastUnavailable && socketState === 'connected';
            const fallbackActive = serverKnown && broadcastUnavailable;
            const driver = realtimeCard.dataset.broadcastConnection || 'reverb';

            mode.textContent = live ? 'Reverb live' : 'Polling fallback';
            mode.classList.remove('is-clear', 'is-pending', 'is-failed', 'is-unknown', 'is-not-configured');
            mode.classList.add(live ? 'is-clear' : 'is-pending');
            socketValue.textContent = labelForSocketState(socketState);
            serverValue.textContent = !serverConfigured
                ? `Polling via ${driver.toUpperCase()}`
                : (fallbackActive ? 'Reverb fallback active' : (serverKnown ? 'Reverb configured' : 'Status unavailable'));

            if (!serverConfigured) {
                detail.textContent = `The server broadcaster is ${driver.toUpperCase()}; message updates use polling.`;
            } else if (fallbackActive) {
                detail.textContent = 'A recent server broadcast failed. Message updates are using polling while Reverb recovers.';
            } else if (!serverKnown) {
                detail.textContent = 'The server broadcast status could not be refreshed, so polling is shown as the safe update mode.';
            } else if (live) {
                detail.textContent = 'Reverb is connected. Message updates switch to polling automatically if the socket drops.';
            } else {
                detail.textContent = `Reverb is ${labelForSocketState(socketState).toLowerCase()}; message updates are using polling while it reconnects.`;
            }
        };

        connection?.bind('state_change', (states) => {
            socketState = states.current;
            update();
        });
        update();

        const refreshServerStatus = async () => {
            if (refreshing) return;
            refreshing = true;
            try {
                const response = await fetch(realtimeCard.dataset.statusUrl, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!response.ok) throw new Error('Status request failed');
                const status = await response.json();
                serverConfigured = status.configured === true;
                broadcastUnavailable = status.broadcast_unavailable === true;
                serverKnown = true;
                realtimeCard.dataset.broadcastConnection = status.broadcast_connection || 'not configured';
                if (status.checked_at) {
                    const checkedAt = new Date(status.checked_at);
                    checkedValue.textContent = Number.isNaN(checkedAt.getTime()) ? 'Not available' : checkedAt.toLocaleString();
                }
            } catch (_) {
                serverKnown = false;
            } finally {
                refreshing = false;
                update();
            }
        };

        window.setInterval(refreshServerStatus, 15000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') refreshServerStatus();
        });
    }
    // The shared Admin sidebar component owns navigation and collapse state.
    // Keeping a second controller here can offset the shell without resizing the sidebar.
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            one('.ad-search input')?.focus();
        }
    });

    const adminSearch = one('[data-admin-search]');
    if (adminSearch) {
        const form = one('[data-admin-search-form]', adminSearch);
        const input = one('[data-admin-search-input]', adminSearch);
        const dropdown = one('[data-admin-search-results]', adminSearch);
        const heading = one('[data-admin-search-heading]', adminSearch);
        const options = one('[data-admin-search-options]', adminSearch);
        const emptyMessage = one('[data-admin-search-empty]', adminSearch);
        const clearHistory = one('[data-admin-search-clear]', adminSearch);
        const historyKey = 'likhae-admin-search-history';
        let activeOption = -1;
        let optionId = 0;

        const normalizeSearchText = (value) => String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();

        const aliases = {
            'dashboard': 'home overview metrics marketplace health activity',
            'user accounts': 'users buyers sellers admins logistics profiles accounts status pending active suspended banned email id',
            'products': 'catalog listings product moderation ai safety inventory approval images',
            'platform marketing': 'campaign campaigns announcement announcements voucher vouchers discount promotion',
            'categories': 'category subcategory taxonomy product group',
            'seller compliance': 'seller cases warnings violations policy review product compliance',
            'refunds disputes': 'refunds refund buyer complaints disputes claims resolution return',
            'finance payouts': 'finance seller logistics payouts payout commissions COD cash settlements receipts transfers',
            'shipping rate requests': 'shipping delivery rate fee logistics rider commission tiers regional proposal requests',
            'additional care rules': 'fragile protection care fee product category rule checkout',
            'reports': 'analytics sales orders commissions products sellers performance export',
            'messages': 'messaging inbox conversations chat support users',
            'notifications': 'alerts updates registration risk finance message',
            'platform settings': 'configuration registration policy terms privacy platform fee',
            'system settings': 'health infrastructure database runtime storage capacity errors logs audit preferences moderation ai',
            'my account': 'profile password security payout destination payment details',
        };

        const damerauDistance = (left, right) => {
            const a = left.slice(0, 48);
            const b = right.slice(0, 48);
            const rows = Array.from({ length: a.length + 1 }, () => Array(b.length + 1).fill(0));
            for (let i = 0; i <= a.length; i += 1) rows[i][0] = i;
            for (let j = 0; j <= b.length; j += 1) rows[0][j] = j;
            for (let i = 1; i <= a.length; i += 1) {
                for (let j = 1; j <= b.length; j += 1) {
                    const cost = a[i - 1] === b[j - 1] ? 0 : 1;
                    rows[i][j] = Math.min(
                        rows[i - 1][j] + 1,
                        rows[i][j - 1] + 1,
                        rows[i - 1][j - 1] + cost,
                    );
                    if (i > 1 && j > 1 && a[i - 1] === b[j - 2] && a[i - 2] === b[j - 1]) {
                        rows[i][j] = Math.min(rows[i][j], rows[i - 2][j - 2] + 1);
                    }
                }
            }
            return rows[a.length][b.length];
        };

        const matchScore = (query, searchableText) => {
            const normalizedQuery = normalizeSearchText(query);
            const queryWords = normalizedQuery.split(' ').filter((word) => word.length > 1);
            const normalizedText = normalizeSearchText(searchableText);
            if (!queryWords.length || !normalizedText) return 0;
            if (normalizedText.includes(normalizedQuery)) return 1.25;

            const words = [...new Set(normalizedText.split(' '))];
            const scores = [];
            for (const queryWord of queryWords) {
                let best = 0;
                for (const word of words) {
                    if (word === queryWord || word.includes(queryWord)) {
                        best = 1;
                        break;
                    }
                    if (word.startsWith(queryWord) || queryWord.startsWith(word)) {
                        best = Math.max(best, 0.88);
                        continue;
                    }
                    const limit = queryWord.length < 5 ? 1 : queryWord.length < 9 ? 2 : 3;
                    if (Math.abs(queryWord.length - word.length) > limit) continue;
                    const distance = damerauDistance(queryWord, word);
                    if (distance <= limit) {
                        best = Math.max(best, 1 - distance / Math.max(queryWord.length, word.length));
                    }
                }
                if (best < 0.58) return 0;
                scores.push(best);
            }
            return scores.reduce((sum, score) => sum + score, 0) / scores.length;
        };

        const safeLocalHref = (value) => {
            try {
                const url = new URL(value, window.location.origin);
                if (url.origin !== window.location.origin) return '';
                return `${url.pathname}${url.search}${url.hash}`;
            } catch (_) {
                return '';
            }
        };

        const readHistory = () => {
            try {
                const stored = JSON.parse(window.localStorage.getItem(historyKey) || '[]');
                if (!Array.isArray(stored)) return [];
                return stored.map((entry) => {
                    if (typeof entry === 'string') return { query: entry, href: '' };
                    return { query: String(entry?.query || ''), href: safeLocalHref(entry?.href || '') };
                }).filter((entry) => entry.query).slice(0, 6);
            } catch (_) {
                return [];
            }
        };

        const saveHistory = (query, href) => {
            const cleaned = String(query || '').trim();
            const safeHref = safeLocalHref(href);
            if (!cleaned || !safeHref) return;
            const next = [{ query: cleaned, href: safeHref }, ...readHistory()
                .filter((entry) => normalizeSearchText(entry.query) !== normalizeSearchText(cleaned))]
                .slice(0, 6);
            try { window.localStorage.setItem(historyKey, JSON.stringify(next)); } catch (_) {}
        };

        const pageLinks = all('.lk-admin-navigation .ad-sidebar-scroll a[href]')
            .map((link) => {
                const label = one('.ad-nav-text', link)?.textContent.trim() || link.textContent.trim();
                const group = one('.ad-nav-group .ad-nav-heading', link.closest('.ad-nav-group') || document)?.textContent.trim() || 'Admin Center';
                const url = safeLocalHref(link.href);
                const extraTerms = aliases[normalizeSearchText(label)] || '';
                return { label, group, href: url, searchText: `${group} ${label} ${extraTerms}` };
            })
            .filter((item) => item.label && item.href);

        const pageSections = (() => {
            const main = one('#main-content');
            if (!main) return [];
            const seen = new Set();
            return all('h1, h2, h3, h4', main).map((title, index) => {
                const block = title.closest('.ad-card, article, section') || title.parentElement;
                if (!block || seen.has(block)) return null;
                seen.add(block);
                if (!block.id) block.id = `admin-search-location-${index + 1}`;
                const text = block.textContent.replace(/\s+/g, ' ').trim();
                return {
                    label: title.textContent.replace(/\s+/g, ' ').trim(),
                    text,
                    href: `${window.location.pathname}${window.location.search}#${block.id}`,
                };
            }).filter((item) => item?.label && item.text);
        })();

        const setOpen = (open) => {
            dropdown.hidden = !open;
            input.setAttribute('aria-expanded', String(open));
            if (!open) {
                input.removeAttribute('aria-activedescendant');
                activeOption = -1;
            }
        };

        const makeOption = (item) => {
            const link = document.createElement('a');
            link.href = item.href;
            link.id = `adminSearchOption-${++optionId}`;
            link.className = 'ad-search-option';
            link.setAttribute('role', 'option');
            link.setAttribute('aria-selected', 'false');
            link.dataset.searchQuery = item.query || item.label;
            link.dataset.searchHref = item.href;
            link.dataset.searchType = item.type || 'page';

            const copy = document.createElement('span');
            copy.className = 'ad-search-option__copy';
            const title = document.createElement('strong');
            title.textContent = item.label;
            copy.append(title);
            if (item.description) {
                const description = document.createElement('small');
                description.textContent = item.description;
                copy.append(description);
            }

            const location = document.createElement('span');
            location.className = 'ad-search-option__location';
            location.textContent = item.location || item.type || 'Admin page';
            link.append(copy, location);
            return link;
        };

        const renderGroups = (groups, title, emptyText = '') => {
            heading.textContent = title;
            options.replaceChildren();
            let count = 0;
            groups.forEach((group) => {
                if (!group.items.length) return;
                const section = document.createElement('div');
                section.className = 'ad-search-option-group';
                section.setAttribute('role', 'group');
                section.setAttribute('aria-label', group.label);
                const label = document.createElement('p');
                label.className = 'ad-search-option-group__title';
                label.textContent = group.label;
                section.append(label);
                group.items.forEach((item) => section.append(makeOption(item)));
                count += group.items.length;
                options.append(section);
            });
            emptyMessage.hidden = !emptyText;
            emptyMessage.textContent = emptyText;
            clearHistory.hidden = title !== 'Recent searches' || count === 0;
            setOpen(count > 0 || Boolean(emptyText));
        };

        const showRecentSearches = () => {
            const recent = readHistory().filter((entry) => entry.href).map((entry) => ({
                label: entry.query,
                query: entry.query,
                href: entry.href,
                location: 'Recent search',
                type: 'history',
            }));
            if (!recent.length) {
                setOpen(false);
                return;
            }
            renderGroups([{ label: 'Recent searches', items: recent }], 'Recent searches');
        };

        const renderSuggestions = (query) => {
            const pageMatches = pageLinks
                .map((page) => ({ ...page, score: matchScore(query, page.searchText) }))
                .filter((page) => page.score >= 0.58)
                .sort((left, right) => right.score - left.score)
                .slice(0, 5);
            const sectionMatches = pageSections
                .map((section) => ({ ...section, score: matchScore(query, section.text) }))
                .filter((section) => section.score >= 0.58)
                .sort((left, right) => right.score - left.score)
                .slice(0, 3);

            const groups = [];
            if (pageMatches.length && pageMatches[0].score < 1.02 && normalizeSearchText(query).split(' ').length === 1) {
                groups.push({
                    label: 'Did you mean',
                    items: [{
                        label: pageMatches[0].label,
                        href: pageMatches[0].href,
                        location: pageMatches[0].group,
                        description: `Closest Admin page match for “${query}”`,
                        type: 'page',
                    }],
                });
            }
            groups.push({
                label: 'Admin pages',
                items: pageMatches.map((page) => ({
                    label: page.label,
                    href: page.href,
                    location: page.group,
                    description: 'Open this Admin page',
                    type: 'page',
                })),
            });
            groups.push({
                label: `On ${one('[data-admin-header] h1')?.textContent.trim() || 'this page'}`,
                items: sectionMatches.map((section) => ({
                    label: section.label,
                    href: section.href,
                    location: 'Current page section',
                    description: section.text.slice(0, 150),
                    type: 'section',
                })),
            });

            const hasMatch = pageMatches.length > 0 || sectionMatches.length > 0;
            const userUrl = new URL(form.dataset.userSearchUrl, window.location.origin);
            userUrl.searchParams.set('q', query);
            groups.push({
                label: 'User accounts',
                items: [{
                    label: `Search users for “${query}”`,
                    href: `${userUrl.pathname}${userUrl.search}`,
                    location: 'Name, email, or account ID',
                    description: 'Open User Directory results',
                    type: 'user',
                }],
            });

            renderGroups(groups, 'Suggestions', hasMatch ? '' : 'No matching Admin page or section. Choose User accounts below only if you want to search users.');
        };

        const setActiveOption = (index) => {
            const links = all('[role="option"]', options);
            if (!links.length) return;
            activeOption = (index + links.length) % links.length;
            links.forEach((link, linkIndex) => {
                const active = linkIndex === activeOption;
                link.classList.toggle('is-active', active);
                link.setAttribute('aria-selected', String(active));
            });
            input.setAttribute('aria-activedescendant', links[activeOption].id);
        };

        input.addEventListener('focus', () => {
            const query = input.value.trim();
            if (query.length >= 2) renderSuggestions(query);
            else if (!query) showRecentSearches();
        });
        input.addEventListener('input', () => {
            activeOption = -1;
            input.removeAttribute('aria-activedescendant');
            const query = input.value.trim();
            if (!query) {
                showRecentSearches();
                return;
            }
            if (query.length < 2) {
                renderGroups([], 'Suggestions', 'Type at least 2 characters to see Admin page suggestions.');
                return;
            }
            renderSuggestions(query);
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' && !dropdown.hidden) {
                event.preventDefault();
                setActiveOption(activeOption + 1);
            } else if (event.key === 'ArrowUp' && !dropdown.hidden) {
                event.preventDefault();
                setActiveOption(activeOption < 0 ? 0 : activeOption - 1);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                const query = input.value.trim();
                if (!query) {
                    showRecentSearches();
                    return;
                }
                if (dropdown.hidden) renderSuggestions(query);
                const suggestions = all('[role="option"]', options);
                const destination = activeOption >= 0
                    ? suggestions[activeOption]
                    : suggestions.find((link) => link.dataset.searchType !== 'user');
                destination?.click();
            } else if (event.key === 'Escape') {
                setOpen(false);
            }
        });
        options.addEventListener('click', (event) => {
            const link = event.target.closest('[data-search-href]');
            if (link) saveHistory(link.dataset.searchQuery, link.dataset.searchHref);
        });
        clearHistory.addEventListener('click', () => {
            try { window.localStorage.removeItem(historyKey); } catch (_) {}
            setOpen(false);
        });
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const query = input.value.trim();
            if (!query) {
                showRecentSearches();
                return;
            }

            if (dropdown.hidden) renderSuggestions(query);
            const suggestions = all('[role="option"]', options);
            const destination = activeOption >= 0
                ? suggestions[activeOption]
                : suggestions.find((link) => link.dataset.searchType !== 'user');
            destination?.click();
        });
        document.addEventListener('click', (event) => {
            if (!adminSearch.contains(event.target)) setOpen(false);
        });
    }

    const toastRegion = one('[data-toast-region]');
    const showToast = (message) => {
        if (window.lkFeedback) return window.lkFeedback.show(message);
        if (!toastRegion || !message) return;
        const toast = document.createElement('div');
        toast.className = 'ad-toast';
        toast.textContent = message;
        toastRegion.append(toast);
        window.setTimeout(() => toast.remove(), 3200);
    };

    const sellerPayoutFilterForm = one('[data-seller-payout-filter-form]');
    const sellerPayoutResults = one('[data-seller-payout-results]');
    if (sellerPayoutFilterForm && sellerPayoutResults) {
        let activePayoutRefresh;
        const applyFilterValuesToForm = (url) => {
            sellerPayoutFilterForm.elements.seller_profile_id.value = url.searchParams.get('seller_profile_id') || '';
            sellerPayoutFilterForm.elements.requested_from.value = url.searchParams.get('requested_from') || '';
            sellerPayoutFilterForm.elements.requested_to.value = url.searchParams.get('requested_to') || '';
        };
        const filterUrl = () => {
            const url = new URL(sellerPayoutFilterForm.action, window.location.href);
            url.search = '';
            new FormData(sellerPayoutFilterForm).forEach((value, key) => {
                if (typeof value === 'string' && value.trim()) url.searchParams.set(key, value.trim());
            });
            url.hash = 'finance-sellers';
            return url;
        };
        const refreshPayoutResults = async (url, updateHistory = true) => {
            activePayoutRefresh?.abort();
            const controller = new AbortController();
            activePayoutRefresh = controller;
            sellerPayoutResults.setAttribute('aria-busy', 'true');

            try {
                const response = await fetch(url, {
                    headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Payout list request failed');

                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const refreshedResults = page.querySelector('[data-seller-payout-results]');
                if (!refreshedResults) throw new Error('Payout list was not returned');

                sellerPayoutResults.innerHTML = refreshedResults.innerHTML;
                if (updateHistory) {
                    window.history.pushState({}, '', `${url.pathname}${url.search}#finance-sellers`);
                }
            } catch (error) {
                if (error.name !== 'AbortError') showToast('Could not refresh seller payouts. Please try again.');
            } finally {
                if (activePayoutRefresh === controller) {
                    activePayoutRefresh = null;
                    sellerPayoutResults.setAttribute('aria-busy', 'false');
                }
            }
        };

        sellerPayoutFilterForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const requestedFrom = sellerPayoutFilterForm.elements.requested_from.value;
            const requestedTo = sellerPayoutFilterForm.elements.requested_to.value;
            if (requestedFrom && requestedTo && requestedTo < requestedFrom) {
                showToast('The end date must be on or after the start date.');
                return;
            }
            refreshPayoutResults(filterUrl());
        });

        one('[data-clear-seller-payout-filters]', sellerPayoutFilterForm)?.addEventListener('click', (event) => {
            event.preventDefault();
            sellerPayoutFilterForm.reset();
            refreshPayoutResults(filterUrl());
        });

        sellerPayoutResults.addEventListener('click', (event) => {
            const link = event.target.closest('nav[role="navigation"] a[href]');
            if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) return;
            event.preventDefault();
            refreshPayoutResults(url);
        });

        window.addEventListener('popstate', () => {
            const url = new URL(window.location.href);
            applyFilterValuesToForm(url);
            refreshPayoutResults(url, false);
        });
    }

    all('[data-filter-input]').forEach((input) => {
        const target = input.dataset.filterInput ? one(input.dataset.filterInput) : input.closest('[data-filter-scope]');
        const filter = () => {
            const term = input.value.trim().toLowerCase();
            const items = all('[data-filter-item]', target || document);
            let shown = 0;
            items.forEach((item) => {
                const matches = (item.dataset.search || item.textContent).toLowerCase().includes(term);
                item.hidden = !matches;
                if (matches) shown += 1;
            });
            const count = one('[data-visible-count]', target || document);
            if (count) count.textContent = String(shown);
        };
        input.addEventListener('input', filter);
        if (input.value) filter();
    });

    all('[data-select-all]').forEach((control) => {
        control.addEventListener('change', () => {
            const table = control.closest('table');
            all('tbody input[type="checkbox"]', table || document)
                .filter((checkbox) => !checkbox.disabled)
                .forEach((checkbox) => { checkbox.checked = control.checked; });
            control.dispatchEvent(new Event('selectionchange', { bubbles: true }));
        });
    });

    all('[data-session-revocation-form]').forEach((form) => {
        const selectAll = one('[data-select-all]', form);
        const userCheckboxes = all('[data-session-user-checkbox]', form);
        const selectedCount = one('[data-session-selected-count]', form);
        const bulkSubmit = one('[data-session-bulk-submit]', form);

        const updateSelection = () => {
            const selectable = userCheckboxes.filter((checkbox) => !checkbox.disabled);
            const selected = selectable.filter((checkbox) => checkbox.checked);
            if (selectedCount) selectedCount.textContent = String(selected.length);
            if (bulkSubmit) bulkSubmit.disabled = selected.length === 0 || bulkSubmit.dataset.unavailable === 'true';
            if (selectAll) {
                selectAll.checked = selectable.length > 0 && selected.length === selectable.length;
                selectAll.indeterminate = selected.length > 0 && selected.length < selectable.length;
            }
        };

        form.addEventListener('change', updateSelection);
        selectAll?.addEventListener('selectionchange', updateSelection);
        updateSelection();

        form.addEventListener('submit', (event) => {
            const isSingle = event.submitter?.matches('[data-session-single-submit]') === true;
            const confirmed = window.confirm(isSingle
                ? 'Suspend this account’s active sessions and sign them out? They will need to sign in again.'
                : 'Suspend the selected accounts’ active sessions and sign them out? They will need to sign in again.');
            if (!confirmed) event.preventDefault();
        });
    });

    all('[data-conversation]').forEach((conversation) => {
        conversation.addEventListener('click', () => {
            all('[data-conversation]').forEach((item) => item.classList.remove('is-active'));
            conversation.classList.add('is-active');
            const name = conversation.dataset.name || 'Marketplace user';
            const subject = conversation.dataset.subject || 'Support conversation';
            const title = one('[data-chat-name]');
            const detail = one('[data-chat-subject]');
            if (title) title.textContent = name;
            if (detail) detail.textContent = subject;
            showToast(`Opened conversation with ${name}.`);
        });
    });

    all('[data-chat-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const input = one('input', form);
            const body = one('[data-chat-body]');
            const message = input?.value.trim();
            if (!message || !body) return;
            const bubble = document.createElement('div');
            bubble.className = 'ad-bubble is-admin';
            bubble.textContent = message;
            const time = document.createElement('time');
            time.textContent = 'Just now';
            bubble.append(time);
            body.append(bubble);
            input.value = '';
            body.scrollTop = body.scrollHeight;
        });
    });
});
