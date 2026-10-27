import './echo.js';
import { startRealtimeFallback } from './realtime-fallback.js';
import '../../css/shared/messages.css';

const MAX_MESSAGE_ATTACHMENT_BYTES = 5 * 1024 * 1024;
const OPTIMIZABLE_MESSAGE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);

const optimizeMessageImage = async (file) => {
    if (!OPTIMIZABLE_MESSAGE_IMAGE_TYPES.has(file.type)
        || typeof window.createImageBitmap !== 'function'
        || typeof window.File !== 'function') return file;

    let bitmap;
    try {
        bitmap = await window.createImageBitmap(file);
    } catch {
        return file;
    }

    try {
        const width = bitmap.width;
        const height = bitmap.height;
        if (Math.max(width, height) <= 1600 && file.size <= 400 * 1024) return file;

        const scale = Math.min(1, 1600 / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        const context = canvas.getContext('2d');
        if (!context) return file;
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/webp', 0.82));
        if (!blob || blob.type !== 'image/webp' || blob.size >= file.size) return file;

        const baseName = file.name.replace(/\.[^.]+$/, '').trim() || 'image';
        return new File([blob], `${baseName}.webp`, {
            type: 'image/webp',
            lastModified: file.lastModified,
        });
    } finally {
        bitmap.close?.();
    }
};

window.lkOptimizeMessageAttachments = async (files) => {
    if (files.length > 5) throw new Error('Choose up to 5 files or images per message.');

    const optimized = [];
    for (const file of files) {
        const prepared = await optimizeMessageImage(file);
        if (prepared.size > MAX_MESSAGE_ATTACHMENT_BYTES) {
            throw new Error(`${file.name} is larger than 5 MB. Choose a smaller file or image.`);
        }
        optimized.push(prepared);
    }
    return optimized;
};

const messagePhotoObserver = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const image = entry.target;
            const frame = image.closest('[data-message-photo-frame]');
            if (frame && (image.dataset.src || !image.complete)) frame.classList.add('is-loading');
            if (!image.getAttribute('src') && image.dataset.src) image.src = image.dataset.src;
            messagePhotoObserver.unobserve(image);
        });
    }, { rootMargin: '120px 0px' })
    : null;

const bindMessagePhoto = (image) => {
    if (image.dataset.messagePhotoBound === 'true') return;
    image.dataset.messagePhotoBound = 'true';
    const frame = image.closest('[data-message-photo-frame]');
    if (!frame) return;

    image.addEventListener('load', () => {
        frame.classList.remove('is-loading', 'is-error');
        frame.classList.add('is-loaded');
    }, { once: true });
    image.addEventListener('error', () => {
        frame.classList.remove('is-loading', 'is-loaded');
        frame.classList.add('is-error');
    }, { once: true });

    if (image.getAttribute('src') && image.complete) {
        if (image.naturalWidth > 0) frame.classList.add('is-loaded');
        else frame.classList.add('is-error');
    } else if (messagePhotoObserver) {
        messagePhotoObserver.observe(image);
    } else {
        frame.classList.add('is-loading');
        if (image.dataset.src) image.src = image.dataset.src;
    }
};

document.querySelectorAll('[data-message-photo]').forEach(bindMessagePhoto);

const formatMessageTime = (payload) => {
    if (payload.time) return payload.time;
    if (payload.sent_at) return new Date(payload.sent_at).toLocaleTimeString('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    });
    return 'Just now';
};

const createMessageAttachment = (payload) => {
    if (!payload?.attachment_url) return null;

    const name = String(payload.attachment_name || 'Attached file').slice(0, 180);
    const mime = String(payload.attachment_mime || '').toLowerCase();
    const extension = name.split('.').pop()?.toLowerCase() || '';
    const isImage = ['image/jpeg', 'image/png', 'image/webp'].includes(mime)
        || (['', 'application/octet-stream'].includes(mime) && ['jpg', 'jpeg', 'png', 'webp'].includes(extension));

    if (isImage) {
        const frame = document.createElement('span');
        frame.className = 'lk-message-image-frame';
        frame.dataset.messageAttachment = 'true';
        frame.dataset.messagePhotoFrame = 'true';
        const placeholder = document.createElement('span');
        placeholder.className = 'lk-message-photo-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');
        const image = document.createElement('img');
        image.className = 'lk-message-image-attachment';
        image.dataset.messageAttachment = 'true';
        image.dataset.messagePhoto = 'true';
        image.alt = `Attached image: ${name}`;
        image.loading = 'lazy';
        image.decoding = 'async';
        image.fetchPriority = 'low';
        frame.append(placeholder, image);
        bindMessagePhoto(image);
        image.dataset.src = payload.attachment_url;
        return frame;
    }

    const link = document.createElement('a');
    link.className = 'lk-message-file';
    link.dataset.messageAttachment = 'true';
    link.href = payload.attachment_url;
    link.download = name;
    link.setAttribute('aria-label', `Download ${name}`);

    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    icon.setAttribute('viewBox', '0 0 24 24');
    icon.setAttribute('fill', 'none');
    icon.setAttribute('stroke', 'currentColor');
    icon.setAttribute('stroke-width', '1.8');
    icon.setAttribute('aria-hidden', 'true');
    icon.innerHTML = '<path d="M13 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10z"/><path d="M13 3v7h7M8 15h8M8 18h5"/>';
    link.appendChild(icon);

    const copy = document.createElement('span');
    copy.className = 'lk-message-file-copy';
    const title = document.createElement('strong');
    title.textContent = name;
    const type = document.createElement('small');
    type.textContent = `${extension ? extension.toUpperCase() : 'FILE'} attachment`;
    copy.append(title, type);
    link.appendChild(copy);

    const download = document.createElement('span');
    download.className = 'lk-message-file-download';
    download.setAttribute('aria-hidden', 'true');
    download.textContent = '↓';
    link.appendChild(download);
    return link;
};

