import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // Shared cell renderers (badge / row action markup) live here.
        './resources/js/**/*.js',
        // The table cells' class names are built in the controllers
        // (Controller::statusBadge / rowActions), so they must be scanned too.
        './app/Http/Controllers/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    // Tailwind's own form reset. Flowbite is gone: every component in this app
    // (drawer, modal, toggle, notification) is hand-written Tailwind + Alpine.
    plugins: [forms],
};
