import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        watch: {
            // Runtime caches/logs change on requests and must never trigger HMR.
            ignored: ['**/storage/**', '**/bootstrap/cache/**', '**/services/openuem-fork/**'],
        },
    },
    build: {
        emptyOutDir: false,
    },
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/shell-redesign.css',
                'resources/css/tabs-redesign.css',
                'resources/css/chat-redesign.css',
                'resources/js/app.js',
                // Eigener Einstieg fuers Anruf-Fenster: haelt livekit-client
                // (~80 KB gz) aus dem globalen Bundle heraus.
                'resources/js/calls.js',
            ],
            refresh: true,
        }),
    ],
});
