import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Dark mode is a class on <html> (set before paint by layouts/theme-script, flipped
    // by the switch in resources/js/ui/theme.js). Until someone uses the switch the
    // class follows the OS setting, so the default behaviour is unchanged.
    darkMode: 'class',

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

            /*
             * THE THEME. A warm gray palette (tokens read from tokenharbor.ai): a
             * paper page, near-black "ink", and soft grays. No colour accent: the
             * emphasis is the contrast between ink and paper (see docs/frontend.md,
             * Theme).
             *
             * Two Tailwind scales are REMAPPED instead of renamed, so the hundreds
             * of existing classes pick the new look up without being edited:
             *
             *   gray   -> warm neutrals. 50 is the paper (#f7f6f2), 900 the dark page
             *             (#141413), 500 the muted text (#6b6a64). Cards stay white.
             *   indigo -> the BRAND, which here is INK plus grays. 600 is ink (primary
             *             buttons, links), 300 / 400 the light grays that carry text
             *             and rings on the dark page, 50 / 100 the washes used for
             *             hover and active backgrounds. In dark mode a solid ink
             *             button would vanish, so resources/css/app.css turns
             *             `bg-indigo-600` into `mist` (a light gray) there.
             *
             * New code should prefer the names below (paper, ink, mist).
             */
            colors: {
                paper: '#f7f6f2',
                ink: '#17170f',
                // The foreground of the dark theme (#e8e6e1): ink's counterpart.
                mist: {
                    DEFAULT: '#e8e6e1',
                    deep: '#d8d5cc',
                },

                gray: {
                    50: '#f7f6f2',
                    100: '#efede7',
                    200: '#e4e1d8',
                    300: '#d0ccc0',
                    400: '#9a9793',
                    500: '#6b6a64',
                    600: '#55544e',                    700: '#3a3933',
                    800: '#23221e',
                    900: '#141413',
                    950: '#0d0d0c',
                },

                indigo: {
                    50: '#f3f2ee',
                    100: '#e9e7e0',
                    200: '#dcd9cf',
                    300: '#c9c5b8',
                    400: '#a8a498',
                    500: '#6b6a64',
                    600: '#17170f',
                    700: '#2b2b20',
                    800: '#55544e',
                    900: '#1d1d14',
                    950: '#0f0f0a',
                },
            },

            // Softer, warmer than Tailwind's greys: cards float on the paper.
            boxShadow: {
                card: '0 1px 2px rgb(23 23 15 / 0.04), 0 8px 24px -8px rgb(23 23 15 / 0.10)',
                'card-lift': '0 2px 4px rgb(23 23 15 / 0.05), 0 16px 32px -12px rgb(23 23 15 / 0.18)',
            },

            /*
             * Motion. Short, soft and purposeful: things arrive (fade-up), pop when
             * they open (pop), and answer a tap (cart-bump). All of it is switched
             * off for people who ask for reduced motion (resources/css/app.css).
             * `backwards` fill mode: the element holds its FIRST frame during a
             * delay, but afterwards is back under its normal styles, so a hover
             * transform is not frozen out by the animation.
             */
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(12px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                pop: {
                    '0%': { opacity: '0', transform: 'scale(0.94)' },
                    '100%': { opacity: '1', transform: 'scale(1)' },
                },
                'cart-bump': {
                    '0%, 100%': { transform: 'scale(1)' },
                    '35%': { transform: 'scale(1.18)' },
                    '65%': { transform: 'scale(0.96)' },
                },
                shake: {
                    '0%, 100%': { transform: 'translateX(0)' },
                    '20%, 60%': { transform: 'translateX(-6px)' },
                    '40%, 80%': { transform: 'translateX(6px)' },
                },
                'live-dot': {
                    '0%, 100%': { opacity: '1', transform: 'scale(1)' },
                    '50%': { opacity: '0.35', transform: 'scale(0.7)' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.5s cubic-bezier(0.22, 1, 0.36, 1) backwards',
                'fade-in': 'fade-in 0.25s ease-out backwards',
                pop: 'pop 0.25s cubic-bezier(0.22, 1, 0.36, 1) backwards',
                'cart-bump': 'cart-bump 0.45s ease-out',
                'live-dot': 'live-dot 1.6s ease-in-out infinite',
                shake: 'shake 0.4s ease-in-out',
            },
        },
    },

    // Tailwind's own form reset. Flowbite is gone: every component in this app
    // (drawer, modal, toggle, notification) is hand-written Tailwind + Alpine.
    plugins: [forms],
};
