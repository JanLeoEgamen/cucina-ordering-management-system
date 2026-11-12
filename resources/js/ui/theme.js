/**
 * Dark / light switch.
 *
 * Any element marked `data-theme-toggle` flips the theme. The theme is the `dark`
 * class on <html>: layouts/theme-script sets it before first paint, this module
 * flips it and remembers the choice (localStorage `cucina.theme`). Until someone
 * has chosen, the page keeps following the OS setting, live.
 *
 * Loaded on every page through app.js, like the image viewer.
 */
const KEY = 'cucina.theme';
const root = document.documentElement;

const stored = () => {
    try {
        return localStorage.getItem(KEY);
    } catch (error) {
        return null;
    }
};

const isDark = () => root.classList.contains('dark');

// aria-pressed says "dark mode is on" to a screen reader.
const syncButtons = () => {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String(isDark()));
    });
};

const apply = (dark, remember) => {
    // Colours ease over for a moment (see app.css) instead of snapping.
    root.classList.add('theme-transition');
    root.classList.toggle('dark', dark);
    setTimeout(() => root.classList.remove('theme-transition'), 350);

    if (remember) {
        try {
            localStorage.setItem(KEY, dark ? 'dark' : 'light');
        } catch (error) {
            // Storage may be disabled; the choice then only lasts this visit.
        }
    }

    syncButtons();
};

document.addEventListener('click', (event) => {
    if (event.target.closest('[data-theme-toggle]')) {
        apply(!isDark(), true);
    }
});

// Nobody has chosen yet: follow the device when it switches (sunset, a shortcut).
window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', (event) => {
    if (!stored()) {
        apply(event.matches, false);
    }
});

syncButtons();