window.lkCreateMessageAttachment = createMessageAttachment;

const receiptFor = (thread, row) => {
    let receipt = row.querySelector('[data-message-receipt]');
    if (receipt) return receipt;

    const time = row.querySelector('.lk-message-time');
    let meta = time?.parentElement?.classList.contains('lk-message-meta') ? time.parentElement : null;
    if (!meta) {
        meta = document.createElement('div');
        meta.className = 'lk-message-meta';
        if (time) {
            time.after(meta);
            meta.appendChild(time);
        } else {
            row.querySelector('.lk-message-bubble')?.after(meta);
        }
    }

    receipt = document.createElement('span');
    receipt.className = 'lk-message-receipt';
    receipt.dataset.messageReceipt = 'true';
    const text = document.createElement('span');
    text.dataset.messageReceiptText = 'true';
    receipt.appendChild(text);
    meta.appendChild(receipt);
    return receipt;
};

const setMessageReceipt = (thread, row, state) => {
    const receipt = receiptFor(thread, row);
    receipt.dataset.receiptState = state;
    receipt.dataset.messageSentAt = row.dataset.messageSentAt || '';

    let text = receipt.querySelector('[data-message-receipt-text]');
    if (!text) {
        text = document.createElement('span');
        text.dataset.messageReceiptText = 'true';
        receipt.appendChild(text);
    }
    text.textContent = state === 'seen' ? 'Seen'
        : state === 'delivered' ? 'Delivered'
            : state === 'sending' ? 'Sending'
                : 'Sent';
    receipt.setAttribute(
        'aria-label',
        state === 'seen' ? 'Seen by ' + (thread.dataset.otherName || 'recipient') : text.textContent,
    );

    let avatar = receipt.querySelector('[data-message-receipt-avatar]');
    if (state === 'seen') {
        if (!avatar) {
            avatar = document.createElement('img');
            avatar.className = 'lk-message-seen-avatar';
            avatar.dataset.messageReceiptAvatar = 'true';
            avatar.alt = '';
            receipt.appendChild(avatar);
        }
        avatar.src = thread.dataset.otherAvatar || '';
        avatar.title = 'Seen by ' + (thread.dataset.otherName || 'recipient');
    } else {
        avatar?.remove();
    }
};

const updateMessageReceipts = (thread) => {
    const lastReadAt = Date.parse(thread.dataset.otherLastReadAt || '');
    const recipientOnline = thread.dataset.otherOnline === 'true';
    const rows = [...thread.querySelectorAll('.lk-message-row.is-mine, .lk-message-row.is-buyer')];
    const seenRows = [];

    rows.forEach((row) => {
        if (row.dataset.messagePending === 'true') {
            setMessageReceipt(thread, row, 'sending');
            row.dataset.messageReceiptState = 'sending';
            return;
        }

        const sentAt = Date.parse(row.dataset.messageSentAt || row.querySelector('[data-message-receipt]')?.dataset.messageSentAt || '');
        const wasSeen = Number.isFinite(lastReadAt) && Number.isFinite(sentAt) && sentAt <= lastReadAt;
        const state = wasSeen ? 'seen' : (recipientOnline ? 'delivered' : 'sent');
        setMessageReceipt(thread, row, state);
        row.dataset.messageReceiptState = state;
        if (wasSeen) seenRows.push(row);
    });

    const latestSeenRow = seenRows.reduce((latest, row) => {
        if (!latest) return row;
        const sentAt = Date.parse(row.dataset.messageSentAt || '');
        const latestSentAt = Date.parse(latest.dataset.messageSentAt || '');
        const messageId = Number(row.dataset.messageId) || 0;
        const latestMessageId = Number(latest.dataset.messageId) || 0;
        return sentAt > latestSentAt || (sentAt === latestSentAt && messageId > latestMessageId) ? row : latest;
    }, null);

    rows.forEach((row) => {
        const state = row.dataset.messageReceiptState || 'sent';
        const isLatestSeen = state === 'seen' && row === latestSeenRow;
        const isExpanded = row.dataset.receiptExpanded === 'true';
        const isCollapsedSeen = state === 'seen' && !isLatestSeen && !isExpanded;
        const receipt = row.querySelector('[data-message-receipt]');
        const meta = row.querySelector('.lk-message-meta');

        if (receipt) receipt.hidden = isCollapsedSeen;
        row.dataset.receiptLatestSeen = isLatestSeen ? 'true' : 'false';
        row.classList.toggle('has-collapsed-seen-receipt', isCollapsedSeen);

        if (state === 'seen' && !isLatestSeen && meta) {
            meta.setAttribute('role', 'button');
            meta.setAttribute('tabindex', '0');
            meta.setAttribute('aria-label', isExpanded ? 'Hide seen status for this message' : 'Show seen status for this message');
            meta.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
            meta.setAttribute('title', isExpanded ? 'Click to hide Seen status' : 'Click to show Seen status');
        } else if (meta) {
            meta.removeAttribute('role');
            meta.removeAttribute('tabindex');
            meta.removeAttribute('aria-label');
            meta.removeAttribute('aria-pressed');
            meta.removeAttribute('title');
        }
    });
};

