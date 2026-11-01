const normalize = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim();

const editDistance = (left, right) => {
    const a = left.slice(0, 32);
    const b = right.slice(0, 32);
    const previous = Array.from({ length: b.length + 1 }, (_, index) => index);

    for (let row = 1; row <= a.length; row += 1) {
        let diagonal = previous[0];
        previous[0] = row;
        for (let column = 1; column <= b.length; column += 1) {
            const above = previous[column];
            previous[column] = Math.min(
                previous[column] + 1,
                previous[column - 1] + 1,
                diagonal + (a[row - 1] === b[column - 1] ? 0 : 1),
            );
            diagonal = above;
        }
    }

    return previous[b.length];
};

const scoreMatch = (query, text) => {
    const normalizedQuery = normalize(query);
    const normalizedText = normalize(text);
    const terms = normalizedQuery.split(' ').filter((term) => term.length > 1);
    if (!terms.length || !normalizedText) return 0;
    if (normalizedText.includes(normalizedQuery)) return 1.3;

    const words = [...new Set(normalizedText.split(' '))];
    let score = 0;
    for (const term of terms) {
        let best = 0;
        for (const word of words) {
            if (word === term || word.includes(term)) {
                best = 1;
                break;
            }
            if (word.startsWith(term) || term.startsWith(word)) {
                best = Math.max(best, 0.84);
                continue;
            }
            const limit = term.length < 5 ? 1 : 2;
            if (Math.abs(term.length - word.length) <= limit && editDistance(term, word) <= limit) {
                best = Math.max(best, 0.72);
            }
        }
        if (!best) return 0;
        score += best;
    }

    return score / terms.length;
};

