import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Page modules, referenced per view with @vite('resources/js/pages/...').
                'resources/js/pages/dishes.js',
                'resources/js/pages/dish-types.js',
            ],
            refresh: true,
        }),
    ],
});