const toggleOlderSeenReceipt = (thread, row) => {
    if (row.dataset.messageReceiptState !== 'seen' || row.dataset.receiptLatestSeen === 'true') return;

    row.dataset.receiptExpanded = row.dataset.receiptExpanded === 'true' ? 'false' : 'true';
    updateMessageReceipts(thread);
};

const bindOlderSeenReceiptToggles = (thread) => {
    if (thread.dataset.seenReceiptToggleBound === 'true') return;
    thread.dataset.seenReceiptToggleBound = 'true';

    thread.addEventListener('click', (event) => {
        const row = event.target.closest('.lk-message-row.is-mine, .lk-message-row.is-buyer');
        if (!row || !thread.contains(row) || event.target.closest('a, button, input, select, textarea')) return;
        toggleOlderSeenReceipt(thread, row);
    });

    thread.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const meta = event.target.closest('.lk-message-meta[role="button"]');
        const row = meta?.closest('.lk-message-row.is-mine, .lk-message-row.is-buyer');
        if (!row || !thread.contains(row)) return;
        event.preventDefault();
        toggleOlderSeenReceipt(thread, row);
    });
};

const pendingMessageFor = (thread, payload) => {
    const body = String(payload.body || '').trim();
    const senderId = String(payload.sender_id || payload.sender_user_id || '');
    const hasAttachment = Boolean(payload.attachment_url);
    if ((!body && !hasAttachment) || !senderId) return null;

    return [...thread.querySelectorAll('[data-message-pending="true"]')].find((row) => {
        const rowBody = row.querySelector('.lk-message-bubble')?.textContent?.trim() || '';
        const bodyMatches = rowBody === body;
        const attachmentMatches = body !== '' || row.dataset.messageAttachmentPending === 'true';
        return bodyMatches && attachmentMatches && String(row.dataset.messageSenderId || '') === senderId;
    }) || null;
};

const reconcilePendingMessage = (thread, payload) => {
    const pending = pendingMessageFor(thread, payload);
    if (!pending || !payload.id) return false;

    pending.dataset.messageId = String(payload.id);
    pending.dataset.messagePending = 'false';
    if (payload.sent_at) pending.dataset.messageSentAt = payload.sent_at;
    if (thread.dataset.attachmentsEnabled === 'true' && payload.attachment_url
        && !pending.querySelector('[data-message-attachment="true"]')) {
        const attachment = createMessageAttachment(payload);
        if (attachment) pending.querySelector('.lk-message-bubble')?.after(attachment);
    }
    pending.querySelector('.lk-message-time').textContent = formatMessageTime(payload);
    updateMessageReceipts(thread);
    pollConversationReceipt(thread);
    thread.dataset.lastMessageId = String(payload.id);
    return true;
};

const removePendingMessage = (thread, pendingId) => {
    thread.querySelector(`[data-message-id="${pendingId}"]`)?.remove();
};

const appendRealtimeMessage = (thread, payload, { pending = false } = {}) => {
    if (!pending && reconcilePendingMessage(thread, payload)) return;

    const id = String(payload.id || '');
    if (!id || [...thread.querySelectorAll('[data-message-id]')].some((row) => row.dataset.messageId === id)) return;

    const currentUserId = String(thread.dataset.currentUserId || '');
    const isMine = String(payload.sender_id || '') === currentUserId;
    const isBuyer = Boolean(thread.closest('.lk-messages-page'));
    const otherAvatar = thread.dataset.otherAvatar || thread.dataset.sellerAvatar || '';
    const otherName = thread.dataset.otherName || thread.dataset.sellerName || 'User';

    thread.querySelector('.lk-messages-empty')?.remove();
    thread.dataset.lastMessageId = id;

    const row = document.createElement('div');
    row.className = `lk-message-row${isMine ? (isBuyer ? ' is-buyer' : ' is-mine') : ''}`;
    row.dataset.messageId = id;
    row.dataset.messageSenderId = String(payload.sender_id || payload.sender_user_id || '');
    if (payload.sent_at) row.dataset.messageSentAt = payload.sent_at;
    else if (pending) row.dataset.messageSentAt = new Date().toISOString();
    if (pending) row.dataset.messagePending = 'true';
    if (pending && payload.attachment_pending) row.dataset.messageAttachmentPending = 'true';

    if (!isMine) {
        const avatar = document.createElement('img');
        avatar.className = 'lk-message-small-avatar';
        avatar.src = otherAvatar;
        avatar.alt = otherName;
        avatar.loading = 'lazy';
        avatar.decoding = 'async';
        row.appendChild(avatar);
    }

    const wrapper = document.createElement('div');
    const bubble = document.createElement('div');
    bubble.className = 'lk-message-bubble';
    const body = String(payload.body || '').trim();
    bubble.textContent = body;
    bubble.hidden = !body || (Boolean(payload.attachment_url) && ['[Photo]', '[File]'].includes(body));
    wrapper.appendChild(bubble);
    if (thread.dataset.attachmentsEnabled === 'true' && payload.attachment_url) {
        const attachment = createMessageAttachment(payload);
        if (attachment) wrapper.appendChild(attachment);
    }
    const time = document.createElement('span');
    time.className = 'lk-message-time';
    time.textContent = pending ? '' : formatMessageTime(payload);
    const meta = document.createElement('div');
    meta.className = 'lk-message-meta';
    meta.appendChild(time);
    wrapper.appendChild(meta);
    row.appendChild(wrapper);
    const smartHelp = thread.querySelector('[data-smart-help]');
    if (smartHelp) thread.insertBefore(row, smartHelp);
    else thread.appendChild(row);
    if (isMine) {
        updateMessageReceipts(thread);
        pollConversationReceipt(thread);
    }
    thread.scrollTop = thread.scrollHeight;
};