export function initializeWorkspaceHeaderSearch() {
    const search = document.querySelector('[data-workspace-search]');
    if (!search) return;

    const form = search.querySelector('[data-workspace-search-form]');
    const input = search.querySelector('[data-workspace-search-input]');
    const dropdown = search.querySelector('[data-workspace-search-results]');
    const heading = search.querySelector('[data-workspace-search-heading]');
    const options = search.querySelector('[data-workspace-search-options]');
    const emptyMessage = search.querySelector('[data-workspace-search-empty]');
    const clearHistory = search.querySelector('[data-workspace-search-clear]');
    if (!form || !input || !dropdown || !heading || !options || !emptyMessage || !clearHistory) return;

    const workspaceKey = search.dataset.searchWorkspace || 'logistics';
    const workspaceName = search.dataset.searchLabel || (workspaceKey === 'rider' ? 'Rider' : workspaceKey === 'seller' ? 'Seller' : 'Logistics');
    const historyKey = `likhae-${workspaceKey}-search-history`;
    const aliases = {
        dashboard: 'home overview metrics operations parcels shipments activity',
        products: 'catalog listings merchandise sku',
        inventory: 'stock variants quantities availability',
        orders: 'sales purchases fulfillment shipping',
        reviews: 'ratings feedback customer comments',
        marketing: 'campaigns promotions discounts vouchers',
        'analytics reports': 'analytics sales performance insights',
        'store settings': 'storefront shop profile branding',
        'my account': 'account profile owner personal details',
        'system settings': 'preferences configuration notifications security',
        'rider applications': 'pending onboarding registration drivers',
        'all riders': 'rider management drivers couriers',
        'delivery areas': 'service area coverage locations barangay',
        'all parcels': 'shipments tracking shipment orders',
        'delivery monitoring': 'live tracking last mile deliveries',
        'pickup requests': 'pickups intake collection',
        'incoming parcels': 'receive intake shipments',
        'parcel sorting': 'sort scan parcels',
        'hub transfers': 'inter hub manifests transfer',
        'rider assignment': 'assign deliveries drivers',
        'delivery trip overview': 'local trips drivers delivery',
        'return to seller': 'returns reverse logistics',
        reports: 'analytics operations performance',
        'wallet payouts': 'finance remittance COD cash settlement earnings payout payment balance',
        finance: 'remittance COD cash reconciliation payouts',
        'shipping rate': 'fees rate charges delivery',
        messages: 'messaging inbox conversations chat',
        notifications: 'alerts updates',
        profile: 'account owner security',
        'system settings': 'preferences configuration',
        'pickup assignments': 'seller pickups collections assigned batches',
        'delivery batches': 'deliveries parcels dropoff route assignments',
        'return parcels': 'returns reverse logistics seller',
        'delivery history': 'completed delivered parcel trips',
        'inter hub transfers': 'interhub manifests transfer dispatch',
        'cod remittances': 'cash on delivery remittance submit cash',
    };

    const safeHref = (value) => {
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
                return { query: String(entry?.query || ''), href: safeHref(entry?.href || '') };
            }).filter((entry) => entry.query && entry.href).slice(0, 6);
        } catch (_) {
            return [];
        }
    };

    const saveHistory = (query, href) => {
        const cleaned = String(query || '').trim();
        const destination = safeHref(href);
        if (!cleaned || !destination) return;
        const next = [{ query: cleaned, href: destination }, ...readHistory()
            .filter((entry) => normalize(entry.query) !== normalize(cleaned))].slice(0, 6);
        try { window.localStorage.setItem(historyKey, JSON.stringify(next)); } catch (_) {}
    };

    const pages = [...document.querySelectorAll(`[data-sl-sidebar][data-sidebar-workspace="${workspaceKey}"] .sl-sidebar-scroll a[href]`)]
        .map((link) => {
            const label = link.querySelector('.sl-nav-text')?.textContent.trim() || link.textContent.trim();
            const group = link.closest('.sl-nav-section')?.querySelector('.sl-nav-label')?.textContent.trim() || workspaceName;
            const href = safeHref(link.href);
            return { label, group, href, searchText: `${workspaceName} ${group} ${label} ${aliases[normalize(label)] || ''}` };
        })
        .filter((item) => item.label && item.href);

    const content = document.querySelector('[data-workspace-search-content]');
    const sections = (() => {
        if (!content) return [];
        const seen = new Set();
        return [...content.querySelectorAll('h1, h2, h3, h4')].map((title, index) => {
            const block = title.closest('article, section, [data-search-section]') || title.parentElement;
            if (!block || block.closest('[hidden]') || seen.has(block)) return null;
            seen.add(block);
            if (!block.id) block.id = `${workspaceKey}-search-section-${index + 1}`;
            return {
                label: title.textContent.replace(/\s+/g, ' ').trim(),
                text: block.textContent.replace(/\s+/g, ' ').trim(),
                href: `${window.location.pathname}${window.location.search}#${block.id}`,
            };
        }).filter((item) => item?.label && item.text);
    })();

    let activeOption = -1;
    let optionId = 0;

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
        link.id = `${workspaceKey}SearchOption-${++optionId}`;
        link.className = 'sl-search-option';
        link.setAttribute('role', 'option');
        link.setAttribute('aria-selected', 'false');
        link.dataset.searchQuery = item.query || item.label;
        link.dataset.searchHref = item.href;
        link.dataset.searchType = item.type || 'page';

        const copy = document.createElement('span');
        copy.className = 'sl-search-option__copy';
        const title = document.createElement('strong');
        title.textContent = item.label;
        copy.append(title);
        if (item.description) {
            const description = document.createElement('small');
            description.textContent = item.description;
            copy.append(description);
        }

        const location = document.createElement('span');
        location.className = 'sl-search-option__location';
        location.textContent = item.location || item.type || `${workspaceName} page`;
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
            section.className = 'sl-search-option-group';
            section.setAttribute('role', 'group');
            section.setAttribute('aria-label', group.label);
            const label = document.createElement('p');
            label.className = 'sl-search-option-group__title';
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
        const recent = readHistory().map((entry) => ({
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
        const pageMatches = pages
            .map((page) => ({ ...page, score: scoreMatch(query, page.searchText) }))
            .filter((page) => page.score >= 0.58)
            .sort((left, right) => right.score - left.score)
            .slice(0, 6);
        const sectionMatches = sections
            .map((section) => ({ ...section, score: scoreMatch(query, section.text) }))
            .filter((section) => section.score >= 0.58)
            .sort((left, right) => right.score - left.score)
            .slice(0, 3);

        const groups = [];
        if (pageMatches.length && pageMatches[0].score < 1.02 && normalize(query).split(' ').length === 1) {
            groups.push({
                label: 'Did you mean',
                items: [{
                    label: pageMatches[0].label,
                    href: pageMatches[0].href,
                    location: pageMatches[0].group,
                    description: `Closest ${workspaceName} page match for “${query}”`,
                    type: 'page',
                }],
            });
        }
        groups.push({
            label: `${workspaceName} pages`,
            items: pageMatches.map((page) => ({
                label: page.label,
                href: page.href,
                location: page.group,
                description: `Open this ${workspaceName} page`,
                type: 'page',
            })),
        });
        groups.push({
            label: 'On this page',
            items: sectionMatches.map((section) => ({
                label: section.label,
                href: section.href,
                location: 'Current page section',
                description: section.text.slice(0, 145),
                type: 'section',
            })),
        });

        const hasMatch = pageMatches.length > 0 || sectionMatches.length > 0;
        renderGroups(groups, 'Suggestions', hasMatch ? '' : `No matching ${workspaceName} page or section.`);
    };

    const setActiveOption = (index) => {
        const links = [...options.querySelectorAll('[role="option"]')];
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
            renderGroups([], 'Suggestions', `Type at least 2 characters to see ${workspaceName} page suggestions.`);
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
            const suggestions = [...options.querySelectorAll('[role="option"]')];
            const destination = activeOption >= 0
                ? suggestions[activeOption]
                : suggestions.find((link) => link.dataset.searchType !== 'history');
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
        const suggestions = [...options.querySelectorAll('[role="option"]')];
        const destination = activeOption >= 0
            ? suggestions[activeOption]
            : suggestions.find((link) => link.dataset.searchType !== 'history');
        destination?.click();
    });
    document.addEventListener('click', (event) => {
        if (!search.contains(event.target)) setOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            input.focus();
        }
    });
}
