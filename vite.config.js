import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // The page modules (resources/js/pages/*.js) are not listed here: soft-nav.js
                // imports them lazily with import.meta.glob, one chunk each, and a view names
                // the ones it needs with <x-page-module name="..." />.
            ],
            refresh: true,
        }),
    ],
});
