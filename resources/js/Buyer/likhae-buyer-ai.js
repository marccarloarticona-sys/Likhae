(() => {
    const widget = document.querySelector('[data-likhae-buyer-ai]');
    if (!widget) return;

    const head = document.getElementById('likhaeBuyerAiHead');
    const windowEl = document.getElementById('likhaeBuyerAiWindow');
    const messages = document.getElementById('likhaeBuyerAiMessages');
    const form = document.getElementById('likhaeBuyerAiForm');
    const input = document.getElementById('likhaeBuyerAiInput');
    const send = document.getElementById('likhaeBuyerAiSend');
    const minimize = document.getElementById('likhaeBuyerAiMinimize');
    const close = document.getElementById('likhaeBuyerAiClose');
    const toggle = document.getElementById('likhaeBuyerAiToggle');
    const stateText = widget.querySelector('[data-likhae-buyer-ai-state]');
    const endpoint = widget.dataset.chatUrl;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const page = window.LIKHAE_BUYER_AI_PAGE_CONTEXT?.page || 'buyer';
    const responseSoundEnabled = widget.dataset.aiResponseSound === '1';
    let openedOnce = false;
    let sending = false;
    let enabled = sessionStorage.getItem('likhaeBuyerAiEnabled') === '1';

    const scrollToBottom = () => { messages.scrollTop = messages.scrollHeight; };
    const addBubble = (text, type = 'assistant', options = []) => {
        const bubble = document.createElement('div');
        bubble.className = `likhae-buyer-ai-bubble likhae-buyer-ai-bubble--${type}`;
        const copy = document.createElement('p');
        copy.textContent = text;
        bubble.append(copy);

        if (options.length) {
            const optionList = document.createElement('div');
            optionList.className = 'likhae-buyer-ai-options';
            options.forEach((option) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = option;
                button.addEventListener('click', () => submitMessage(option));
                optionList.append(button);
            });
            bubble.append(optionList);
        }
        messages.append(bubble);
        scrollToBottom();
        return bubble;
    };

    const addWelcome = () => {
        if (openedOnce) return;
        openedOnce = true;
        addBubble("Hi! 👋 I’m your LIKHAE AI Assistant. I can help you find Buyer features, understand orders, check delivery status, and use the Buyer portal. How can I help?");
        const quick = document.createElement('div');
        quick.className = 'likhae-buyer-ai-quick-actions';
        [
            ['📦', 'Track my order'],
            ['🛒', 'Where is my cart?'],
            ['🔎', 'How do I find products?'],
            ['🚚', 'Explain my order status'],
        ].forEach(([icon, question]) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.innerHTML = `<span aria-hidden="true">${icon}</span>${question}`;
            button.addEventListener('click', () => submitMessage(question));
            quick.append(button);
        });
        messages.append(quick);
        scrollToBottom();
    };

    const setOpen = (isOpen) => {
        windowEl.hidden = !isOpen;
        head.setAttribute('aria-expanded', String(isOpen));
        sessionStorage.setItem('likhaeBuyerAiOpen', isOpen ? '1' : '0');
        if (isOpen) {
            addWelcome();
            requestAnimationFrame(() => input.focus());
        }
    };

    const setEnabled = (isEnabled) => {
        enabled = isEnabled;
        widget.classList.toggle('likhae-buyer-ai-is-off', !enabled);
        sessionStorage.setItem('likhaeBuyerAiEnabled', enabled ? '1' : '0');
        toggle.setAttribute('aria-pressed', String(enabled));
        toggle.setAttribute('aria-label', enabled ? 'Turn LIKHAE AI Assistant off' : 'Turn LIKHAE AI Assistant on');
        toggle.title = enabled ? 'AI is online — turn off' : 'AI is offline — turn on';
        toggle.innerHTML = enabled ? '&#128065;' : '&#128683;';
        stateText.textContent = enabled ? 'Online' : 'Offline';
        input.disabled = !enabled;
        send.disabled = !enabled || sending;
    };

    const typingBubble = () => {
        const typing = document.createElement('div');
        typing.className = 'likhae-buyer-ai-typing';
        typing.setAttribute('role', 'status');
        typing.innerHTML = '<span></span><span></span><span></span><em>LIKHAE AI is typing</em>';
        messages.append(typing);
        scrollToBottom();
        return typing;
    };

    const playResponseSound = () => {
        if (!responseSoundEnabled) return;
        if (window.likhaePlayNotificationSound) {
            window.likhaePlayNotificationSound('AI_RESPONSE', { force: true });
            return;
        }
        if (!window.AudioContext) return;
        const context = new window.AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.frequency.value = 660;
        gain.gain.setValueAtTime(0.035, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.14);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(); oscillator.stop(context.currentTime + 0.14);
        oscillator.addEventListener('ended', () => context.close());
    };

    async function submitMessage(value) {
        const text = (value ?? input.value).trim();
        if (!text || sending) return;
        if (!enabled) {
            addBubble('LIKHAE AI is off. Select the eye button in the header to turn it on.', 'assistant');
            return;
        }
        setOpen(true);
        sending = true;
        input.value = '';
        input.style.height = 'auto';
        send.disabled = true;
        addBubble(text, 'buyer');
        const typing = typingBubble();

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ message: text, page }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload.reply) throw new Error('Assistant request failed');
            typing.remove();
            addBubble(payload.reply, 'assistant', Array.isArray(payload.options) ? payload.options : []);
            playResponseSound();
        } catch (_) {
            typing.remove();
            addBubble('Sorry, the LIKHAE AI Assistant is temporarily unavailable. Please try again.', 'assistant');
        } finally {
            sending = false;
            send.disabled = !enabled;
            if (enabled) input.focus();
        }
    }

    head.addEventListener('click', () => setOpen(windowEl.hidden));
    minimize.addEventListener('click', () => setOpen(false));
    close.addEventListener('click', () => { setEnabled(false); setOpen(false); });
    toggle.addEventListener('click', () => setEnabled(!enabled));
    form.addEventListener('submit', (event) => { event.preventDefault(); submitMessage(); });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            submitMessage();
        }
    });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 96)}px`;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !windowEl.hidden) setOpen(false);
    });

    setEnabled(enabled);
    if (sessionStorage.getItem('likhaeBuyerAiOpen') === '1') setOpen(true);
})();
