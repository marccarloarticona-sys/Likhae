import os from 'node:os';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

function getLanAddress() {
    for (const interfaces of Object.values(os.networkInterfaces())) {
        const address = interfaces?.find((candidate) =>
            candidate.family === 'IPv4'
            && !candidate.internal
            && (
                candidate.address.startsWith('10.')
                || candidate.address.startsWith('192.168.')
                || /^172\.(1[6-9]|2\d|3[01])\./.test(candidate.address)
            )
        );

        if (address) {
            return address.address;
        }
    }

    return 'localhost';
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');

    return {
        plugins: [
            laravel({
                // Keep the development-server marker out of public/ so it can
                // never be uploaded with the production web root.
                hotFile: 'storage/framework/vite.hot',
                input: [
                    'resources/css/app.css',
                    'resources/css/admin/admin.css',
                    'resources/css/Buyer/buyer.css',
                    'resources/css/Buyer/likhae-buyer-ai.css',
                    'resources/css/Buyer/settings.css',
                    'resources/css/seller/seller.css',
                    'resources/css/auth/login.css',
                    'resources/css/auth/register.css',
                    'resources/css/logistic/app.css',
                    'resources/css/logistic/landing.css',
                    'resources/css/shared/likhae-workspace-ai.css',
                    'resources/css/shared/mapbox.css',
                    'resources/css/shared/workspace-sidebar.css',
                    'resources/css/shared/workspace-settings.css',
                    'resources/js/app.js',
                    'resources/js/shared/messages.js',
                    'resources/js/Buyer/buyer.js',
                    'resources/js/Buyer/realtime.js',
                    'resources/js/Buyer/likhae-buyer-ai.js',
                    'resources/js/admin/admin.js',
                    'resources/js/seller/seller.js',
                    'resources/js/auth/login.js',
                    'resources/js/auth/recovery.js',
                    'resources/js/auth/register.js',
                    'resources/js/logistic/app.js',
                    'resources/js/logistic/portal.js',
                    'resources/js/rider/app.js',
                    'resources/js/address/forms.js',
                    'resources/js/shared/likhae-workspace-ai.js',
                    'resources/js/shared/mapbox.js',
                    'resources/js/shared/address-pin.js',
                ],
                refresh: [
                    'resources/views/**',
                    'routes/**',
                    'app/Http/**',
                ],
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            // Keep the development URL deterministic. Silently moving to
            // 5174+ leaves already-open pages attached to a stale Vite/Echo
            // process and makes Reverb failures very hard to diagnose.
            strictPort: true,
            hmr: {
                host: env.VITE_HMR_HOST || getLanAddress(),
            },
        },
        build: {
            manifest: 'manifest.json',
            emptyOutDir: true,
        },
    };
});
