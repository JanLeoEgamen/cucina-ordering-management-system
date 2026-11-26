/**
 * Soft navigation (resources/js/ui/soft-nav.js, known-issues F21): what only a browser can show.
 */
import { expect, fixtures, sidebar, signIn, test } from './support/test.js';

const rows = (page, table) => page.locator(`${table} tbody tr button[data-row-action]`).first();

// Set on the window once: a full page load would lose it.
const markDocument = (page) => page.evaluate(() => { window.__sameDocument = true; });
const sameDocument = (page) => page.evaluate(() => window.__sameDocument === true);

test('the sidebar swaps pages without a reload, and Back and Forward do too', async ({ page }) => {
    await signIn(page, 'test@example.com');
    await page.goto('/companies');
    await expect(rows(page, '#companies-table')).toBeVisible();
    await markDocument(page);

    await sidebar(page, '/menu');
    await expect(page.locator('#menu-root')).toBeVisible();

    await sidebar(page, '/payments');
    await expect(page.locator('#payments-table')).toBeVisible();

    await sidebar(page, '/companies');
    await expect(rows(page, '#companies-table')).toBeVisible();

    await page.goBack();
    await page.waitForURL('**/payments');
    await expect(page.locator('#payments-table')).toBeVisible();

    await page.goBack();
    await page.waitForURL('**/menu');
    await expect(page.locator('#menu-root')).toBeVisible();

    await page.goForward();
    await page.waitForURL('**/payments');
    await expect(page.locator('#payments-table')).toBeVisible();

    expect(await sameDocument(page)).toBe(true);
});

test('coming back to a page leaves no listeners behind, and one click sends one request', async ({ page }) => {
    // Counts the document / window listeners that are still attached through an abort signal,
    // which is how soft-nav ties a page's listeners to the visit.
    await page.addInitScript(() => {
        const original = EventTarget.prototype.addEventListener;

        window.__pageListeners = 0;

        EventTarget.prototype.addEventListener = function (type, listener, options) {
            if ((this === document || this === window) && options?.signal && !options.signal.aborted) {
                window.__pageListeners += 1;
                original.call(options.signal, 'abort', () => { window.__pageListeners -= 1; }, { once: true });
            }

            return original.call(this, type, listener, options);
        };
    });

    await signIn(page, 'test@example.com');
    await page.goto('/users');
    await expect(rows(page, '#users-table')).toBeVisible();
    await markDocument(page);

    const listeners = () => page.evaluate(() => window.__pageListeners);
    const onFirstVisit = await listeners();

    expect(onFirstVisit).toBeGreaterThan(0);

    for (let round = 0; round < 3; round++) {
        await sidebar(page, '/companies');
        await expect(rows(page, '#companies-table')).toBeVisible();
        await sidebar(page, '/users');
        await expect(rows(page, '#users-table')).toBeVisible();
    }

    await expect.poll(listeners).toBe(onFirstVisit);

    const requests = [];
    page.on('request', (request) => {
        if (/\/users\/\d+\/edit$/.test(new URL(request.url()).pathname)) {
            requests.push(request.url());
        }
    });

    await page.locator('#users-table button[data-row-action="view"]').first().click();
    await expect(page.locator('#view-user-name')).not.toBeEmpty();
    // Long enough for a second handler's request to show up.
    await page.waitForTimeout(1500);

    expect(requests).toHaveLength(1);
    expect(await sameDocument(page)).toBe(true);
});

test('the menu stops asking for its stock once the page is left', async ({ page }) => {
    fixtures();

    let polls = 0;
    page.on('request', (request) => {
        if (new URL(request.url()).pathname === '/menu/stock') {
            polls += 1;
        }
    });

    await signIn(page, 'test@example.com');
    await page.goto('/menu');
    await markDocument(page);
    await expect.poll(() => polls).toBeGreaterThan(1);

    await sidebar(page, '/companies');
    await expect(rows(page, '#companies-table')).toBeVisible();

    // One may already be in the air.
    await page.waitForTimeout(1500);
    const afterLeaving = polls;
    await page.waitForTimeout(4000);

    expect(polls).toBe(afterLeaving);
    expect(await sameDocument(page)).toBe(true);
});