const setConnectionStatus = (thread, message, error = false) => {
    const feedback = thread.closest('.lk-chat-area')?.querySelector('[data-message-feedback]');
    if (!feedback) return;
    feedback.textContent = message;
    feedback.hidden = !message;
    feedback.classList.toggle('is-error', error);
};

const setPresenceStatus = (thread, online) => {
    const status = thread.closest('.lk-chat-area')?.querySelector('[data-message-presence-status]');
    if (!status) return;
    status.classList.toggle('is-offline', !online);
    status.dataset.presence = online ? 'online' : 'offline';
    const label = status.querySelector('[data-message-presence-label]');
    if (label) label.textContent = online ? 'Online' : 'Offline';
};

const markConversationRead = async (thread) => {
    const readUrl = thread.dataset.readUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!readUrl || !csrfToken) return;
    if (thread.dataset.readRequestInFlight === 'true') {
        thread.dataset.readRequestPending = 'true';
        return;
    }

    thread.dataset.readRequestInFlight = 'true';
    try {
        const response = await fetch(readUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        if (!response.ok) throw new Error(`Read state update failed with status ${response.status}.`);
    } catch (error) {
        setConnectionStatus(thread, error?.message || 'Read state could not be updated.', true);
    } finally {
        thread.dataset.readRequestInFlight = 'false';
        if (thread.dataset.readRequestPending === 'true') {
            delete thread.dataset.readRequestPending;
            markConversationRead(thread);
        }
    }
};

const acknowledgeIncomingMessage = (thread, payload) => {
    const senderId = String(payload?.sender_id || payload?.sender_user_id || '');
    const currentUserId = String(thread.dataset.currentUserId || '');
    const messageId = Number(payload?.id || 0);
    if (!senderId || senderId === currentUserId || messageId < 1) return;

    const lastAcknowledgedId = Number(thread.dataset.lastAcknowledgedMessageId || 0);
    if (messageId <= lastAcknowledgedId) return;
    thread.dataset.lastAcknowledgedMessageId = String(messageId);
    markConversationRead(thread);
};

const setConversationRowPresence = (row, online) => {
    const dot = row.querySelector('[data-conversation-presence-dot]');
    if (!dot) return;
    const label = online ? 'Online' : 'Offline';
    dot.classList.toggle('is-online', online);
    dot.setAttribute('aria-label', label);
    dot.title = label;
    row.dataset.presence = online ? 'online' : 'offline';
};

const updateConversationRowsPresence = (conversationId, online) => {
    document.querySelectorAll('[data-conversation-presence-id]').forEach((row) => {
        if (String(row.dataset.conversationPresenceId) === String(conversationId)) {
            setConversationRowPresence(row, online);
        }
    });
};

const pollConversationListPresence = async () => {
    if (document.hidden) return;

    const activeConversationIds = new Set(
        [...document.querySelectorAll('.lk-chat-stream[data-conversation-id]')]
            .map((thread) => String(thread.dataset.conversationId || '')),
    );
    const rows = [...document.querySelectorAll('[data-conversation-presence-id][data-presence-url]')]
        .filter((row) => {
            if (activeConversationIds.has(String(row.dataset.conversationPresenceId || '')) || !row.getClientRects().length) {
                return false;
            }

            const list = row.closest('.lk-conversation-list');
            if (!list) return false;
            const rowBounds = row.getBoundingClientRect();
            const listBounds = list.getBoundingClientRect();

            return rowBounds.bottom > Math.max(0, listBounds.top)
                && rowBounds.top < Math.min(window.innerHeight, listBounds.bottom)
                && rowBounds.right > 0
                && rowBounds.left < window.innerWidth;
        });

    await Promise.all(rows.map(async (row) => {
        if (row.dataset.presencePolling === 'true') return;
        row.dataset.presencePolling = 'true';
        try {
            const response = await fetch(row.dataset.presenceUrl, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
            });
            const payload = await response.json();
            if (response.ok && payload.success === true) {
                setConversationRowPresence(row, payload.recipient_online === true);
            }
        } catch (_) {
            // Keep the last known presence while the connection retries.
        } finally {
            row.dataset.presencePolling = 'false';
        }
    }));
};

