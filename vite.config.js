import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    server: {
        watch: {
            // Native OpenUEM sources are not part of the RailTime frontend.
            ignored: ['**/services/openuem-fork/**'],
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
