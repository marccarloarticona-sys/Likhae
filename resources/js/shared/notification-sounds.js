const SOUND_PATTERNS = {
    message: [[660, 0, .08], [880, .09, .10]],
    order: [[523, 0, .09], [659, .10, .09], [784, .20, .12]],
    delivery: [[440, 0, .08], [554, .09, .08], [659, .18, .13]],
    promotion: [[784, 0, .07], [988, .08, .07], [1175, .16, .12]],
    earnings: [[587, 0, .08], [740, .09, .08], [988, .18, .14]],
    warning: [[392, 0, .12], [330, .14, .16]],
    return: [[494, 0, .10], [440, .12, .10], [392, .24, .14]],
    general: [[620, 0, .09], [780, .11, .12]],
};

const toneFor = (type = '') => {
    const value = String(type).toUpperCase();
    if (/MESSAGE|CHAT/.test(value)) return 'message';
    if (/ORDER|PURCHASE|CHECKOUT/.test(value)) return 'order';
    if (/DELIVERY|SHIPMENT|PICKUP|PARCEL|DISPATCH|RIDER|SORT|INVENTORY|ROUTE/.test(value)) return 'delivery';
    if (/PROMO|VOUCHER|DISCOUNT|CAMPAIGN|REWARD/.test(value)) return 'promotion';
    if (/EARNING|PAYOUT|FINANCE|COMMISSION|PAYMENT/.test(value)) return 'earnings';
    if (/SUSPEND|SECURITY|COMPLIANCE|WARNING|REJECT|FAILED|EXCEPTION|REGISTRATION/.test(value)) return 'warning';
    if (/RETURN|REFUND|DISPUTE|COMPLAINT/.test(value)) return 'return';
    return 'general';
};

document.addEventListener('DOMContentLoaded', () => {
    const config = window.LIKHAE_NOTIFICATION_SOUND_CONFIG;
    if (!config?.userId) return;

    const AudioContext = window.AudioContext || window.webkitAudioContext;
    if (!AudioContext) return;

    let context;
    const getContext = () => {
        context ||= new AudioContext();
        if (context.state === 'suspended') context.resume().catch(() => {});
        return context;
    };
    const promptKey = 'likhae-notification-sounds-enabled';
    const dismissPrompt = () => document.querySelector('[data-audio-unlock-prompt]')?.remove();
    window.likhaeEnableNotificationSounds = () => {
        const audio = getContext();
        audio.resume?.().catch(() => {});
        try { localStorage.setItem(promptKey, '1'); } catch (_) {}
        dismissPrompt();
    };

    if (config.enabled) {
        let alreadyEnabled = false;
        try { alreadyEnabled = localStorage.getItem(promptKey) === '1'; } catch (_) {}
        if (!alreadyEnabled) {
            const prompt = document.createElement('div');
            prompt.className = 'lk-audio-unlock-prompt';
            prompt.dataset.audioUnlockPrompt = 'true';
            prompt.setAttribute('role', 'status');
            prompt.innerHTML = '<span>Enable notification sounds?</span><button type="button">Enable sounds</button>';
            prompt.querySelector('button').addEventListener('click', () => window.likhaeEnableNotificationSounds());
            document.body.append(prompt);
        }
    }
    const unlock = () => getContext();
    window.addEventListener('pointerdown', unlock, { once: true, passive: true });
    window.addEventListener('keydown', unlock, { once: true });

    window.likhaePlayNotificationSound = (type = 'general', options = {}) => {
        if (!config.enabled && !options.force) return;
        const audio = getContext();
        const start = audio.currentTime + .01;
        (SOUND_PATTERNS[toneFor(type)] || SOUND_PATTERNS.general).forEach(([frequency, delay, duration]) => {
            const oscillator = audio.createOscillator();
            const gain = audio.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, start + delay);
            gain.gain.setValueAtTime(.0001, start + delay);
            gain.gain.exponentialRampToValueAtTime(.055, start + delay + .015);
            gain.gain.exponentialRampToValueAtTime(.0001, start + delay + duration);
            oscillator.connect(gain).connect(audio.destination);
            oscillator.start(start + delay);
            oscillator.stop(start + delay + duration + .02);
        });
    };

    document.querySelectorAll('[data-notification-preview]').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            window.likhaeEnableNotificationSounds?.();
            window.likhaePlayNotificationSound(button.dataset.notificationPreview, { force: true });
        });
    });

    if (config.enabled && window.Echo) {
        window.Echo.private(`App.Models.User.${config.userId}`)
            .listen('.notification.created', notification => window.likhaePlayNotificationSound(notification?.type));
    }
});
