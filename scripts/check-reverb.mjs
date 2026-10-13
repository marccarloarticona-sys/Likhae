import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

const values = Object.fromEntries(readFileSync(new URL('../.env', import.meta.url), 'utf8')
    .split(/\r?\n/)
    .filter((line) => /^[A-Za-z_][A-Za-z0-9_]*=/.test(line))
    .map((line) => {
        const separator = line.indexOf('=');
        return [line.slice(0, separator), line.slice(separator + 1).trim().replace(/^"|"$/g, '')];
    }));

const host = process.argv[2] || '127.0.0.1';
const port = Number(process.argv[3] || values.REVERB_SERVER_PORT || 8080);
const appKey = values.REVERB_APP_KEY;
const channel = `reverb.health.${Date.now()}`;
const url = `ws://${host}:${port}/app/${encodeURIComponent(appKey)}?protocol=7&client=js&version=8.6.0&flash=false`;
const timeout = setTimeout(() => finish('Timed out waiting for the broadcast event.', 1), 8000);
const socket = new WebSocket(url);
let finished = false;

function finish(message, exitCode) {
    if (finished) return;
    finished = true;
    clearTimeout(timeout);
    try { socket.close(); } catch { /* already closed */ }
    console.log(message);
    process.exitCode = exitCode;
}

socket.addEventListener('error', () => finish(`Could not connect to ${host}:${port}.`, 1));
socket.addEventListener('message', (message) => {
    const payload = JSON.parse(String(message.data));

    if (payload.event === 'pusher:connection_established') {
        socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel } }));
        return;
    }

    if (payload.event === 'pusher_internal:subscription_succeeded') {
        const php = process.env.PHP_BINARY || 'php';
        const code = [
            "require 'vendor/autoload.php';",
            "$app=require 'bootstrap/app.php';",
            "$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();",
            `$app->make(Illuminate\\Contracts\\Broadcasting\\Factory::class)->connection('reverb')->broadcast(['${channel}'], 'health.checked', ['ok'=>true]);`,
        ].join(' ');
        const result = spawnSync(php, ['-r', code], { cwd: new URL('..', import.meta.url), encoding: 'utf8' });
        if (result.status !== 0) finish(`Laravel broadcast failed: ${result.stderr.trim()}`, 1);
        return;
    }

    if (payload.event === 'health.checked') {
        const data = typeof payload.data === 'string' ? JSON.parse(payload.data) : payload.data;
        finish(data?.ok === true
            ? `PASS: Laravel broadcast reached a subscribed WebSocket client through Reverb on ${host}:${port}.`
            : 'Received the event with an unexpected payload.', data?.ok === true ? 0 : 1);
    }
});
