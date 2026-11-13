import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const viteUrl = 'http://127.0.0.1:5173/@vite/client';

async function isViteAlreadyRunning() {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 1500);

    try {
        const response = await fetch(viteUrl, { signal: controller.signal });
        if (!response.ok) return false;

        const body = await response.text();
        return body.includes('/@vite/client') || body.includes('createHotContext');
    } catch {
        return false;
    } finally {
        clearTimeout(timeout);
    }
}

if (await isViteAlreadyRunning()) {
    console.log('Reusing the Vite dev server already running on port 5173.');
    process.exit(0);
}

const projectRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const viteCli = path.join(projectRoot, 'node_modules', 'vite', 'bin', 'vite.js');
const vite = spawn(process.execPath, [viteCli, '--host=0.0.0.0', '--port=5173', '--strictPort'], {
    cwd: projectRoot,
    stdio: 'inherit',
});

let receivedSignal;
const signalHandlers = new Map();
for (const signal of ['SIGINT', 'SIGTERM']) {
    const handler = () => {
        receivedSignal = signal;
        vite.kill(signal);
    };
    signalHandlers.set(signal, handler);
    process.on(signal, handler);
}

vite.on('error', (error) => {
    console.error(`Unable to start Vite: ${error.message}`);
    process.exitCode = 1;
});

vite.on('exit', (code, signal) => {
    for (const [registeredSignal, handler] of signalHandlers) {
        process.off(registeredSignal, handler);
    }
    process.exitCode = receivedSignal ? 130 : (signal ? 1 : (code ?? 1));
});
