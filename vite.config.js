import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Chart pages are separate entries so Blade only ships a tiny boot JSON + hashed chunks.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/chart/dashboard.js',
                'resources/js/chart/bot.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
