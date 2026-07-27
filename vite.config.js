import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
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
            ],
            refresh: true,
        }),
    ],
});
