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
                'resources/js/pages/dish-types-manager.js',
                'resources/js/pages/order-sessions.js',
                'resources/js/pages/order-session-show.js',
                'resources/js/pages/orders.js',
                'resources/js/pages/menu.js',
                'resources/js/pages/payment-methods.js',
                'resources/js/pages/payments.js',
                'resources/js/pages/roles.js',
                'resources/js/pages/users.js',
                'resources/js/pages/settings.js',
            ],
            refresh: true,
        }),
    ],
});
