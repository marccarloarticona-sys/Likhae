import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const pageHost = window.location.hostname;
const isLocalPage = window.location.protocol === 'http:'
    || ['localhost', '127.0.0.1', '0.0.0.0', '::1'].includes(pageHost)
    || /^10\./.test(pageHost)
    || /^192\.168\./.test(pageHost)
    || /^172\.(1[6-9]|2\d|3[01])\./.test(pageHost);
const configuredScheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https';
const forceTLS = isLocalPage ? false : configuredScheme === 'https';
const reverbPort = isLocalPage ? 8080 : Number(import.meta.env.VITE_REVERB_PORT ?? (forceTLS ? 443 : 80));
const reverbHost = isLocalPage ? pageHost : import.meta.env.VITE_REVERB_HOST;
const endpointSignature = `${forceTLS ? 'wss' : 'ws'}://${reverbHost}:${reverbPort}`;

// Several layouts load more than one real-time-aware entry point. Keep a
// single Echo connection for the whole page so subscriptions and status
// indicators all observe the same socket.
if (window.Echo && window.__LIKHAE_ECHO_ENDPOINT__ !== endpointSignature) {
    window.Echo.disconnect();
    window.Echo = null;
}

window.Echo ??= new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
});
window.__LIKHAE_ECHO_ENDPOINT__ = endpointSignature;
