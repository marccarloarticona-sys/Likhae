(() => {
    document.querySelectorAll('[data-likhae-workspace-ai]').forEach((widget) => {
        const config = window.LIKHAE_WORKSPACE_AI_CONFIG || {};
        const head = widget.querySelector('[data-likhae-workspace-ai-head]');
        const windowEl = widget.querySelector('.likhae-workspace-ai-window');
        const messages = widget.querySelector('[data-likhae-workspace-ai-messages]');
        const form = widget.querySelector('[data-likhae-workspace-ai-form]');
        const input = widget.querySelector('[data-likhae-workspace-ai-input]');
        const send = widget.querySelector('[data-likhae-workspace-ai-send]');
        const minimize = widget.querySelector('[data-likhae-workspace-ai-minimize]');
        const close = widget.querySelector('[data-likhae-workspace-ai-close]');
        const toggle = widget.querySelector('[data-likhae-workspace-ai-toggle]');
        const stateText = widget.querySelector('[data-likhae-workspace-ai-state]');
        const endpoint = widget.dataset.chatUrl;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const workspace = widget.dataset.workspace || config.workspace || 'workspace';
        const enableKey = `likhaeWorkspaceAiEnabled:${workspace}`;
        const openKey = `likhaeWorkspaceAiOpen:${workspace}`;
        let openedOnce = false;
        let sending = false;
        let enabled = sessionStorage.getItem(enableKey) === '1';

        const scrollToBottom = () => { messages.scrollTop = messages.scrollHeight; };

        const addBubble = (text, type = 'assistant', options = []) => {
            const bubble = document.createElement('div');
            bubble.className = `likhae-workspace-ai-bubble likhae-workspace-ai-bubble--${type}`;

            const copy = document.createElement('p');
            copy.textContent = text;
            bubble.append(copy);

            if (options.length) {
                const optionList = document.createElement('div');
                optionList.className = 'likhae-workspace-ai-options';
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

        const playResponseSound = () => {
            if (widget.dataset.aiResponseSound !== '1') return;
            if (window.likhaePlayNotificationSound) {
                window.likhaePlayNotificationSound('AI_RESPONSE', { force: true });
                return;
            }
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) return;
            const context = new AudioContext();
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            oscillator.frequency.value = 620;
            gain.gain.setValueAtTime(0.035, context.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.16);
            oscillator.connect(gain).connect(context.destination);
            oscillator.start();
            oscillator.stop(context.currentTime + 0.16);
            oscillator.addEventListener('ended', () => context.close(), { once: true });
        };

        const addWelcome = () => {
            if (openedOnce) return;
            openedOnce = true;
            addBubble(config.welcome || `Hi! I’m your LIKHAE ${workspace} Assistant. How can I help?`);

            const quick = document.createElement('div');
            quick.className = 'likhae-workspace-ai-quick-actions';
            (config.shortcuts || []).forEach(({ icon, label }) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.innerHTML = `<span aria-hidden="true">${icon || '•'}</span>${label}`;
                button.addEventListener('click', () => submitMessage(label));
                quick.append(button);
            });
            messages.append(quick);
            scrollToBottom();
        };

        const setOpen = (isOpen) => {
            windowEl.hidden = !isOpen;
            head.setAttribute('aria-expanded', String(isOpen));
            sessionStorage.setItem(openKey, isOpen ? '1' : '0');
            if (isOpen) {
                addWelcome();
                requestAnimationFrame(() => input.focus());
            }
        };

        const setEnabled = (isEnabled) => {
            enabled = isEnabled;
            widget.classList.toggle('likhae-workspace-ai-is-off', !enabled);
            sessionStorage.setItem(enableKey, enabled ? '1' : '0');
            toggle.setAttribute('aria-pressed', String(enabled));
            toggle.setAttribute('aria-label', enabled ? 'Turn assistant off' : 'Turn assistant on');
            toggle.title = enabled ? 'AI is online — turn off' : 'AI is offline — turn on';
            toggle.innerHTML = enabled ? '&#128065;' : '&#128683;';
            stateText.textContent = enabled ? 'Online' : 'Offline';
            input.disabled = !enabled;
            send.disabled = !enabled || sending;
        };

        const typingBubble = () => {
            const typing = document.createElement('div');
            typing.className = 'likhae-workspace-ai-typing';
            typing.setAttribute('role', 'status');
            typing.innerHTML = '<span></span><span></span><span></span><em>Assistant is typing</em>';
            messages.append(typing);
            scrollToBottom();
            return typing;
        };

        async function submitMessage(value) {
            const text = (value ?? input.value).trim();
            if (!text || sending) return;
            if (!enabled) {
                addBubble(`${config.assistantName || 'This assistant'} is offline. Turn it on with the toggle above.`, 'assistant');
                return;
            }

            setOpen(true);
            sending = true;
            input.value = '';
            input.style.height = 'auto';
            send.disabled = true;
            addBubble(text, 'user');
            const typing = typingBubble();

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ message: text, page: config.page || 'dashboard' }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.reply) throw new Error('Assistant request failed');
                typing.remove();
                addBubble(payload.reply, 'assistant', Array.isArray(payload.options) ? payload.options : []);
                playResponseSound();
            } catch (_) {
                typing.remove();
                addBubble('I’m unable to reach the AI service right now, but I can still help with the workspace navigation and common workflows.', 'assistant');
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
        if (sessionStorage.getItem(openKey) === '1') setOpen(true);
    });
})();