const startConversationListPresencePolling = () => {
    if (!document.querySelector('[data-conversation-presence-id][data-presence-url]')) return;
    pollConversationListPresence();
    window.setInterval(pollConversationListPresence, 15000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') pollConversationListPresence();
    });
};

const pollConversationReceipt = async (thread) => {
    if (document.hidden) return;

    const receiptUrl = thread.dataset.receiptUrl;
    if (!receiptUrl) return;

    try {
        const response = await fetch(receiptUrl, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
        const payload = await response.json();
        if (!response.ok || payload.success !== true) return;
        thread.dataset.otherOnline = payload.recipient_online ? 'true' : 'false';
        thread.dataset.otherLastReadAt = payload.recipient_read_at || '';
        setPresenceStatus(thread, payload.recipient_online === true);
        updateConversationRowsPresence(thread.dataset.conversationId, payload.recipient_online === true);
        updateMessageReceipts(thread);
    } catch (_) {
        // Keep the last known receipt while the connection retries.
    }
};

const startPresenceHeartbeat = () => {
    if (document.body.dataset.messagePresenceBound === 'true') return;
    const presenceUrl = window.LIKHAE_MESSAGE_PRESENCE_URL || '/messages/presence';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrfToken) return;
    document.body.dataset.messagePresenceBound = 'true';

    const touchPresence = () => {
        if (document.hidden) return;

        fetch(presenceUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
            credentials: 'same-origin',
            cache: 'no-store',
        }).catch(() => {});
    };

    touchPresence();
    window.setInterval(touchPresence, 20000);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') touchPresence();
    });
};

const clearAttachmentSelection = (form) => {
    const attachmentInput = form.querySelector('input[name="attachments[]"], input[name="attachment"]');
    const preview = form.querySelector('[data-attachment-preview]');
    const filename = form.querySelector('[data-attachment-filename]');
    if (preview?.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
    if (preview) {
        delete preview.dataset.previewUrl;
        preview.hidden = true;
        preview.removeAttribute('src');
    }
    if (filename) {
        filename.hidden = true;
        filename.textContent = '';
    }
    if (attachmentInput) attachmentInput.value = '';
};

const sameAttachmentSelection = (input, selectedFiles) => {
    const currentFiles = [...(input?.files || [])];
    return currentFiles.length === selectedFiles.length && selectedFiles.every((file, index) =>
        file.name === currentFiles[index].name
        && file.size === currentFiles[index].size
        && file.lastModified === currentFiles[index].lastModified,
    );
};

const subscribeToThread = (thread) => {
    const conversationId = thread.dataset.conversationId;
    if (!conversationId) return;
    bindOlderSeenReceiptToggles(thread);

    if (!thread.dataset.lastMessageId) {
        const lastMessageId = [...thread.querySelectorAll('[data-message-id]')]
            .map((row) => Number(row.dataset.messageId) || 0)
            .reduce((highest, id) => Math.max(highest, id), 0);
        thread.dataset.lastMessageId = String(lastMessageId);
    }

    if (window.Echo && thread.dataset.realtimeSubscribed !== 'true') {
        thread.dataset.realtimeSubscribed = 'true';
        window.Echo.private(`conversations.${conversationId}`)
            .listen('.message.sent', (payload) => {
                appendRealtimeMessage(thread, payload);
                acknowledgeIncomingMessage(thread, payload);
            })
            .listen('.conversation.read', (payload) => {
                const readerId = String(payload.reader_user_id || payload.user_id || '');
                if (readerId !== String(thread.dataset.otherUserId || '') || !payload.read_at) return;
                thread.dataset.otherLastReadAt = payload.read_at;
                updateMessageReceipts(thread);
            });
    }

    const connection = window.Echo?.connector?.pusher?.connection;
    if (connection && thread.dataset.connectionStatusBound !== 'true') {
        thread.dataset.connectionStatusBound = 'true';
        connection.bind('connected', () => setConnectionStatus(thread, ''));
        connection.bind('disconnected', () => setConnectionStatus(thread, 'Reconnecting; fallback active', true));
        connection.bind('unavailable', () => setConnectionStatus(thread, 'Fallback polling active', true));
        setConnectionStatus(
            thread,
            connection.state === 'connected' ? '' : 'Connecting; fallback active',
            connection.state !== 'connected',
        );
    }

    updateMessageReceipts(thread);
    if (thread.dataset.receiptPollBound !== 'true') {
        thread.dataset.receiptPollBound = 'true';
        pollConversationReceipt(thread);
        window.setInterval(() => pollConversationReceipt(thread), 8000);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') pollConversationReceipt(thread);
        });
    }

    if (thread.dataset.realtimeFallbackBound === 'true' || !thread.dataset.streamUrl) return;
    thread.dataset.realtimeFallbackBound = 'true';
    let polling = false;
    startRealtimeFallback(async () => {
        if (polling) return;
        polling = true;
        let timeout = null;
        try {
            const streamUrl = new URL(thread.dataset.streamUrl, window.location.origin);
            const lastMessageId = Number(thread.dataset.lastMessageId || 0);
            if (lastMessageId > 0) streamUrl.searchParams.set('after_id', String(lastMessageId));
            const controller = new AbortController();
            timeout = window.setTimeout(() => controller.abort(), 8000);
            const response = await fetch(streamUrl.toString(), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
            const payload = await response.json();
            if (!response.ok || !Array.isArray(payload.messages)) return;
            payload.messages.forEach((message) => appendRealtimeMessage(thread, {
                ...message,
                sender_id: message.sender_id ?? message.sender_user_id,
            }));
            payload.messages.forEach((message) => acknowledgeIncomingMessage(thread, message));
            setConnectionStatus(thread, 'Fallback polling active', true);
        } catch (_) {
            // The next fallback attempt will retry.
            setConnectionStatus(thread, 'Message updates temporarily unavailable', true);
        } finally {
            if (timeout !== null) window.clearTimeout(timeout);
            polling = false;
        }
    });
};

