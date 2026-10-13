import './echo.js';
import { startRealtimeFallback } from './realtime-fallback.js';

const formatMessageTime = (payload) => {
    if (payload.time) return payload.time;
    if (payload.sent_at) return new Date(payload.sent_at).toLocaleTimeString('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    });
    return 'Just now';
};

const pendingMessageFor = (thread, payload) => {
    const body = String(payload.body || '').trim();
    const senderId = String(payload.sender_id || payload.sender_user_id || '');
    if (!body || !senderId) return null;

    return [...thread.querySelectorAll('[data-message-pending="true"]')].find((row) => {
        const rowBody = row.querySelector('.lk-message-bubble')?.textContent?.trim() || '';
        return rowBody === body && String(row.dataset.messageSenderId || '') === senderId;
    }) || null;
};

const reconcilePendingMessage = (thread, payload) => {
    const pending = pendingMessageFor(thread, payload);
    if (!pending || !payload.id) return false;

    pending.dataset.messageId = String(payload.id);
    pending.dataset.messagePending = 'false';
    pending.querySelector('.lk-message-time').textContent = formatMessageTime(payload);
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
    const previousRow = [...thread.querySelectorAll('[data-message-id]')].pop();
    const previousBody = previousRow?.querySelector('.lk-message-bubble')?.textContent?.trim();
    const isBuyer = Boolean(thread.closest('.lk-messages-page'));
    const previousIsMine = isBuyer
        ? previousRow?.classList.contains('is-buyer')
        : previousRow?.classList.contains('is-mine');
    if (!pending && previousRow && previousBody === String(payload.body || '').trim()
        && previousIsMine === isMine) return;
    const otherAvatar = thread.dataset.otherAvatar || thread.dataset.sellerAvatar || '';
    const otherName = thread.dataset.otherName || thread.dataset.sellerName || 'User';

    thread.querySelector('.lk-messages-empty')?.remove();
    thread.dataset.lastMessageId = id;

    const row = document.createElement('div');
    row.className = `lk-message-row${isMine ? (isBuyer ? ' is-buyer' : ' is-mine') : ''}`;
    row.dataset.messageId = id;
    row.dataset.messageSenderId = String(payload.sender_id || payload.sender_user_id || '');
    if (pending) row.dataset.messagePending = 'true';

    if (!isMine) {
        const avatar = document.createElement('img');
        avatar.className = 'lk-message-small-avatar';
        avatar.src = otherAvatar;
        avatar.alt = otherName;
        row.appendChild(avatar);
    }

    const wrapper = document.createElement('div');
    const bubble = document.createElement('div');
    bubble.className = 'lk-message-bubble';
    bubble.textContent = payload.body || '';
    const time = document.createElement('span');
    time.className = 'lk-message-time';
    time.textContent = pending ? 'Sending…' : formatMessageTime(payload);
    wrapper.append(bubble, time);
    row.appendChild(wrapper);
    thread.appendChild(row);
    thread.scrollTop = thread.scrollHeight;
};

const setConnectionStatus = (thread, message, error = false) => {
    const status = thread.closest('.lk-chat-area')?.querySelector('[data-message-connection-status]');
    if (!status) return;
    const dot = status.querySelector('.lk-chat-status-dot');
    status.lastChild.textContent = message;
    status.classList.toggle('is-error', error);
    dot?.classList.toggle('is-error', error);
};

const subscribeToThread = (thread) => {
    const conversationId = thread.dataset.conversationId;
    if (!conversationId) return;

    if (!thread.dataset.lastMessageId) {
        const lastMessageId = [...thread.querySelectorAll('[data-message-id]')]
            .map((row) => Number(row.dataset.messageId) || 0)
            .reduce((highest, id) => Math.max(highest, id), 0);
        thread.dataset.lastMessageId = String(lastMessageId);
    }

    if (window.Echo && thread.dataset.realtimeSubscribed !== 'true') {
        thread.dataset.realtimeSubscribed = 'true';
        window.Echo.private(`conversations.${conversationId}`)
            .listen('.message.sent', (payload) => appendRealtimeMessage(thread, payload));
    }

    const connection = window.Echo?.connector?.pusher?.connection;
    if (connection && thread.dataset.connectionStatusBound !== 'true') {
        thread.dataset.connectionStatusBound = 'true';
        connection.bind('connected', () => setConnectionStatus(thread, 'Live conversation'));
        connection.bind('disconnected', () => setConnectionStatus(thread, 'Reconnecting; fallback active', true));
        connection.bind('unavailable', () => setConnectionStatus(thread, 'Fallback polling active', true));
        setConnectionStatus(
            thread,
            connection.state === 'connected' ? 'Live conversation' : 'Connecting; fallback active',
            connection.state !== 'connected',
        );
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
        const input = form.querySelector('input[name="body"], textarea[name="body"]');
        const thread = form.closest('.lk-chat-area')?.querySelector('.lk-chat-stream[data-message-thread]');
        const body = input?.value.trim();
        if (!body || !thread) return;

        const pendingId = `pending-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
        const formData = new FormData(form);
        appendRealtimeMessage(thread, {
            id: pendingId,
            sender_id: thread.dataset.currentUserId,
            body,
        }, { pending: true });
        input.value = '';
        input.focus();

        let timeout = null;
        try {
            const controller = new AbortController();
            timeout = window.setTimeout(() => controller.abort(), 12000);
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                body: formData,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.message) {
                const validationMessage = payload?.errors ? Object.values(payload.errors).flat()[0] : null;
                throw new Error(validationMessage || payload?.message || 'Message could not be sent.');
            }

            appendRealtimeMessage(thread, payload.message);
            setConnectionStatus(thread, 'Message sent');
        } catch (error) {
            removePendingMessage(thread, pendingId);
            setConnectionStatus(thread, error?.message || 'Message could not be sent.', true);
        } finally {
            if (timeout !== null) window.clearTimeout(timeout);
        }
    });
};

window.lkSubscribeMessageThread = (thread, payload = null) => {
    if (payload?.conversation_id && !thread.dataset.conversationId) {
        thread.dataset.conversationId = String(payload.conversation_id);
    }

    subscribeToThread(thread);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.lk-chat-stream[data-conversation-id]').forEach(subscribeToThread);
    document.querySelectorAll('[data-live-message-form]').forEach(bindLiveSend);
    subscribeToUserMessages();
});
