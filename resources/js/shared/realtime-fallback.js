// Realtime should normally be instant. When the WebSocket is unavailable,
// check quickly enough for chat to still feel live without hammering the API.
const FALLBACK_INTERVAL_MS = 5000;
const CONNECT_GRACE_MS = 2500;

const connectionFor = () => window.Echo?.connector?.pusher?.connection || null;

export const startRealtimeFallback = (poll) => {
    let timer = null;
    let graceTimer = null;
    let disposed = false;
    const connection = connectionFor();

    const stop = () => {
        if (timer !== null) window.clearInterval(timer);
        timer = null;
        if (graceTimer !== null) window.clearTimeout(graceTimer);
        graceTimer = null;
    };

    const start = () => {
        if (disposed || timer !== null) return;
        // Poll once immediately after the connection drops, then every five
        // seconds only until Reverb is connected again.
        if (!document.hidden) poll();
        timer = window.setInterval(() => {
            if (!document.hidden) poll();
        }, FALLBACK_INTERVAL_MS);
    };

    const sync = () => {
        const state = connection?.state;
        if (!connection || ['disconnected', 'unavailable', 'failed'].includes(state)) {
            start();
            return;
        }

        if (state === 'connecting' || state === 'initialized') {
            // Pusher can spend many seconds deciding that a failed WebSocket
            // is unavailable. Start a light fallback after a short grace
            // period so receiving messages does not wait for that timeout.
            if (timer === null && graceTimer === null) {
                graceTimer = window.setTimeout(() => {
                    graceTimer = null;
                    start();
                }, CONNECT_GRACE_MS);
            }
            return;
        }

        if (state === 'connected') stop();
        else start();
    };

    if (connection) {
        ['connected', 'connecting', 'disconnected', 'unavailable', 'failed'].forEach((state) => {
            connection.bind(state, sync);
        });
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) sync();
    });
    sync();

    return () => {
        disposed = true;
        stop();
        if (connection) {
            ['connected', 'connecting', 'disconnected', 'unavailable', 'failed'].forEach((state) => {
                connection.unbind(state, sync);
            });
        }
    };
};