const subscribeToUserMessages = () => {
    const thread = document.querySelector('.lk-chat-stream[data-message-thread]');
    const userId = thread?.dataset.currentUserId
        || document.querySelector('[data-current-user-id]')?.dataset.currentUserId
        || window.LIKHAE_NOTIFICATION_SOUND_CONFIG?.userId;
    if (!userId || !window.Echo || document.body.dataset.userMessagesSubscribed === 'true') return;

    document.body.dataset.userMessagesSubscribed = 'true';
    window.Echo.private(`App.Models.User.${userId}`)
        .listen('.message.sent', (payload) => {
            if (String(payload.sender_id || '') === String(userId)) return;
            if (document.body.dataset.messageRedirecting === 'true') return;
            const activeThread = document.querySelector('.lk-chat-stream[data-message-thread]');
            if (activeThread && String(activeThread.dataset.conversationId) === String(payload.conversation_id)) {
                appendRealtimeMessage(activeThread, payload);
                acknowledgeIncomingMessage(activeThread, payload);
                window.likhaePlayNotificationSound?.('MESSAGE');
                return;
            }

            const url = new URL(window.LIKHAE_MESSAGES_URL || window.location.href, window.location.origin);
            if (document.querySelector('.lk-messages-page')) {
                url.searchParams.set('seller', `conversation-${payload.conversation_id}`);
                url.searchParams.delete('conversation');
            } else {
                url.searchParams.set('conversation', payload.conversation_id);
            }
            window.likhaePlayNotificationSound?.('MESSAGE');
            document.body.dataset.messageRedirecting = 'true';
            window.location.assign(url.toString());
        });
};

