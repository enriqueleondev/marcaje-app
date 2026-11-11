import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/kiosk.css', 'resources/js/kiosk.js'],
            refresh: true,
        }),
    ],
    build: {
        target: 'es2022', // el kiosco usa top-level await
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
