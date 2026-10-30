import '../address/forms.js';
import '../shared/feedback.js';
import '../shared/echo.js';
import '../shared/messages.js';
import '../shared/notification-sounds.js';
import '../shared/payout-destination.js';

document.addEventListener('DOMContentLoaded', () => {
    const select = (selector, root = document) => root.querySelector(selector);
    const selectAll = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const sidebar = select('[data-sl-sidebar]');
    const overlay = select('[data-sl-overlay]');
    const mobileButton = select('[data-sl-mobile-menu]');
    const collapseButton = select('[data-sl-sidebar-toggle]');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const dashboardAutoCollapse = document.body.classList.contains('sl-dashboard-sidebar-hover');
    const storageKey = 'likhae-seller-sidebar-collapsed';
    let toastTimer;

    const readStorage = (key) => {
        try { return window.localStorage.getItem(key); } catch (error) { return null; }
    };

    const writeStorage = (key, value) => {
        try { window.localStorage.setItem(key, value); } catch (error) { /* State remains session-only. */ }
    };

    const showToast = (message) => {
        if (window.lkFeedback) return window.lkFeedback.show(message);
        const toast = select('[data-sl-toast]');
        if (!toast || !message) return;
        window.clearTimeout(toastTimer);
        toast.textContent = message;
        toast.classList.add('is-visible');
        toastTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 2600);
    };
    window.slShowToast = showToast;

    const messagePreviewToggle = select('[data-message-preview-toggle]');
    const messagePreviewPopover = select('[data-message-header-popover]');
    const messagePreviewList = select('[data-message-preview-list]', messagePreviewPopover || document);
    const messagePreviewSummary = select('[data-message-preview-summary]', messagePreviewPopover || document);
    const messagePreviewBadges = selectAll('[data-seller-message-count]');
    let messagePreviewRequest = null;
    let messagePreviewRefreshTimer = null;
    let messagePreviewRefreshQueued = false;

    const setMessageUnreadCount = (count) => {
        const unreadCount = Math.max(0, Number.parseInt(count, 10) || 0);
        const unreadLabel = unreadCount === 1 ? '1 unread message' : `${unreadCount} unread messages`;
        messagePreviewBadges.forEach((badge) => {
            badge.textContent = String(unreadCount > 99 ? '99+' : unreadCount);
            badge.style.display = unreadCount > 0 ? '' : 'none';
            badge.setAttribute('aria-label', unreadLabel);
        });
        messagePreviewToggle?.setAttribute('aria-label', unreadCount > 0 ? `Messages, ${unreadLabel}` : 'Messages');
        if (messagePreviewSummary) {
            messagePreviewSummary.textContent = unreadCount > 0
                ? `${unreadCount} unread`
                : 'You are all caught up';
        }
    };

    const renderMessagePreview = (payload) => {
        if (!messagePreviewList || !messagePreviewPopover) return;
        const items = Array.isArray(payload?.items) ? payload.items.slice(0, 6) : [];
        const urlTemplate = messagePreviewPopover.dataset.messageUrlTemplate || '';
        const rows = items.map((item) => {
            const conversationId = String(item?.id || '');
            if (!/^\d+$/.test(conversationId)) return null;

            const row = document.createElement('a');
            row.className = 'sl-message-preview';
            row.classList.toggle('is-unread', Number(item.unread_count) > 0);
            row.dataset.messagePreviewItem = '';
            row.dataset.conversationId = conversationId;
            row.href = urlTemplate.replace('__conversation__', encodeURIComponent(conversationId));

            const avatar = document.createElement('span');
            avatar.className = 'sl-message-preview__avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = String(item.initial || item.name || '?').slice(0, 1);

            const copy = document.createElement('span');
            copy.className = 'sl-message-preview__copy';
            const name = document.createElement('strong');
            name.dataset.messagePreviewName = '';
            name.textContent = String(item.name || 'Conversation');
            const preview = document.createElement('span');
            preview.dataset.messagePreviewText = '';
            preview.textContent = String(item.preview || 'Start a conversation.');
            const time = document.createElement('small');
            time.dataset.messagePreviewTime = '';
            time.textContent = String(item.time || 'Just now');
            copy.append(name, preview, time);

            const unread = document.createElement('span');
            unread.className = 'sl-message-preview__unread';
            unread.dataset.messagePreviewUnread = '';
            const unreadCount = Math.max(0, Number.parseInt(item.unread_count, 10) || 0);
            unread.textContent = String(unreadCount);
            unread.style.display = unreadCount > 0 ? '' : 'none';
            row.append(avatar, copy, unread);
            return row;
        }).filter(Boolean);

        if (rows.length) {
            messagePreviewList.replaceChildren(...rows);
        } else {
            const empty = document.createElement('div');
            empty.className = 'sl-message-popover__empty';
            const title = document.createElement('strong');
            title.textContent = 'No messages yet';
            const copy = document.createElement('span');
            copy.textContent = 'New conversations will show up here.';
            empty.append(title, copy);
            messagePreviewList.replaceChildren(empty);
        }

        setMessageUnreadCount(payload?.unread_count);
    };

    const refreshMessagePreview = async () => {
        if (!messagePreviewPopover?.dataset.previewUrl) return null;
        if (messagePreviewRequest) {
            messagePreviewRefreshQueued = true;
            return messagePreviewRequest;
        }
        messagePreviewRequest = fetch(messagePreviewPopover.dataset.previewUrl, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        }).then((response) => {
            if (!response.ok) throw new Error('Message preview is unavailable.');
            return response.json();
        }).then(renderMessagePreview).catch(() => {
            if (messagePreviewSummary && !messagePreviewList?.querySelector('[data-message-preview-item]')) {
                messagePreviewSummary.textContent = 'Unable to load messages';
            }
        }).finally(() => {
            messagePreviewRequest = null;
            if (messagePreviewRefreshQueued) {
                messagePreviewRefreshQueued = false;
                window.setTimeout(refreshMessagePreview, 0);
            }
        });
        return messagePreviewRequest;
    };

    const closeMessagePreview = () => {
        if (!messagePreviewPopover || messagePreviewPopover.hidden) return;
        messagePreviewPopover.hidden = true;
        messagePreviewToggle?.setAttribute('aria-expanded', 'false');
    };

    messagePreviewToggle?.addEventListener('click', () => {
        if (!messagePreviewPopover) return;
        const opening = messagePreviewPopover.hidden;
        messagePreviewPopover.hidden = !opening;
        messagePreviewToggle.setAttribute('aria-expanded', String(opening));
        if (opening) refreshMessagePreview();
    });

    document.addEventListener('click', (event) => {
        if (!messagePreviewPopover || messagePreviewPopover.hidden
            || messagePreviewPopover.contains(event.target)
            || event.target.closest('[data-message-preview-toggle]')) return;
        closeMessagePreview();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && messagePreviewPopover && !messagePreviewPopover.hidden) {
            closeMessagePreview();
            messagePreviewToggle?.focus();
        }
    });

    document.addEventListener('likhae:seller-message-received', () => {
        window.clearTimeout(messagePreviewRefreshTimer);
        messagePreviewRefreshTimer = window.setTimeout(refreshMessagePreview, 250);
    });

    const passwordChangeToggle = select('[data-password-change-toggle]');
    const passwordChangePanel = select('[data-password-change-panel]');
    const passwordChangeForm = select('[data-password-change-form]');
    const passwordConfirmation = select('[data-password-confirmation]');
    const passwordMatchMessage = select('[data-password-match-message]');
    const newPassword = select('#sellerNewPassword');

    const updatePasswordMatch = () => {
        if (!passwordConfirmation || !passwordMatchMessage || !newPassword) return;
        const hasConfirmation = passwordConfirmation.value.length > 0;
        const matches = hasConfirmation && passwordConfirmation.value === newPassword.value;
        passwordConfirmation.setCustomValidity(hasConfirmation && !matches ? 'Passwords do not match.' : '');
        passwordConfirmation.setAttribute('aria-invalid', String(hasConfirmation && !matches));
        passwordMatchMessage.classList.toggle('is-match', matches);
        passwordMatchMessage.classList.toggle('is-mismatch', hasConfirmation && !matches);
        passwordMatchMessage.textContent = !hasConfirmation
            ? ''
            : matches
                ? 'Passwords match.'
                : 'Passwords do not match.';
    };

    passwordChangeToggle?.addEventListener('click', () => {
        if (!passwordChangePanel) return;
        const open = passwordChangePanel.hidden;
        passwordChangePanel.hidden = !open;
        passwordChangeToggle.setAttribute('aria-expanded', String(open));
        passwordChangeToggle.textContent = open ? 'Close form' : 'Change';
        if (open) select('[name="current_password"]', passwordChangePanel)?.focus();
        else {
            passwordChangeForm?.reset();
            updatePasswordMatch();
        }
    });

    select('[data-password-change-cancel]')?.addEventListener('click', () => {
        if (!passwordChangePanel) return;
        passwordChangePanel.hidden = true;
        passwordChangeToggle?.setAttribute('aria-expanded', 'false');
        if (passwordChangeToggle) passwordChangeToggle.textContent = 'Change';
        passwordChangeForm?.reset();
        updatePasswordMatch();
    });

    passwordChangeForm?.addEventListener('input', (event) => {
        if (event.target.matches('[data-password-confirmation], #sellerNewPassword')) updatePasswordMatch();
    });
    passwordChangeForm?.addEventListener('submit', updatePasswordMatch);

    selectAll('[data-password-peek]').forEach((button) => {
        const input = document.getElementById(button.dataset.passwordPeek);
        if (!input) return;
        const label = (button.getAttribute('aria-label') || 'password').replace(/^show\s+/i, '');
        button.addEventListener('click', () => {
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} ${label}`);
        });
    });

    const notificationBadges = selectAll('[data-seller-notification-count]');
    const sellerUserId = window.LIKHAE_NOTIFICATION_SOUND_CONFIG?.userId;
    if (notificationBadges.length && sellerUserId && window.Echo
        && document.body.dataset.sellerNotificationsSubscribed !== 'true') {
        document.body.dataset.sellerNotificationsSubscribed = 'true';
        const seenNotificationIds = new Set();
        window.Echo.private(`App.Models.User.${sellerUserId}`)
            .listen('.notification.created', (notification) => {
                const notificationId = String(notification?.id || '');
                if (!notificationId || seenNotificationIds.has(notificationId)) return;
                seenNotificationIds.add(notificationId);

                const currentCount = Math.max(0, ...notificationBadges.map((badge) => Number.parseInt(badge.textContent || '0', 10) || 0));
                const nextCount = currentCount + 1;
                notificationBadges.forEach((badge) => {
                    badge.textContent = String(nextCount);
                    badge.style.display = '';
                });

                const notificationList = select('[data-seller-notification-list]');
                const openTemplate = notificationList?.dataset.sellerNotificationOpenTemplate;
                const currentPage = new URL(window.location.href).searchParams.get('page');
                if (notificationList && openTemplate && (!currentPage || currentPage === '1')
                    && /^\d+$/.test(notificationId)
                    && !notificationList.querySelector(`[data-seller-notification-event-id="${notificationId}"]`)) {
                    const emptyState = notificationList.querySelector(':scope > div');
                    if (emptyState && !notificationList.querySelector('a')) emptyState.remove();

                    const row = document.createElement('a');
                    row.href = openTemplate.replace('__id__', encodeURIComponent(notificationId));
                    row.dataset.sellerNotificationEventId = notificationId;
                    row.style.cssText = notificationList.querySelector('a')?.getAttribute('style')
                        || 'display:block;padding:15px 17px;border-bottom:1px solid rgba(125,95,75,.16);color:inherit;text-decoration:none';
                    row.style.background = 'rgba(80,130,180,.10)';
                    const title = document.createElement('strong');
                    title.style.cssText = 'display:block;font-size:14px';
                    title.textContent = notification?.title || 'New notification';
                    row.appendChild(title);
                    if (notification?.message) {
                        const message = document.createElement('span');
                        message.style.cssText = 'display:block;margin-top:4px;opacity:.72;font-size:13px;line-height:1.45';
                        message.textContent = notification.message;
                        row.appendChild(message);
                    }
                    const time = document.createElement('small');
                    time.style.cssText = 'display:block;margin-top:6px;opacity:.55;font-size:11px';
                    time.textContent = notification?.created_at
                        ? new Date(notification.created_at).toLocaleString('en-PH', {timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short'})
                        : 'Just now';
                    row.appendChild(time);
                    notificationList.prepend(row);
                }

                showToast(notification?.title || 'A new notification has arrived.');
            });
    }

    const setMobileSidebar = (open) => {
        if (!sidebar) return;
        const isOpen = open && !desktop.matches;
        sidebar.classList.toggle('is-open', isOpen);
        overlay?.classList.toggle('is-open', isOpen);
        document.body.classList.toggle('sl-mobile-open', isOpen);
        mobileButton?.setAttribute('aria-expanded', String(isOpen));
        mobileButton?.setAttribute('aria-label', isOpen
            ? (mobileButton.dataset.closeLabel || 'Close navigation')
            : (mobileButton.dataset.openLabel || 'Open navigation'));
        collapseButton?.setAttribute('aria-expanded', String(isOpen));
        if (!desktop.matches) {
            collapseButton?.setAttribute('aria-label', isOpen ? 'Close navigation' : 'Open navigation');
        }
    };

    const setCollapsed = (collapsed, persist = true) => {
        if (!sidebar) return;
        if (!desktop.matches) collapsed = false;
        document.body.classList.toggle('sl-sidebar-collapsed', collapsed);
        if (dashboardAutoCollapse) document.body.classList.toggle('sl-dashboard-sidebar-expanded', !collapsed);
        const mobileOpen = !desktop.matches && sidebar.classList.contains('is-open');
        collapseButton?.setAttribute('aria-expanded', String(desktop.matches ? !collapsed : mobileOpen));
        collapseButton?.setAttribute('aria-label', desktop.matches
            ? (collapsed ? 'Expand sidebar' : 'Collapse sidebar')
            : (mobileOpen ? 'Close navigation' : 'Open navigation'));
        if (persist) writeStorage(storageKey, String(collapsed));
    };

    if (desktop.matches) setCollapsed(dashboardAutoCollapse || readStorage(storageKey) === 'true', false);
    else setMobileSidebar(false);
    if (dashboardAutoCollapse && sidebar) {
        sidebar.addEventListener('pointerenter', () => setCollapsed(false, false));
        sidebar.addEventListener('pointerleave', () => {
            if (!sidebar.contains(document.activeElement)) setCollapsed(true, false);
        });
        sidebar.addEventListener('focusin', () => setCollapsed(false, false));
        sidebar.addEventListener('focusout', (event) => {
            if (!sidebar.contains(event.relatedTarget) && !sidebar.matches(':hover')) setCollapsed(true, false);
        });
    }
    mobileButton?.addEventListener('click', () => setMobileSidebar(!sidebar?.classList.contains('is-open')));
    overlay?.addEventListener('click', () => setMobileSidebar(false));
    collapseButton?.addEventListener('click', () => {
        if (!desktop.matches) {
            setMobileSidebar(!sidebar?.classList.contains('is-open'));
            return;
        }
        setCollapsed(
            !document.body.classList.contains('sl-sidebar-collapsed'),
            !dashboardAutoCollapse,
        );
    });

    desktop.addEventListener('change', (event) => {
        setMobileSidebar(false);
        setCollapsed(event.matches && (dashboardAutoCollapse || readStorage(storageKey) === 'true'), false);
    });

    selectAll('.sl-sidebar a').forEach((link) => link.addEventListener('click', () => {
        if (!desktop.matches) setMobileSidebar(false);
    }));

    selectAll('[data-nav-toggle]').forEach((button) => {
        const submenu = button.nextElementSibling;
        if (!submenu?.matches('[data-nav-submenu]')) return;

        button.addEventListener('click', () => {
            const open = !button.classList.contains('is-open');
            selectAll('[data-nav-toggle]').forEach((other) => {
                if (other === button) return;
                const otherMenu = other.nextElementSibling;
                other.classList.remove('is-open');
                other.setAttribute('aria-expanded', 'false');
                if (otherMenu?.matches('[data-nav-submenu]')) {
                    otherMenu.classList.remove('is-open');
                    window.setTimeout(() => { if (!otherMenu.classList.contains('is-open')) otherMenu.hidden = true; }, 200);
                }
            });

            button.classList.toggle('is-open', open);
            button.setAttribute('aria-expanded', String(open));
            if (open) {
                submenu.hidden = false;
                window.requestAnimationFrame(() => submenu.classList.add('is-open'));
            } else {
                submenu.classList.remove('is-open');
                window.setTimeout(() => { if (!submenu.classList.contains('is-open')) submenu.hidden = true; }, 200);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setMobileSidebar(false);
            closeModal(select('.sl-modal:not([hidden])'));
        }
    });

    const openModal = (modal) => {
        if (!modal) return;
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        select('input, select, textarea, button', modal)?.focus();
    };

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    selectAll('[data-modal-open]').forEach((button) => button.addEventListener('click', () => {
        const modal = select(`[data-modal="${button.dataset.modalOpen}"]`);
        const editPayload = button.dataset.voucherEdit;
        if (modal && button.dataset.modalOpen === 'campaignModal') {
            const form = select('[data-voucher-form]', modal);
            form?.reset();
            if (form) {
                delete form.dataset.editingVoucherId;
                syncVoucherConflictWarnings(form);
                syncVoucherTargetForm(form);
            }
        }
        if (modal && editPayload) {
            const form = select('[data-voucher-edit-form]', modal);
            if (!form || !button.dataset.updateUrl) return;
            form.action = button.dataset.updateUrl;
            form.dataset.editingVoucherId = button.dataset.voucherId || '';
            const values = {
                edit_voucher_id: button.dataset.voucherId,
                name: button.dataset.voucherName,
                code: button.dataset.voucherCode,
                discount_type: button.dataset.discountType,
                discount_value: button.dataset.discountValue,
                minimum_order_amount: button.dataset.minimumOrderAmount,
                maximum_discount_amount: button.dataset.maximumDiscountAmount,
                seller_discount_budget_total: button.dataset.sellerDiscountBudgetTotal,
                applies_to: button.dataset.appliesTo,
                visibility: button.dataset.visibility || 'PUBLIC',
                usage_limit: button.dataset.usageLimit,
                per_user_limit: button.dataset.perUserLimit,
                starts_at: button.dataset.startsAt,
                ends_at: button.dataset.endsAt,
            };
            Object.entries(values).forEach(([name, value]) => {
                const field = select(`[name="${name}"]`, form);
                if (field) field.value = value ?? '';
            });
            const selectedProductIds = new Set((button.dataset.productIds || '').split(',').filter(Boolean));
            const selectedVariantIds = new Set((button.dataset.variantIds || '').split(',').filter(Boolean));
            selectAll('[data-voucher-product-checkbox]', form).forEach((checkbox) => {
                checkbox.checked = selectedProductIds.has(checkbox.value);
            });
            selectAll('[data-voucher-variant-checkbox]', form).forEach((checkbox) => {
                checkbox.checked = selectedVariantIds.has(checkbox.value);
            });
            syncVoucherConflictWarnings(form);
            syncVoucherTargetForm(form);
        }
        openModal(modal);
    }));

    function syncVoucherTargetForm(form) {
        const target = select('[data-voucher-target]', form);
        const productWrapper = select('[data-voucher-products-field]', form);
        const variantWrapper = select('[data-voucher-variants-field]', form);
        const selectingProducts = target?.value === 'PRODUCTS';
        const selectingVariants = target?.value === 'VARIANTS';
        if (productWrapper) productWrapper.hidden = !selectingProducts;
        if (variantWrapper) variantWrapper.hidden = !selectingVariants;
        selectAll('[data-voucher-product-checkbox]', form).forEach((field) => { field.disabled = !selectingProducts; });
        selectAll('[data-voucher-variant-checkbox]', form).forEach((field) => { field.disabled = !selectingVariants; });
        updateVoucherPickerCounts(form);
        updateVoucherPricePreview(form);
    }

    function syncVoucherConflictWarnings(form) {
        const editingVoucherId = form.dataset.editingVoucherId || '';
        selectAll('[data-voucher-conflict-id]', form).forEach((warning) => {
            const belongsToEditingVoucher = editingVoucherId
                && warning.dataset.voucherConflictKind === 'voucher'
                && warning.dataset.voucherConflictId === editingVoucherId;
            warning.hidden = Boolean(belongsToEditingVoucher);
        });
        const affectedProducts = selectAll('[data-voucher-conflict-product]', form).filter((product) => {
            const hasOtherOffer = selectAll('[data-voucher-conflict-entry]', product).some((entry) => !entry.hidden);
            product.hidden = !hasOtherOffer;
            return hasOtherOffer;
        });
        const conflictDetails = select('[data-voucher-conflict-details]', form);
        if (conflictDetails) {
            conflictDetails.hidden = affectedProducts.length === 0;
            select('[data-voucher-conflict-count]', conflictDetails)?.replaceChildren(String(affectedProducts.length));
        }
    }

    function updateVoucherPickerCounts(form) {
        const productCount = selectAll('[data-voucher-product-checkbox]:checked', form).length;
        const variantCount = selectAll('[data-voucher-variant-checkbox]:checked', form).length;
        select('[data-selected-count="products"]', form)?.replaceChildren(String(productCount));
        select('[data-selected-count="variants"]', form)?.replaceChildren(String(variantCount));
    }

    function updateVoucherPricePreview(form) {
        const preview = select('[data-voucher-preview-text]');
        if (!preview || !form.closest('.sl-marketing-center')) return;
        const scope = select('[data-voucher-target]', form)?.value || 'STORE';
        const pickedProductIds = new Set(selectAll('[data-voucher-product-checkbox]:checked', form).map((field) => field.value));
        const pickedVariantIds = new Set(selectAll('[data-voucher-variant-checkbox]:checked', form).map((field) => field.value));
        const prices = selectAll('[data-voucher-variant-checkbox]', form)
            .filter((field) => scope === 'VARIANTS' ? pickedVariantIds.has(field.value)
                : scope === 'PRODUCTS' ? pickedProductIds.has(field.closest('[data-voucher-variant-product]')?.dataset.productId)
                    : true)
            .map((field) => Number(field.dataset.price))
            .filter((price) => Number.isFinite(price) && price > 0)
            .sort((a, b) => a - b);
        const amount = prices[0];
        const discountType = select('[name="discount_type"]', form)?.value || 'PERCENT';
        const discountValue = Math.max(0, Number(select('[name="discount_value"]', form)?.value || 0));
        const maximum = Number(select('[name="maximum_discount_amount"]', form)?.value || 0);
        if (!amount || !discountValue) {
            preview.textContent = 'Choose an eligibility scope and discount to preview the lowest eligible variant price.';
            return;
        }
        let discount = discountType === 'PERCENT' ? amount * Math.min(discountValue, 100) / 100 : Math.min(amount, discountValue);
        if (maximum > 0) discount = Math.min(discount, maximum);
        discount = Math.round(discount * 100) / 100;
        preview.textContent = `Lowest eligible variant: PHP ${amount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}. Estimated discount: PHP ${discount.toLocaleString('en-PH', { minimumFractionDigits: 2 })}. Price after this voucher: PHP ${Math.max(0, amount - discount).toLocaleString('en-PH', { minimumFractionDigits: 2 })}.`;
    }

    selectAll('[data-voucher-target-form]').forEach((form) => {
        select('[data-voucher-target]', form)?.addEventListener('change', () => syncVoucherTargetForm(form));
        form.addEventListener('input', (event) => {
            if (event.target.matches('[data-voucher-search="products"]')) {
                const query = event.target.value.trim().toLocaleLowerCase();
                selectAll('[data-voucher-product-row]', form).forEach((row) => { row.hidden = !row.dataset.search.includes(query); });
            }
            if (event.target.matches('[data-voucher-search="variants"]')) {
                const query = event.target.value.trim().toLocaleLowerCase();
                selectAll('[data-voucher-variant-product]', form).forEach((product) => {
                    const productMatch = product.dataset.search.includes(query);
                    let rowMatch = false;
                    selectAll('[data-voucher-variant-row]', product).forEach((row) => {
                        row.hidden = !(productMatch || row.dataset.search.includes(query));
                        rowMatch ||= !row.hidden;
                    });
                    product.hidden = !(productMatch || rowMatch);
                    if (query && !productMatch && rowMatch) product.open = true;
                });
            }
        });
        form.addEventListener('change', (event) => {
            if (event.target.matches('[data-voucher-product-checkbox], [data-voucher-variant-checkbox]')) {
                updateVoucherPickerCounts(form);
                updateVoucherPricePreview(form);
            }
        });
        syncVoucherTargetForm(form);
    });
    selectAll('[data-select-visible="products"]').forEach((button) => button.addEventListener('click', () => {
        const form = button.closest('form');
        selectAll('[data-voucher-product-row]:not([hidden]) [data-voucher-product-checkbox]', form).forEach((checkbox) => { checkbox.checked = true; });
        updateVoucherPickerCounts(form);
        updateVoucherPricePreview(form);
    }));
    selectAll('[data-clear-selection="products"]').forEach((button) => button.addEventListener('click', () => {
        const form = button.closest('form');
        selectAll('[data-voucher-product-checkbox]', form).forEach((checkbox) => { checkbox.checked = false; });
        updateVoucherPickerCounts(form);
        updateVoucherPricePreview(form);
    }));
    selectAll('[data-select-product-variants]').forEach((button) => button.addEventListener('click', () => {
        const form = button.closest('form');
        selectAll('[data-voucher-variant-checkbox]', button.closest('[data-voucher-variant-product]')).forEach((checkbox) => { checkbox.checked = true; });
        updateVoucherPickerCounts(form);
        updateVoucherPricePreview(form);
    }));
    const syncBundleRemoveButtons = (container) => {
        const canRemove = container.querySelectorAll('[data-bundle-component]').length > 1;
        selectAll('[data-remove-bundle-component]', container).forEach((button) => { button.disabled = !canRemove; });
    };
    selectAll('[data-add-bundle-component]').forEach((button) => button.addEventListener('click', (event) => {
        const form = event.currentTarget.closest('form');
        const container = select('[data-bundle-components]', form);
        const template = select('[data-bundle-component-template]');
        if (!container || !template || container.querySelectorAll('[data-bundle-component]').length >= 10) return;
        const index = Number(container.dataset.nextIndex || 1);
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach((field) => { field.name = field.name.replaceAll('__INDEX__', String(index)); });
        container.appendChild(fragment);
        container.dataset.nextIndex = String(index + 1);
        syncBundleRemoveButtons(container);
    }));
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-bundle-component]');
        if (!button) return;
        const container = button.closest('[data-bundle-components]');
        if (!container || container.querySelectorAll('[data-bundle-component]').length <= 1) return;
        button.closest('[data-bundle-component]')?.remove();
        syncBundleRemoveButtons(container);
    });
    selectAll('[data-flash-search]').forEach((field) => field.addEventListener('input', () => {
        const query = field.value.trim().toLocaleLowerCase();
        selectAll('[data-flash-product]').forEach((product) => { product.hidden = !product.dataset.search.includes(query); });
    }));
    const targetedFlashProductId = new URLSearchParams(window.location.search).get('product_id');
    if (targetedFlashProductId) {
        const targetedFlashProduct = select(`[data-flash-product-id="${CSS.escape(targetedFlashProductId)}"]`);
        targetedFlashProduct?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        targetedFlashProduct?.classList.add('is-targeted');
    }
    selectAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => closeModal(button.closest('[data-modal]'))));
    selectAll('[data-modal]').forEach((modal) => modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
    }));

    const filesFrom = (selector) => Array.from(select(selector)?.files || []);
    const imageLibraryItems = () => selectAll('[data-library-image]:not(.is-removing), [data-new-library-image]');
    const descriptionVisual = select('[data-description-visual]');
    const descriptionContent = select('[data-description-content]');
    const descriptionField = select('[data-description-value]');
    const descriptionCharacterCount = select('[data-character-count]');
    const descriptionImagePicker = select('[data-description-image-picker]');
    const descriptionImagesInput = select('[data-description-images-input]');
    const descriptionImageLibrary = select('[data-description-image-library]');
    const descriptionUploadFiles = [];
    const descriptionUploadUrls = [];
    let descriptionSelection = null;
    const descriptionTokenPattern = /\[\[image:(image:\d+|upload:primary|upload:additional:\d+)\]\]|\[\[description-image:(stored:[A-Za-z0-9._-]+|upload:\d+)\]\]/g;

    const rememberDescriptionSelection = () => {
        const selection = window.getSelection();
        if (!selection?.rangeCount || !descriptionContent?.contains(selection.anchorNode)) return;
        descriptionSelection = selection.getRangeAt(0).cloneRange();
    };

    const appendDescriptionText = (text) => {
        if (!descriptionContent || !text) return;
        String(text).split('\n').forEach((line, index) => {
            if (index > 0) descriptionContent.appendChild(document.createElement('br'));
            if (line) descriptionContent.appendChild(document.createTextNode(line));
        });
    };

    const descriptionImageSource = (reference, legacy = false) => {
        if (legacy) return imageLibraryItems().find((candidate) => candidate.dataset.imageRef === reference) || null;
        if (reference.startsWith('stored:')) {
            return selectAll('[data-description-image-item]', descriptionImageLibrary)
                .find((candidate) => candidate.dataset.descriptionImageRef === reference) || null;
        }
        if (reference.startsWith('upload:')) {
            const index = Number(reference.split(':').pop());
            const file = descriptionUploadFiles[index];
            return file ? {
                dataset: { imageUrl: descriptionUploadUrls[index] || '', imageLabel: file.name },
                querySelector: () => null,
            } : null;
        }

        return null;
    };

    const createDescriptionImage = (reference, legacy = false) => {
        const figure = document.createElement('figure');
        figure.contentEditable = 'false';
        figure.dataset.descriptionImageRef = reference;
        figure.dataset.descriptionImageLegacy = String(legacy);

        const image = document.createElement('img');
        const item = descriptionImageSource(reference, legacy);
        if (item) {
            image.src = item.dataset.imageUrl || '';
            image.alt = item.querySelector('img')?.alt || item.dataset.imageLabel || 'Product description image';
        } else {
            image.hidden = true;
        }
        figure.appendChild(image);

        const note = document.createElement('span');
        note.dataset.descriptionImageNote = 'true';
        note.textContent = item ? '' : 'This description picture is unavailable. Remove it and upload it again.';
        note.hidden = Boolean(item);
        figure.appendChild(note);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.dataset.descriptionImageRemove = 'true';
        remove.setAttribute('aria-label', 'Remove picture from description');
        remove.textContent = 'Remove picture';
        figure.appendChild(remove);

        return figure;
    };

    const renderDescriptionEditor = () => {
        if (!descriptionContent || !descriptionField || !descriptionVisual) return;
        descriptionContent.replaceChildren();
        const description = descriptionField.value || '';
        let offset = 0;
        for (const match of description.matchAll(descriptionTokenPattern)) {
            const tokenOffset = match.index ?? 0;
            appendDescriptionText(description.slice(offset, tokenOffset));
            const legacy = Boolean(match[1]);
            descriptionContent.appendChild(createDescriptionImage(legacy ? match[1] : match[2], legacy));
            offset = tokenOffset + match[0].length;
        }
        appendDescriptionText(description.slice(offset));
        descriptionContent.dataset.ready = 'true';
        descriptionContent.contentEditable = 'true';
        const descriptionImageButton = select('[data-description-insert-image]');
        if (descriptionImageButton) descriptionImageButton.disabled = false;
        descriptionVisual.hidden = false;
        descriptionField.hidden = true;
    };

    const serializeDescriptionNode = (node) => {
        if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || '';
        if (node.nodeType !== Node.ELEMENT_NODE) return '';
        if (node.matches('[data-description-image-ref]')) {
            return node.dataset.descriptionImageLegacy === 'true'
                ? `[[image:${node.dataset.descriptionImageRef}]]`
                : `[[description-image:${node.dataset.descriptionImageRef}]]`;
        }
        if (node.tagName === 'BR') return '\n';
        const content = Array.from(node.childNodes).map(serializeDescriptionNode).join('');
        return ['DIV', 'P'].includes(node.tagName) ? `${content}\n` : content;
    };

    const syncDescriptionValue = () => {
        if (!descriptionContent?.dataset.ready || !descriptionField) return '';
        const value = Array.from(descriptionContent.childNodes)
            .map(serializeDescriptionNode)
            .join('')
            .replace(/\n+$/, '');
        descriptionField.value = value;
        const readableValue = value.replace(/\[\[(?:image|description-image):[^\]]+\]\]/g, '');
        if (descriptionCharacterCount) descriptionCharacterCount.textContent = String(readableValue.length);
        return value;
    };

    const syncDescriptionImagePreviews = () => {
        if (!descriptionContent?.dataset.ready) return;
        selectAll('[data-description-image-ref]', descriptionContent).forEach((figure) => {
            const item = descriptionImageSource(figure.dataset.descriptionImageRef || '', figure.dataset.descriptionImageLegacy === 'true');
            const image = select('img', figure);
            const note = select('[data-description-image-note]', figure);
            if (item && image) {
                image.src = item.dataset.imageUrl || '';
                image.alt = item.querySelector('img')?.alt || item.dataset.imageLabel || 'Product description image';
                image.hidden = false;
                if (note) note.hidden = true;
            } else {
                if (image) image.hidden = true;
                if (note) {
                    note.hidden = false;
                    note.textContent = 'This description picture is unavailable. Remove it and upload it again.';
                }
            }
        });
        syncDescriptionValue();
    };

    const removeDescriptionImageReference = (reference) => {
        if (!descriptionContent) return;
        selectAll('[data-description-image-ref]', descriptionContent)
            .filter((figure) => figure.dataset.descriptionImageRef === reference)
            .forEach((figure) => figure.remove());
        syncDescriptionValue();
    };

    const remapDescriptionUploadAfterRemoval = (kind, index) => {
        if (!descriptionContent) return;
        selectAll('[data-description-image-ref]', descriptionContent).forEach((figure) => {
            const reference = figure.dataset.descriptionImageRef || '';
            if (kind === 'primary' && reference === 'upload:primary') {
                figure.remove();
                return;
            }
            if (kind !== 'additional' || !reference.startsWith('upload:additional:')) return;
            const currentIndex = Number(reference.split(':').pop());
            if (currentIndex === index) figure.remove();
            else if (currentIndex > index) figure.dataset.descriptionImageRef = `upload:additional:${currentIndex - 1}`;
        });
        syncDescriptionValue();
    };

    const syncDescriptionUploadInput = () => {
        if (!descriptionImagesInput || !window.DataTransfer) return false;
        const transfer = new DataTransfer();
        descriptionUploadFiles.forEach((file) => transfer.items.add(file));
        descriptionImagesInput.files = transfer.files;
        return true;
    };

    const removeDescriptionUpload = (index) => {
        if (index < 0 || index >= descriptionUploadFiles.length) return;
        const oldUrl = descriptionUploadUrls[index];
        if (oldUrl) URL.revokeObjectURL(oldUrl);
        descriptionUploadFiles.splice(index, 1);
        descriptionUploadUrls.splice(index, 1);
        selectAll('[data-description-image-ref]', descriptionContent).forEach((figure) => {
            if (figure.dataset.descriptionImageLegacy === 'true') return;
            const reference = figure.dataset.descriptionImageRef || '';
            if (!reference.startsWith('upload:')) return;
            const currentIndex = Number(reference.split(':').pop());
            if (currentIndex === index) figure.remove();
            else if (currentIndex > index) figure.dataset.descriptionImageRef = `upload:${currentIndex - 1}`;
        });
        syncDescriptionUploadInput();
        syncDescriptionImagePreviews();
    };

    const insertDescriptionImage = (reference, legacy = false) => {
        if (!descriptionContent || !reference) return;
        if (selectAll('[data-description-image-ref]', descriptionContent).length >= 10) {
            showToast('A product description can include up to 10 pictures.');
            return;
        }
        const item = descriptionImageSource(reference, legacy);
        if (!item) {
            showToast('Choose or upload an available description picture first.');
            return;
        }

        const figure = createDescriptionImage(reference, legacy);
        descriptionContent.focus();
        const selection = window.getSelection();
        let range = descriptionSelection?.commonAncestorContainer
            && descriptionContent.contains(descriptionSelection.commonAncestorContainer)
            ? descriptionSelection.cloneRange()
            : null;
        if (!range) {
            range = document.createRange();
            range.selectNodeContents(descriptionContent);
            range.collapse(false);
        }
        range.collapse(false);
        range.insertNode(figure);
        const breakAfterImage = document.createElement('br');
        figure.after(breakAfterImage);
        range = document.createRange();
        range.setStartAfter(breakAfterImage);
        range.collapse(true);
        selection?.removeAllRanges();
        selection?.addRange(range);
        descriptionSelection = range.cloneRange();
        syncDescriptionValue();
        descriptionContent.dispatchEvent(new Event('input', { bubbles: true }));
    };

    descriptionContent?.addEventListener('input', () => {
        rememberDescriptionSelection();
        syncDescriptionValue();
    });
    descriptionContent?.addEventListener('keyup', rememberDescriptionSelection);
    descriptionContent?.addEventListener('mouseup', rememberDescriptionSelection);
    descriptionContent?.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-description-image-remove]');
        if (!removeButton) return;
        const figure = removeButton.closest('[data-description-image-ref]');
        if (!figure) return;
        const reference = figure.dataset.descriptionImageRef || '';
        if (figure.dataset.descriptionImageLegacy !== 'true' && reference.startsWith('upload:')) {
            removeDescriptionUpload(Number(reference.split(':').pop()));
            return;
        }
        figure.remove();
        syncDescriptionValue();
    });

    descriptionImagePicker?.addEventListener('change', () => {
        const selectedFiles = Array.from(descriptionImagePicker.files || []);
        const currentPictures = selectAll('[data-description-image-ref]', descriptionContent).length;
        if (currentPictures + selectedFiles.length > 10 || descriptionUploadFiles.length + selectedFiles.length > 10) {
            showToast('A product description can include up to 10 pictures.');
            descriptionImagePicker.value = '';
            return;
        }
        const startIndex = descriptionUploadFiles.length;
        selectedFiles.forEach((file) => {
            descriptionUploadFiles.push(file);
            descriptionUploadUrls.push(URL.createObjectURL(file));
        });
        if (!syncDescriptionUploadInput()) {
            descriptionUploadFiles.splice(startIndex, selectedFiles.length);
            descriptionUploadUrls.splice(startIndex, selectedFiles.length).forEach((url) => URL.revokeObjectURL(url));
            showToast('Your browser could not prepare description picture uploads. Please use a current browser.');
            descriptionImagePicker.value = '';
            return;
        }
        selectedFiles.forEach((file, index) => insertDescriptionImage(`upload:${startIndex + index}`));
        descriptionImagePicker.value = '';
    });

    const clearVariantImageAssignments = (imageRef) => {
        if (!imageRef) return;
        variationRows().forEach((row) => {
            const ref = rowField(row, 'product_image_ref');
            if (ref?.value !== imageRef) return;
            ref.value = '';
            syncVariantImageButton(row);
        });
    };

    const renderFilePreview = (file, alt, className = '') => {
        const image = document.createElement('img');
        image.className = className;
        image.src = URL.createObjectURL(file);
        image.alt = alt;
        image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
        return image;
    };

    const syncProductImageLibrary = () => {
        const primaryFiles = filesFrom('[data-image-input]');
        const additionalFiles = filesFrom('[data-images-input]');
        const newLibrary = select('[data-new-product-image-library]');
        const primaryPreview = select('[data-image-preview]');
        if (!newLibrary) return;

        if (primaryPreview) {
            if (primaryFiles[0]) {
                primaryPreview.innerHTML = '';
                primaryPreview.appendChild(renderFilePreview(primaryFiles[0], primaryFiles[0].name));
            }
        }
        newLibrary.innerHTML = '';
        const existingCount = selectAll('[data-library-image]:not(.is-removing)').length;
        const imageLimitError = existingCount + primaryFiles.length + additionalFiles.length > 10
            ? 'A product may have at most 10 images, including the primary image.'
            : '';
        const productForm = select('.sl-editor-layout');
        [select('[data-image-input]'), select('[data-images-input]')].filter(Boolean)
            .forEach((input) => input.setCustomValidity(imageLimitError));
        if (productForm && imageLimitError && productForm.dataset.imageLimitInvalid !== 'true') showToast(imageLimitError);
        if (productForm) productForm.dataset.imageLimitInvalid = String(Boolean(imageLimitError));
        const renderNewImage = (file, ref, number, primary = false, index = 0) => {
            const item = document.createElement('span');
            item.className = 'sl-saved-image sl-product-image-library__item';
            item.dataset.newLibraryImage = 'true';
            item.dataset.imageRef = ref;
            item.dataset.imageUrl = URL.createObjectURL(file);
            item.dataset.imageLabel = `#${number}`;
            item.appendChild(Object.assign(document.createElement('span'), { className: 'sl-image-number', textContent: `#${number}${primary ? ' · Primary' : ''}` }));
            item.appendChild(renderFilePreview(file, file.name));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'sl-image-remove';
            remove.dataset.removeNewProductImage = 'true';
            remove.dataset.uploadKind = primary ? 'primary' : 'additional';
            remove.dataset.uploadIndex = String(index);
            remove.textContent = 'Remove';
            item.appendChild(remove);
            newLibrary.appendChild(item);
        };

        if (primaryFiles[0]) renderNewImage(primaryFiles[0], 'upload:primary', existingCount + 1, true);
        additionalFiles.forEach((file, index) => renderNewImage(file, `upload:additional:${index}`, existingCount + (primaryFiles[0] ? 2 : 1) + index, false, index));
        syncAllVariantImageButtons();
        syncBulkImageButton();
        syncDescriptionImagePreviews();
    };

    const removeFileFromInput = (selector, index) => {
        const input = select(selector);
        if (!input?.files?.length || !window.DataTransfer) return;
        const transfer = new DataTransfer();
        Array.from(input.files).forEach((file, fileIndex) => {
            if (fileIndex !== index) transfer.items.add(file);
        });
        input.files = transfer.files;
        syncProductImageLibrary();
        select('.sl-editor-layout')?.dispatchEvent(new Event('input', { bubbles: true }));
    };

    select('[data-image-input]')?.addEventListener('change', syncProductImageLibrary);
    select('[data-images-input]')?.addEventListener('change', syncProductImageLibrary);

    const productTypeEditor = select('[data-product-type-editor]');
    if (productTypeEditor) {
        const singleFields = select('[data-single-product-fields]');
        const variationFields = select('[data-variation-product-fields]');
        const syncProductType = () => {
            const variations = select('[name="product_type"]:checked')?.value === 'variations';
            if (singleFields) singleFields.hidden = variations;
            if (variationFields) variationFields.hidden = !variations;
            selectAll('input, select, textarea', singleFields || document).forEach((field) => { field.disabled = variations; });
            selectAll('input, select, textarea', variationFields || document).forEach((field) => { field.disabled = !variations; });
        };
        selectAll('[name="product_type"]', productTypeEditor).forEach((radio) => radio.addEventListener('change', syncProductType));
        syncProductType();
    }

    const productForm = select('.sl-editor-layout');
    if (productForm) {
        const money = (value) => `₱${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const updateEditorPreview = () => {
            const variations = select('[name="product_type"]:checked', productForm)?.value === 'variations';
            const name = select('[name="name"]', productForm)?.value.trim() || 'Your product name';
            const category = select('[name="category_id"] option:checked', productForm)?.textContent.trim() || 'Subcategory';
            const variantPriceFields = selectAll('[name^="variants"][name$="[price]"]:not(:disabled)', productForm);
            const variantPrices = variantPriceFields
                .map((field) => Number(field.value || 0)).filter((price) => Number.isFinite(price) && price >= 0);
            const variantStocks = selectAll('[name^="variants"][name$="[stock]"]:not(:disabled)', productForm).map((field) => Number(field.value || 0));
            const singlePriceField = select('[name="price"]:not(:disabled)', productForm);
            const singleBasePrice = Number(singlePriceField?.value || 0);
            const singleStock = Number(select('[name="stock"]:not(:disabled)', productForm)?.value || 0);
            const previewPrice = (field, basePrice, stock) => {
                const base = Math.round(Math.max(0, basePrice) * 100) / 100;
                const flashPrice = Number(field?.dataset.marketingFlashPrice);
                const flashActive = field?.dataset.marketingFlashActive === 'true'
                    && stock > 0
                    && Number(field?.dataset.marketingFlashRemaining || 0) > 0
                    && Number.isFinite(flashPrice)
                    && flashPrice > 0;
                if (flashActive) return { price: Math.max(0.01, Math.min(base, flashPrice)), source: 'flash_sale', base };

                if (field?.dataset.marketingDiscountActive === 'true') {
                    const value = Math.max(0, Number(field.dataset.marketingDiscountValue || 0));
                    const amount = field.dataset.marketingDiscountType === 'fixed'
                        ? value
                        : field.dataset.marketingDiscountType === 'percentage'
                            ? base * Math.min(100, value) / 100
                            : 0;
                    if (amount > 0 && base > 0) {
                        return { price: Math.max(0.01, Math.round((base - amount) * 100) / 100), source: 'product_discount', base };
                    }
                }

                return { price: base, source: 'regular', base };
            };
            const priceResults = variations
                ? variantPriceFields.map((field, index) => previewPrice(field, variantPrices[index] || 0, variantStocks[index] || 0))
                : [previewPrice(singlePriceField, singleBasePrice, singleStock)];
            const prices = priceResults.map((row) => row.price);
            const stock = variations ? variantStocks.reduce((sum, value) => sum + value, 0) : singleStock;
            const priceText = prices.length && Math.min(...prices) !== Math.max(...prices) ? `${money(Math.min(...prices))} - ${money(Math.max(...prices))}` : money(prices[0] || 0);

            if (select('[data-preview-name]')) select('[data-preview-name]').textContent = name;
            if (select('[data-preview-category]')) select('[data-preview-category]').textContent = category === 'Select subcategory' ? 'Subcategory' : category;
            if (select('[data-preview-price]')) select('[data-preview-price]').textContent = priceText;
            if (select('[data-preview-stock]')) select('[data-preview-stock]').textContent = `${stock} available`;
            const previewDiscount = select('[data-preview-discount]', productForm);
            if (previewDiscount) {
                const discounted = priceResults.filter((row) => row.price < row.base);
                previewDiscount.hidden = discounted.length === 0;
                previewDiscount.textContent = discounted.some((row) => row.source === 'flash_sale')
                    ? 'Flash Sale price managed in Marketing'
                    : 'Product discount managed in Marketing';
            }

            const previewImage = select('[data-preview-image]', productForm);
            const previewGallery = select('[data-preview-gallery]', productForm);
            const previewOptions = select('[data-preview-options]', productForm);
            const imageItems = imageLibraryItems().filter((item) => item.dataset.imageUrl);
            const previewItems = previewGallery
                ? selectAll('[data-preview-saved-image]', previewGallery).filter((item) => item.dataset.imageUrl)
                : [];
            const galleryItems = imageItems.length ? imageItems : previewItems;
            const primaryFile = filesFrom('[data-image-input]')[0];

            if (previewImage && !primaryFile && previewImage.dataset.previewFromUpload === 'true') {
                previewImage.src = previewImage.dataset.previewDefaultSrc || previewImage.src;
                delete previewImage.dataset.previewFromUpload;
            }

            if (previewGallery) {
                const selectedPreviewUrl = previewImage?.dataset.previewSelected && galleryItems.some((item) => item.dataset.imageUrl === previewImage.dataset.previewSelected)
                    ? previewImage.dataset.previewSelected
                    : galleryItems[0]?.dataset.imageUrl;
                previewGallery.innerHTML = '';
                previewGallery.hidden = galleryItems.length === 0;
                galleryItems.forEach((item, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'sl-preview-gallery-button';
                    const selected = item.dataset.imageUrl === selectedPreviewUrl || (!selectedPreviewUrl && index === 0);
                    button.classList.toggle('is-selected', selected);
                    button.setAttribute('aria-pressed', String(selected));
                    button.setAttribute('aria-label', `Preview ${item.dataset.imageLabel || 'product image'}`);
                    button.addEventListener('click', () => {
                        if (!previewImage) return;
                        previewImage.src = item.dataset.imageUrl;
                        previewImage.dataset.previewSelected = item.dataset.imageUrl;
                        selectAll('.sl-preview-gallery-button', previewGallery).forEach((thumbnail) => thumbnail.classList.remove('is-selected'));
                        selectAll('.sl-preview-gallery-button', previewGallery).forEach((thumbnail) => thumbnail.setAttribute('aria-pressed', String(thumbnail === button)));
                        button.classList.add('is-selected');
                    });

                    const image = document.createElement('img');
                    image.src = item.dataset.imageUrl;
                    image.alt = item.dataset.imageLabel || 'Product image';
                    button.appendChild(image);
                    previewGallery.appendChild(button);
                });
            }

            if (previewOptions) {
                previewOptions.innerHTML = '';
                const optionRows = selectAll('[data-option-list] .sl-option-row');
                optionRows.forEach((row) => {
                    const optionName = select('input[name$="[name]"]', row)?.value.trim();
                    const optionValues = (select('input[name$="[values]"]', row)?.value || '')
                        .split(',')
                        .map((value) => value.trim())
                        .filter(Boolean);
                    if (!optionName && !optionValues.length) return;

                    const option = document.createElement('div');
                    option.className = 'sl-preview-option';
                    const label = document.createElement('strong');
                    label.textContent = optionName || 'Option';
                    const values = document.createElement('span');
                    values.textContent = optionValues.join(' · ');
                    option.append(label, values);
                    previewOptions.appendChild(option);
                });
                previewOptions.hidden = !variations || !previewOptions.children.length;
            }

            const pricingFields = variations
                ? selectAll('[name^="variants"][name$="[price]"]:not(:disabled)', productForm)
                : [select('[name="price"]:not(:disabled)', productForm)].filter(Boolean);
            const priceReductions = pricingFields.filter((field) => {
                const savedPrice = Number(field.dataset.savedPrice);
                const currentPrice = Number(field.value);
                return Number.isFinite(savedPrice) && savedPrice > 0
                    && Number.isFinite(currentPrice) && currentPrice < savedPrice - 0.009;
            });
            const reductionWarning = select('[data-price-reduction-warning]', productForm);
            if (reductionWarning) {
                reductionWarning.hidden = priceReductions.length === 0;
                const message = select('[data-price-reduction-message]', reductionWarning);
                if (message) {
                    message.textContent = variations
                        ? `${priceReductions.length} variant price${priceReductions.length === 1 ? ' is' : 's are'} below its saved catalog price. This reduction is Seller-funded.`
                        : `The price is ₱${(Number(priceReductions[0]?.dataset.savedPrice || 0) - Number(priceReductions[0]?.value || 0)).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} below its previously saved catalog price. This reduction is Seller-funded.`;
                }
            }
            const inventoryFields = variations
                ? selectAll('[name^="variants"][name$="[stock]"]:not(:disabled)', productForm)
                : [select('[name="stock"]:not(:disabled)', productForm)].filter(Boolean);
            const hasValidValue = (field, max, integer = false) => {
                const raw = field.value.trim();
                const value = Number(raw);
                return raw !== '' && Number.isFinite(value) && value >= 0 && value <= max
                    && (!integer || Number.isInteger(value));
            };

            const checks = {
                name: name !== 'Your product name',
                description: Boolean(select('[name="description"]', productForm)?.value.trim()),
                category: Boolean(select('[name="category_id"]', productForm)?.value),
                image: Boolean(select('[name="image"]', productForm)?.files?.length || selectAll('[data-library-image]:not(.is-removing)', productForm).length),
                pricing: pricingFields.length > 0 && pricingFields.every((field) => hasValidValue(field, 999999.99)),
                inventory: inventoryFields.length > 0 && inventoryFields.every((field) => hasValidValue(field, 999999, true)),
                variations: !variations || (variantPrices.length > 0 && selectAll('[name^="variants"][name$="[values]"]:not(:disabled)', productForm).every((field) => field.value.trim())),
            };
            Object.entries(checks).forEach(([key, complete]) => {
                const row = select(`[data-check="${key}"]`);
                if (!row) return;
                row.classList.toggle('is-complete', complete);
                const icon = select('span', row);
                if (icon) icon.textContent = complete ? '✓' : '!';
                if (key === 'variations') row.hidden = !variations;
            });
            const publish = select('[data-publish-product]');
            const applicableChecks = Object.entries(checks).filter(([key]) => variations || key !== 'variations');
            if (publish) publish.disabled = !applicableChecks.every(([, value]) => value);
        };
        productForm.addEventListener('input', updateEditorPreview);
        productForm.addEventListener('change', updateEditorPreview);
        select('[data-image-input]', productForm)?.addEventListener('change', (event) => {
            const file = event.target.files?.[0];
            const preview = select('[data-preview-image]');
            if (file && preview) {
                preview.src = URL.createObjectURL(file);
                preview.dataset.previewFromUpload = 'true';
                delete preview.dataset.previewSelected;
            }
        });
        productForm.addEventListener('submit', (event) => {
            const descriptionValue = syncDescriptionValue();
            if (descriptionValue.replace(/\[\[(?:image|description-image):[^\]]+\]\]/g, '').length > 10000) {
                event.preventDefault();
                descriptionContent?.focus();
                showToast('Product description must be 10,000 characters or fewer.');
                return;
            }
            const button = event.submitter;
            const submittedStatus = select('[data-listing-status-field]', productForm);
            if (submittedStatus && button?.dataset.listingStatus) {
                submittedStatus.value = button.dataset.listingStatus;
            }
            const listingStatus = button?.dataset.listingStatus || submittedStatus?.value || 'draft';
            const isDraft = listingStatus === 'draft';
            const progress = select('[data-product-save-progress]', productForm);
            if (progress) {
                progress.textContent = isDraft
                    ? 'Saving your draft. Please keep this page open.'
                    : 'Publishing your product. Please keep this page open.';
                progress.hidden = false;
            }
            productForm.setAttribute('aria-busy', 'true');
            selectAll('button[type="submit"][data-listing-status]', productForm).forEach((submitButton) => {
                submitButton.disabled = true;
                if (submitButton === button) {
                    submitButton.dataset.originalText = submitButton.textContent;
                    submitButton.textContent = isDraft ? 'Saving Draft...' : 'Publishing...';
                }
            });
        });
        updateEditorPreview();
    }

    const optionList = select('[data-option-list]');
    const addOptionButton = select('[data-add-option]');
    const maxOptionTypes = Number(optionList?.dataset.maxOptions || 3);
    const syncOptionTypeLimit = () => {
        if (!optionList || !addOptionButton) return;
        const atLimit = selectAll('.sl-option-row', optionList).length >= maxOptionTypes;
        addOptionButton.disabled = atLimit;
        addOptionButton.title = atLimit ? `Only ${maxOptionTypes} variation types are allowed.` : '';
        addOptionButton.setAttribute('aria-disabled', String(atLimit));
    };

    addOptionButton?.addEventListener('click', () => {
        const list = optionList;
        if (!list) return;
        if (selectAll('.sl-option-row', list).length >= maxOptionTypes) {
            syncOptionTypeLimit();
            showToast(`You can add up to ${maxOptionTypes} variation types.`);
            return;
        }
        const indexes = selectAll('input[name^="options["][name$="[name]"]', list)
            .map((field) => Number(field.name.match(/^options\[(\d+)\]/)?.[1]))
            .filter((index) => Number.isInteger(index));
        const index = indexes.length ? Math.max(...indexes) + 1 : 0;
        const row = document.createElement('div');
        row.className = 'sl-option-row';
        row.innerHTML = `<label class="sl-field"><span>Variation type</span><input name="options[${index}][name]" placeholder="e.g. Color"></label><label class="sl-field"><span>Options</span><input name="options[${index}][values]" placeholder="Red, Blue, Natural"></label><button type="button" class="sl-icon-btn" data-remove-option aria-label="Remove variation type">&times;</button>`;
        list.appendChild(row);
        select('input', row)?.focus();
        syncOptionTypeLimit();
    });
    syncOptionTypeLimit();

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;',
    }[character]));

    const variationRows = () => selectAll('[data-variation-list] .sl-variation-row:not(.sl-variation-labels)');
    const rowField = (row, suffix) => select(`[name$="[${suffix}]"]`, row);
    const rowValuesKey = (value) => (value || '').split(/[,|]+/).map((item) => item.trim().toLowerCase()).filter(Boolean).join('|');

    let activeImagePickerTarget = null;
    const imagePickerDialog = select('[data-image-assignment-dialog]');

    const syncVariantImageButton = (row) => {
        const ref = rowField(row, 'product_image_ref')?.value || '';
        const trigger = select('[data-variant-image-trigger]', row);
        const thumb = select('[data-variant-image-thumb]', row);
        const label = select('[data-variant-image-label]', row);
        const image = imageLibraryItems().find((item) => item.dataset.imageRef === ref);
        if (thumb) {
            thumb.innerHTML = '';
            if (image?.dataset.imageUrl) {
                const img = document.createElement('img');
                img.src = image.dataset.imageUrl;
                img.alt = image.dataset.imageLabel || 'Assigned product image';
                thumb.appendChild(img);
            }
        }
        if (label) label.textContent = image?.dataset.imageLabel || 'Choose image';
        if (trigger) trigger.dataset.imageRef = ref;
    };

    const syncAllVariantImageButtons = () => variationRows().forEach(syncVariantImageButton);

    const syncBulkImageButton = () => {
        const ref = select('[data-bulk-image-ref]')?.value || '';
        const image = imageLibraryItems().find((item) => item.dataset.imageRef === ref);
        const thumb = select('[data-bulk-image-thumb]');
        const label = select('[data-bulk-image-label]');
        if (thumb) {
            thumb.innerHTML = '';
            if (image?.dataset.imageUrl) {
                const img = document.createElement('img');
                img.src = image.dataset.imageUrl;
                img.alt = image.dataset.imageLabel || 'Selected product image';
                thumb.appendChild(img);
            }
        }
        if (label) label.textContent = image?.dataset.imageLabel || 'Choose image';
    };

    const openImagePicker = (target) => {
        if (!imagePickerDialog) return;
        activeImagePickerTarget = target;
        const currentRef = target === 'bulk'
            ? select('[data-bulk-image-ref]')?.value || ''
            : rowField(target, 'product_image_ref')?.value || '';
        const context = select('[data-image-assignment-context]', imagePickerDialog);
        if (context) context.textContent = target === 'bulk'
            ? 'Choose the existing image to apply to the selected scope.'
            : `Choose an image for ${rowField(target, 'values')?.value || 'this variant'}.`;
        const options = select('[data-image-picker-options]', imagePickerDialog);
        if (!options) return;
        options.innerHTML = '';
        const items = imageLibraryItems();
        if (!items.length) {
            options.innerHTML = '<p class="sl-image-picker-empty">Upload a primary or additional product image first.</p>';
        }
        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `sl-image-picker-option${item.dataset.imageRef === currentRef ? ' is-selected' : ''}`;
            button.dataset.imagePickerRef = item.dataset.imageRef || '';
            const image = document.createElement('img');
            image.src = item.dataset.imageUrl || '';
            image.alt = item.dataset.imageLabel || 'Product image';
            const label = document.createElement('span');
            label.textContent = item.dataset.imageLabel || 'Product image';
            button.append(image, label);
            options.appendChild(button);
        });
        if (typeof imagePickerDialog.showModal === 'function') imagePickerDialog.showModal();
        else imagePickerDialog.hidden = false;
    };

    const closeImagePicker = () => {
        if (!imagePickerDialog) return;
        if (typeof imagePickerDialog.close === 'function' && imagePickerDialog.open) imagePickerDialog.close();
        else imagePickerDialog.hidden = true;
        activeImagePickerTarget = null;
    };

    const applyImageReference = (reference) => {
        if (!activeImagePickerTarget) return;
        if (activeImagePickerTarget === 'bulk') {
            const field = select('[data-bulk-image-ref]');
            if (field) field.value = reference || '';
            syncBulkImageButton();
        } else {
            const field = rowField(activeImagePickerTarget, 'product_image_ref');
            if (field) field.value = reference || '';
            syncVariantImageButton(activeImagePickerTarget);
        }
        closeImagePicker();
    };

    const descriptionImageButton = select('[data-description-insert-image]');
    descriptionImageButton?.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        rememberDescriptionSelection();
    });
    descriptionImageButton?.addEventListener('click', () => {
        if (selectAll('[data-description-image-ref]', descriptionContent).length >= 10
            || descriptionUploadFiles.length >= 10) {
            showToast('A product description can include up to 10 pictures.');
            return;
        }
        descriptionImagePicker?.click();
    });
    select('[data-bulk-image-trigger]')?.addEventListener('click', () => openImagePicker('bulk'));
    select('[data-image-picker-close]')?.addEventListener('click', closeImagePicker);
    select('[data-image-picker-clear]')?.addEventListener('click', () => applyImageReference(''));
    select('[data-image-picker-options]')?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-image-picker-ref]');
        if (option) applyImageReference(option.dataset.imagePickerRef || '');
    });
    syncProductImageLibrary();
    renderDescriptionEditor();
    syncDescriptionValue();
    syncAllVariantImageButtons();
    syncBulkImageButton();

    select('[data-generate-variants]')?.addEventListener('click', () => {
        const optionRows = selectAll('[data-option-list] .sl-option-row');
        if (optionRows.length > maxOptionTypes) {
            showToast(`You can generate variations from up to ${maxOptionTypes} variation types.`);
            return;
        }
        const sets = optionRows.map((row) => (select('input[name$="[values]"]', row)?.value || '').split(',').map((value) => value.trim()).filter(Boolean)).filter((set) => set.length);
        if (!sets.length) return;
        const combinations = sets.reduce((rows, set) => rows.flatMap((row) => set.map((value) => [...row, value])), [[]]);
        const list = select('[data-variation-list]');
        if (!list) return;
        let savedVariantPrices = {};
        try { savedVariantPrices = JSON.parse(productForm?.dataset.savedVariantPrices || '{}'); } catch {}
        const labels = select('.sl-variation-labels', list);
        const previous = new Map(variationRows().map((row) => [rowValuesKey(rowField(row, 'values')?.value), row]));
        list.replaceChildren();
        if (labels) list.appendChild(labels);
        combinations.forEach((values, index) => {
            const previousRow = previous.get(rowValuesKey(values.join(', ')));
            const previousValue = (suffix, fallback = '') => rowField(previousRow, suffix)?.value || fallback;
            const previousVariantId = previousValue('id');
            const isActive = previousRow ? previousValue('is_active', '1') : '1';
            const row = document.createElement('div');
            row.className = 'sl-variation-row';
            row.innerHTML = `<label class="sl-variation-check"><input type="checkbox" data-variant-select aria-label="Select variation"></label><input name="variants[${index}][id]" type="hidden" value="${escapeHtml(previousValue('id'))}"><input name="variants[${index}][values]" value="${escapeHtml(values.join(', '))}" aria-label="Option values"><input name="variants[${index}][product_image_ref]" type="hidden" value="${escapeHtml(previousValue('product_image_ref'))}" data-variant-image-ref><button type="button" class="sl-variant-image-button" data-variant-image-trigger aria-label="Choose image for ${escapeHtml(values.join(' / '))}"><span class="sl-variant-image-thumb" data-variant-image-thumb></span><span data-variant-image-label>Choose image</span></button><input name="variants[${index}][sku]" value="${escapeHtml(previousValue('sku'))}" placeholder="Auto-generated SKU" aria-label="Variant SKU"><input name="variants[${index}][price]" type="number" min="0" step="0.01" value="${escapeHtml(previousValue('price'))}" placeholder="Price" aria-label="Variant price"><input name="variants[${index}][stock]" type="number" min="0" value="${escapeHtml(previousValue('stock'))}" placeholder="Stock" aria-label="Variant stock"><input name="variants[${index}][weight_kg]" type="number" min="0.001" max="9999999.999" step="0.001" value="${escapeHtml(previousValue('weight_kg'))}" placeholder="kg" aria-label="Weight per item in kilograms"><select name="variants[${index}][payment_method]" aria-label="Payment method"><option value="cod_online" ${previousValue('payment_method', 'cod_online') === 'cod_online' ? 'selected' : ''}>COD + Online</option><option value="cod" ${previousValue('payment_method') === 'cod' ? 'selected' : ''}>COD</option><option value="online" ${previousValue('payment_method') === 'online' ? 'selected' : ''}>Online</option></select><select name="variants[${index}][is_active]" aria-label="Availability"><option value="1" ${isActive === '1' ? 'selected' : ''}>Available</option><option value="0" ${isActive === '0' ? 'selected' : ''}>Unavailable</option></select><button type="button" class="sl-icon-btn" data-remove-variant>&times;</button>`;
            const generatedPriceField = select('[name$="[price]"]', row);
            if (generatedPriceField) generatedPriceField.dataset.savedPrice = String(savedVariantPrices[previousVariantId] ?? '');
            const previousPriceField = rowField(previousRow, 'price');
            if (generatedPriceField && previousPriceField) {
                ['marketingDiscountType', 'marketingDiscountValue', 'marketingDiscountActive', 'marketingFlashPrice', 'marketingFlashActive', 'marketingFlashRemaining']
                    .forEach((key) => { generatedPriceField.dataset[key] = previousPriceField.dataset[key] || ''; });
            }
            list.appendChild(row);
        });
        syncAllVariantImageButtons();
        productForm?.dispatchEvent(new Event('input', { bubbles: true }));
    });

    const applyBulkChanges = () => {
        const scope = select('[data-bulk-scope]')?.value || 'all';
        const rows = variationRows().filter((row) => scope === 'all' || select('[data-variant-select]', row)?.checked);
        const payment = select('[data-bulk-payment]')?.value || 'cod_online';
        const active = select('[data-bulk-active]')?.value || '1';
        const imageRef = select('[data-bulk-image-ref]')?.value || '';
        rows.forEach((row) => {
            const method = rowField(row, 'payment_method');
            const availability = rowField(row, 'is_active');
            const image = rowField(row, 'product_image_ref');
            if (method) method.value = payment;
            if (availability) availability.value = active;
            if (image && imageRef) image.value = imageRef;
            syncVariantImageButton(row);
        });
        productForm?.dispatchEvent(new Event('input', { bubbles: true }));
        window.slShowToast?.(`${rows.length} variation${rows.length === 1 ? '' : 's'} updated.`);
    };

    select('[data-apply-bulk]')?.addEventListener('click', applyBulkChanges);
    select('[data-add-variation]')?.addEventListener('click', () => {
        const list = select('[data-variation-list]');
        if (!list) return;
        const row = document.createElement('div');
        row.className = 'sl-variation-row';
        const defaultWeight = document.querySelector('[name="weight_grams"]')?.value || '';
        row.innerHTML = `<input name="variation_name[]" placeholder="e.g. Color" aria-label="Variation name"><input name="variation_value[]" placeholder="e.g. Navy Blue" aria-label="Variation option"><input name="variation_sku[]" placeholder="SKU" aria-label="Variation SKU"><input name="variation_price[]" type="number" min="0" step="0.01" placeholder="Price" aria-label="Variation price"><input name="variation_stock[]" type="number" min="0" aria-label="Variation stock"><input name="variation_weight_grams[]" type="number" min="1" value="${defaultWeight}" placeholder="Weight (g)" aria-label="Weight of one unit in grams"><button type="button" class="sl-icon-btn" data-remove-row aria-label="Remove variation">&times;</button>`;
        list.appendChild(row);
        select('input', row)?.focus();
    });

    document.addEventListener('click', (event) => {
        const removeDescriptionImage = event.target.closest('[data-description-image-remove]');
        if (removeDescriptionImage) {
            removeDescriptionImage.closest('[data-description-image-ref]')?.remove();
            syncDescriptionValue();
            descriptionContent?.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const variantImageTrigger = event.target.closest('[data-variant-image-trigger]');
        if (variantImageTrigger) {
            openImagePicker(variantImageTrigger.closest('.sl-variation-row'));
            return;
        }
        const removeButton = event.target.closest('[data-remove-row]');
        if (removeButton) removeButton.closest('.sl-variation-row')?.remove();
        const removeOption = event.target.closest('[data-remove-option]');
        if (removeOption) {
            removeOption.closest('.sl-option-row')?.remove();
            syncOptionTypeLimit();
        }
        const removeVariant = event.target.closest('[data-remove-variant]');
        if (removeVariant) removeVariant.closest('.sl-variation-row')?.remove();
        const removeSavedImage = event.target.closest('[data-remove-saved-image]');
        if (removeSavedImage) {
            const image = removeSavedImage.closest('[data-saved-image]');
            const imageId = removeSavedImage.dataset.imageId;
            if (!image || !imageId) return;
            const existing = image.querySelector('input[name="delete_image_ids[]"]');
            if (existing) {
                existing.remove();
                image.classList.remove('is-removing');
                removeSavedImage.textContent = 'Remove';
            } else {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'delete_image_ids[]';
                hidden.value = imageId;
                image.appendChild(hidden);
                image.classList.add('is-removing');
                removeSavedImage.textContent = 'Keep';
                clearVariantImageAssignments(`image:${imageId}`);
                removeDescriptionImageReference(`image:${imageId}`);
                showToast('Assignments using this image were cleared. Choose another image if needed.');
            }
            syncProductImageLibrary();
            select('.sl-editor-layout')?.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const removeNewImage = event.target.closest('[data-remove-new-product-image]');
        if (removeNewImage) {
            const kind = removeNewImage.dataset.uploadKind;
            const index = Number(removeNewImage.dataset.uploadIndex || 0);
            const ref = kind === 'primary' ? 'upload:primary' : `upload:additional:${index}`;
            remapDescriptionUploadAfterRemoval(kind, index);
            if (kind === 'additional') {
                variationRows().forEach((row) => {
                    const field = rowField(row, 'product_image_ref');
                    if (!field) return;
                    if (field.value === ref) field.value = '';
                    else if (field.value.startsWith('upload:additional:')) {
                        const assignedIndex = Number(field.value.split(':').pop());
                        if (assignedIndex > index) field.value = `upload:additional:${assignedIndex - 1}`;
                    }
                    syncVariantImageButton(row);
                });
            } else {
                clearVariantImageAssignments(ref);
            }
            removeFileFromInput(kind === 'primary' ? '[data-image-input]' : '[data-images-input]', index);
        }
    });

    const setInventoryExpanded = (product, expanded, updateUrl = true) => {
        const button = select('[data-inventory-toggle]', product);
        const panel = select('[data-inventory-variants]', product);
        if (!button || !panel) return;

        button.setAttribute('aria-expanded', String(expanded));
        panel.hidden = !expanded;
        const label = select('[data-inventory-toggle-label]', button);
        if (label) label.textContent = `${expanded ? 'Collapse' : 'Manage'} variants`;
        const icon = select('.sl-inventory-toggle__icon', button);
        if (icon) icon.textContent = expanded ? '−' : '+';

        if (updateUrl && window.history?.replaceState) {
            const url = new URL(window.location.href);
            if (expanded) url.searchParams.set('expanded_product', product.dataset.productId || '');
            else if (url.searchParams.get('expanded_product') === product.dataset.productId) url.searchParams.delete('expanded_product');
            window.history.replaceState({}, '', url);
        }
    };

    selectAll('[data-inventory-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const product = button.closest('[data-inventory-product]');
            if (product) setInventoryExpanded(product, button.getAttribute('aria-expanded') !== 'true');
        });
    });

    const filterProductRows = () => {
        const searchInput = select('[data-product-search]');
        const statusFilter = select('[data-product-status-filter]');
        const rawQuery = (searchInput?.value || '').trim();
        const query = rawQuery.toLowerCase();
        const status = statusFilter?.value || 'all';
        const previewLink = select('[data-inventory-preview]');
        if (previewLink) {
            const previewUrl = new URL(previewLink.href, window.location.origin);
            if (rawQuery) previewUrl.searchParams.set('search', rawQuery);
            else previewUrl.searchParams.delete('search');
            if (status !== 'all') previewUrl.searchParams.set('status', status);
            else previewUrl.searchParams.delete('status');
            previewLink.href = `${previewUrl.pathname}${previewUrl.search}`;
        }

        const inventoryContainer = select('[data-inventory-groups]');
        if (inventoryContainer) {
            const products = selectAll('[data-inventory-product]', inventoryContainer);
            let visibleProducts = 0;

            products.forEach((product) => {
                const productMatches = !query || (product.dataset.productName || '').includes(query);
                const variants = selectAll('[data-inventory-variant]', product);
                let visibleVariants = 0;
                variants.forEach((variant) => {
                    const matchesSearch = !query || productMatches || (variant.dataset.variantSearch || '').includes(query);
                    const matchesStatus = status === 'all' || variant.dataset.productStatus === status;
                    const show = matchesSearch && matchesStatus;
                    variant.hidden = !show;
                    if (show) visibleVariants += 1;
                });

                const showProduct = visibleVariants > 0;
                product.hidden = !showProduct;
                if (showProduct) visibleProducts += 1;

                // A variant/SKU match is specific enough to open its product group automatically.
                if (showProduct && query && !productMatches) setInventoryExpanded(product, true);
            });

            const empty = select('[data-product-empty]');
            if (empty) empty.hidden = products.length === 0 || visibleProducts > 0;
            return;
        }

        const rows = selectAll('[data-product-row]');
        let visible = 0;
        rows.forEach((row) => {
            const matchesSearch = !query || (row.dataset.productName || '').includes(query);
            const matchesStatus = status === 'all' || row.dataset.productStatus === status;
            const show = matchesSearch && matchesStatus;
            row.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-product-empty]');
        if (empty) empty.hidden = visible > 0;
    };

    select('[data-product-search]')?.addEventListener('input', filterProductRows);
    select('[data-product-status-filter]')?.addEventListener('change', filterProductRows);

    const orderSearch = select('[data-order-search]');
    orderSearch?.addEventListener('input', () => {
        const query = orderSearch.value.trim().toLowerCase();
        const cards = selectAll('[data-order-card]');
        let visible = 0;
        cards.forEach((card) => {
            const show = !query || (card.dataset.search || '').includes(query);
            card.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-order-empty]');
        if (empty) empty.hidden = visible > 0;
    });
    if (orderSearch?.value.trim()) orderSearch.dispatchEvent(new Event('input'));

    const filterConversations = () => {
        const query = (select('[data-conversation-search]')?.value || '').trim().toLowerCase();
        const activeFilter = select('[data-conversation-filter].is-active')?.dataset.conversationFilter || 'all';
        selectAll('[data-conversation]').forEach((item) => {
            const matchesQuery = !query || (item.dataset.search || '').includes(query);
            const matchesFilter = activeFilter === 'all' || (activeFilter === 'unread' && item.dataset.unread === 'true') || (activeFilter === 'orders' && item.dataset.order === 'true');
            item.hidden = !(matchesQuery && matchesFilter);
        });
    };

    select('[data-conversation-search]')?.addEventListener('input', filterConversations);
    selectAll('[data-conversation-filter]').forEach((button) => button.addEventListener('click', () => {
        selectAll('[data-conversation-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
        filterConversations();
    }));

    const filterNotifications = (type) => {
        let visible = 0;
        selectAll('[data-notification]').forEach((item) => {
            const show = type === 'all' || item.dataset.type === type;
            item.hidden = !show;
            if (show) visible += 1;
        });
        const empty = select('[data-notification-empty]');
        if (empty) empty.hidden = visible > 0;
    };

    selectAll('[data-notification-filter]').forEach((button) => button.addEventListener('click', () => {
        selectAll('[data-notification-filter]').forEach((item) => item.classList.toggle('is-active', item === button));
        filterNotifications(button.dataset.notificationFilter);
    }));

    select('[data-mark-all-read]')?.addEventListener('click', () => {
        selectAll('[data-notification].is-unread').forEach((item) => {
            item.classList.remove('is-unread');
            Array.from(item.children).find((child) => child.tagName === 'I')?.remove();
        });
        showToast('All notifications marked as read.');
    });
});
