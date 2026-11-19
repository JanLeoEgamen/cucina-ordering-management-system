/**
 * One browser, many tabs: log out in one and every other tab of the app leaves too.
 *
 * The session cookie is shared by all tabs, so the SERVER already forgot the user the moment
 * one tab logged out; what stayed behind was the other tabs' stale page. Two layers fix that:
 *
 *   1. Instant (same browser): the logout forms (`data-logout-form`) leave a flag in localStorage
 *      when they are submitted. The page that loads after the logout (a page with no
 *      `meta[name=login-url]`, i.e. a signed-out one) turns the flag into a `storage` event, which
 *      the OTHER tabs hear. The event is sent from the signed-out page, not on submit, because a
 *      tab that went to /login before the logout finished would be bounced back by the `guest`
 *      middleware. Hidden tabs hear it too.
 *   2. Safety net (anything else: session expired, logout in another browser or device, a tab that
 *      was asleep): any 401 answer, from axios here or from the notification poll (notifications.js),
 *      sends the tab to the login page as well.
 *
 * `meta[name=login-url]` is only drawn for signed-in users (layouts/app and guest, inside @auth),
 * so on a signed-out page this module does nothing but send the flag.
 */
const SIGNED_OUT = 'cucina.signed-out';
const PENDING = 'cucina.sign-out-pending';

const loginUrl = () => document.querySelector('meta[name="login-url"]')?.content || null;

// localStorage can throw (private mode, blocked site data): the 401 safety net still works then.
const store = {
    set(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (error) {
            // Ignore.
        }
    },
    take(key) {
        try {
            const value = localStorage.getItem(key);
            localStorage.removeItem(key);

            return value;
        } catch (error) {
            return null;
        }
    },
};

let leaving = false;

// Used here and by notifications.js. Replace, not assign: Back must not return to a dead page.
export const leaveToLogin = () => {
    const url = loginUrl();

    if (!url || leaving) {
        return;
    }

    leaving = true;
    window.location.replace(url);
};

// A logout form was submitted in this tab.
document.addEventListener('submit', (event) => {
    if (event.target instanceof HTMLFormElement && event.target.hasAttribute('data-logout-form')) {
        store.set(PENDING, '1');
    }
});

// The page after the logout announces it. If the logout failed we are still signed in: drop the flag.
if (store.take(PENDING) !== null && !loginUrl()) {
    // Date.now() makes every value new, and the event only fires when the value changes.
    store.set(SIGNED_OUT, String(Date.now()));
}

window.addEventListener('storage', (event) => {
    if (event.key === SIGNED_OUT && event.newValue) {
        leaveToLogin();
    }
});

// Every axios call: a 401 means the session is gone.
window.axios?.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            leaveToLogin();
        }

        return Promise.reject(error);
    },
);