const bindLiveSend = (form) => {
    if (form.dataset.liveSendBound === 'true') return;
    form.dataset.liveSendBound = 'true';

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (form.dataset.sending === 'true') return;
        const input = form.querySelector('input[name="body"], textarea[name="body"]');
        const attachmentInput = form.querySelector('input[name="attachments[]"], input[name="attachment"]');
        const submittedAttachments = [...(attachmentInput?.files || [])];
        const thread = form.closest('.lk-chat-area')?.querySelector('.lk-chat-stream[data-message-thread]');
        const body = input?.value.trim();
        if ((!body && !submittedAttachments.length) || !thread) return;
        if (submittedAttachments.length > 5) {
            setConnectionStatus(thread, 'Choose up to 5 files or images per message.', true);
            return;
        }

        form.dataset.sending = 'true';
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        submitButton?.setAttribute('aria-busy', 'true');
        const pendingId = `pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        let timeout = null;
        let pendingAdded = false;
        try {
            const hasImages = submittedAttachments.some((file) => OPTIMIZABLE_MESSAGE_IMAGE_TYPES.has(file.type));
            if (hasImages) setConnectionStatus(thread, 'Optimizing image attachments…');
            const preparedAttachments = window.lkOptimizeMessageAttachments
                ? await window.lkOptimizeMessageAttachments(submittedAttachments)
                : submittedAttachments;

            const formData = new FormData(form);
            formData.set('body', body || '');
            if (attachmentInput?.name) {
                formData.delete(attachmentInput.name);
                preparedAttachments.forEach((file) => formData.append(attachmentInput.name, file, file.name));
            }

            appendRealtimeMessage(thread, {
                id: pendingId,
                sender_id: thread.dataset.currentUserId,
                body: body || '',
                attachment_pending: submittedAttachments.length > 0,
            }, { pending: true });
            pendingAdded = true;
            if (submittedAttachments.length) setConnectionStatus(thread, 'Sending attachment…');

            const controller = new AbortController();
            timeout = window.setTimeout(() => controller.abort(), 45000);
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                body: formData,
            });
            const payload = await response.json().catch(() => ({}));
            const sentMessages = Array.isArray(payload.messages)
                ? payload.messages
                : (payload.message ? [payload.message] : []);
            if (!response.ok || sentMessages.length === 0) {
                const validationMessage = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                throw new Error(validationMessage || payload?.message || 'Message could not be sent.');
            }

            sentMessages.forEach((message) => appendRealtimeMessage(thread, message));
            if (input?.value.trim() === body) {
                input.value = '';
                input.dispatchEvent(new Event('input', {bubbles: true}));
            }
            if (sameAttachmentSelection(attachmentInput, submittedAttachments)) clearAttachmentSelection(form);
            setConnectionStatus(thread, 'Message sent');
        } catch (error) {
            if (pendingAdded) removePendingMessage(thread, pendingId);
            setConnectionStatus(thread, error?.message || 'Message could not be sent.', true);
        } finally {
            if (timeout !== null) window.clearTimeout(timeout);
            form.dataset.sending = 'false';
            if (submitButton) submitButton.disabled = false;
            submitButton?.removeAttribute('aria-busy');
        }
    });
};

window.lkSubscribeMessageThread = (thread, payload = null) => {
    if (payload?.conversation_id && !thread.dataset.conversationId) {
        thread.dataset.conversationId = String(payload.conversation_id);
        const readUrlTemplate = thread.dataset.readUrlTemplate;
        if (!thread.dataset.readUrl && readUrlTemplate) {
            thread.dataset.readUrl = readUrlTemplate.replace('__conversation__', String(payload.conversation_id));
        }
        const receiptUrlTemplate = thread.dataset.receiptUrlTemplate;
        if (!thread.dataset.receiptUrl && receiptUrlTemplate) {
            thread.dataset.receiptUrl = receiptUrlTemplate.replace('__conversation__', String(payload.conversation_id));
        }
    }

    subscribeToThread(thread);
};

const initializeConversationSearch = () => {
    document.querySelectorAll('[data-conversation-search]').forEach((input) => {
        const sidebar = input.closest('.lk-messages-sidebar');
        const list = sidebar?.querySelector('.lk-conversation-list');
        if (!list) return;

        const rows = [...list.querySelectorAll('[data-conversation-name]')];
        const emptyState = list.querySelector('[data-conversation-search-empty]');
        const filterConversations = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visibleCount = 0;

            rows.forEach((row) => {
                const name = (row.dataset.conversationName || '').toLocaleLowerCase();
                const matches = !query || name.includes(query);
                row.hidden = !matches;
                if (matches) visibleCount += 1;
            });

            if (emptyState) emptyState.hidden = !query || visibleCount > 0 || rows.length === 0;
        };

        input.addEventListener('input', filterConversations);
        filterConversations();
    });
};

const initializeMessages = () => {
    initializeConversationSearch();
    startPresenceHeartbeat();
    document.querySelectorAll('.lk-chat-stream[data-conversation-id]').forEach(subscribeToThread);
    startConversationListPresencePolling();
    document.querySelectorAll('[data-live-message-form]').forEach(bindLiveSend);
    initializeSmartMessaging();
    subscribeToUserMessages();
};

const initializeSmartMessaging = () => {
    document.querySelectorAll('[data-smart-help]').forEach((panel) => {
        if (panel.dataset.smartHelpBound === 'true') return;
        panel.dataset.smartHelpBound = 'true';
        const status = panel.querySelector('[data-smart-help-status]');
        const feedback = panel.querySelector('[data-smart-help-feedback]');
        const feedbackNote = panel.querySelector('[data-smart-help-feedback-note]');
        const handoffRow = panel.querySelector('[data-smart-help-handoff-row]');
        const conversationInput = panel.closest('.lk-chat-area')?.querySelector('[data-chat-input]');
        const token = panel.querySelector('[data-smart-help-token]')?.value || '';
        let humanHandoff = panel.dataset.humanHandoff === 'true';
        let hasAnswer = panel.dataset.hasAnswer === 'true';
        let busy = false;
        let retryUntil = 0;
        let cooldownTimer = null;

        const controls = () => panel.querySelectorAll('[data-smart-question], [data-smart-feedback], [data-smart-handoff]');
        const coolingDown = () => Date.now() < retryUntil;
        const unlockControls = () => {
            if (busy || humanHandoff || coolingDown()) return;
            panel.querySelectorAll('[data-smart-question]').forEach((button) => { button.disabled = false; });
            panel.querySelectorAll('[data-smart-feedback]').forEach((button) => { button.disabled = !hasAnswer || Boolean(feedback?.hidden); });
            const handoffButton = panel.querySelector('[data-smart-handoff]');
            if (handoffButton) handoffButton.disabled = Boolean(handoffRow?.hidden);
        };
        const lockControls = () => controls().forEach((button) => { button.disabled = true; });

        const setStatus = (message, isError = false) => {
            if (!status) return;
            status.textContent = message;
            status.classList.toggle('lk-smart-help-error', isError);
        };
        const startCooldown = (seconds) => {
            retryUntil = Date.now() + seconds * 1000;
            window.clearTimeout(cooldownTimer);
            const updateCooldown = () => {
                const remaining = Math.max(0, Math.ceil((retryUntil - Date.now()) / 1000));
                if (remaining === 0) {
                    unlockControls();
                    setStatus('You can try the suggested questions again.');
                    return;
                }
                lockControls();
                setStatus(`Please wait ${remaining} seconds before trying again.`, true);
                cooldownTimer = window.setTimeout(updateCooldown, 1000);
            };
            updateCooldown();
        };
        const showError = (error) => {
            if (error.retryAfter) {
                startCooldown(error.retryAfter);
                return;
            }
            setStatus(error.name === 'AbortError'
                ? 'That took too long. Check your connection and try again.'
                : error.message, true);
        };
        const post = async (url, payload = {}) => {
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 20000);
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify(payload),
                });
                const result = await response.json().catch(() => ({}));
                if (response.status === 429) {
                    const requestedDelay = Number(response.headers.get('Retry-After'));
                    const retryAfter = Number.isFinite(requestedDelay) && requestedDelay > 0 ? requestedDelay : 30;
                    const error = new Error('Too many quick requests.');
                    error.retryAfter = retryAfter;
                    throw error;
                }
                if (!response.ok) throw new Error(result.message || 'That action could not be completed. Please try again.');
                return result;
            } finally {
                window.clearTimeout(timeout);
            }
        };
        const setHumanHandoff = (message) => {
            humanHandoff = true;
            panel.dataset.humanHandoff = 'true';
            lockControls();
            if (feedback) feedback.hidden = true;
            if (feedbackNote) feedbackNote.hidden = true;
            if (handoffRow) handoffRow.hidden = true;
            setStatus(message);
        };
        const appendExchange = (result) => {
            const stream = panel.closest('.lk-chat-stream');
            if (!stream) return;
            const exchange = document.createElement('article');
            exchange.className = 'lk-smart-help-exchange';

            const question = document.createElement('div');
            question.className = 'lk-smart-help-question-bubble';
            question.textContent = result.question || 'Suggested question';

            const answerBubble = document.createElement('div');
            answerBubble.className = 'lk-smart-help-answer-bubble';
            const source = document.createElement('small');
            source.textContent = result.answer_source || 'Automated answer';
            const answerText = document.createElement('p');
            answerText.textContent = result.answer || 'No answer is available right now.';
            answerBubble.append(source, answerText);
            exchange.append(question, answerBubble);
            stream.insertBefore(exchange, panel);
            hasAnswer = true;
            panel.dataset.hasAnswer = 'true';
            if (feedback) feedback.hidden = false;
            if (feedbackNote) feedbackNote.hidden = true;
            if (stream) stream.scrollTo({ top: stream.scrollHeight, behavior: 'smooth' });
        };

        panel.querySelectorAll('[data-smart-question]').forEach((button) => button.addEventListener('click', async () => {
            if (humanHandoff || busy || coolingDown()) return;
            const questionKey = button.dataset.questionKey;
            if (!questionKey) return;
            busy = true;
            lockControls();
            setStatus('Checking the approved answer...');
            try {
                const result = await post(panel.dataset.answerUrl, {
                    question_key: questionKey,
                    product_id: panel.dataset.productId || null,
                    variant_id: panel.dataset.variantId || null,
                });
                appendExchange(result);
                setStatus('The verified answer has been added to this conversation.');
            } catch (error) {
                showError(error);
            } finally {
                busy = false;
                unlockControls();
            }
        }));

        panel.querySelectorAll('[data-smart-feedback]').forEach((button) => button.addEventListener('click', async () => {
            if (humanHandoff || busy || coolingDown() || !hasAnswer) return;
            busy = true;
            lockControls();
            try {
                const result = await post(panel.dataset.feedbackUrl, { feedback: button.dataset.smartFeedback });
                if (feedback) feedback.hidden = true;
                if (feedbackNote) {
                    feedbackNote.textContent = result.last_feedback === 'RESOLVED'
                        ? 'Marked helpful. You can keep chatting or choose another question.'
                        : 'Thanks for letting us know. You can request live help in this conversation.';
                    feedbackNote.hidden = false;
                }
                if (result.last_feedback === 'RESOLVED') {
                    setStatus('Glad that helped. You can choose another question or continue chatting.');
                } else {
                    setStatus('Thanks for letting us know. You can request live help in this same conversation.');
                }
            } catch (error) {
                showError(error);
            } finally {
                busy = false;
                unlockControls();
            }
        }));

        panel.querySelector('[data-smart-handoff]')?.addEventListener('click', async (event) => {
            if (humanHandoff || busy || coolingDown()) return;
            const button = event.currentTarget;
            busy = true;
            lockControls();
            setStatus('Requesting live help in this conversation...');
            try {
                const result = await post(panel.dataset.handoffUrl, {
                    product_id: panel.dataset.productId || null,
                    variant_id: panel.dataset.variantId || null,
                    concern: conversationInput?.value?.trim() || null,
                });
                setHumanHandoff(result.already_requested
                    ? 'Live help is already requested. Continue in this conversation.'
                    : 'Live help requested in this conversation. Send your message below to share the details.');
                if (conversationInput) {
                    conversationInput.focus({ preventScroll: true });
                    conversationInput.closest('form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            } catch (error) {
                showError(error);
            } finally {
                busy = false;
                unlockControls();
            }
        });
    });

    document.querySelectorAll('[data-smart-resolve]').forEach((button) => {
        if (button.dataset.smartResolveBound === 'true') return;
        button.dataset.smartResolveBound = 'true';
        button.addEventListener('click', async () => {
            const token = button.dataset.csrf || '';
            const status = button.closest('[data-support-session]')?.querySelector('[data-support-session-status]');
            button.disabled = true;
            try {
                const response = await fetch(button.dataset.url, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({}),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(result.message || 'Could not resolve this request.');
                if (status) status.textContent = 'Resolved';
                const context = button.closest('[data-support-session]');
                if (context) context.dataset.status = 'RESOLVED';
                button.hidden = true;
            } catch (error) {
                if (status) status.textContent = error.message;
                button.disabled = false;
            }
        });
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeMessages, { once: true });
} else {
    initializeMessages();
}
