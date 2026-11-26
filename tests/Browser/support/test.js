/**
 * What every spec shares. Each test also fails on an error in the page: an uncaught exception,
 * or soft-nav.js reporting that a page module did not start.
 */
import { test as base, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { directory, phpEnvironment } from './environment.js';

export const test = base.extend({
    pageErrors: [async ({ page }, use) => {
        const errors = [];

        page.on('pageerror', (error) => errors.push(error.message));
        page.on('console', (message) => {
            if (message.type() === 'error' && message.text().includes('Page module')) {
                errors.push(message.text());
            }
        });

        await use(errors);

        expect(errors, 'errors in the page').toEqual([]);
    }, { auto: true }],
});

export { expect };

// Ids written by support/setup.php (read late: the server has made them by the time a test runs).
export const fixtures = () => JSON.parse(fs.readFileSync(path.join(directory, 'fixtures.json'), 'utf8'));

// Something another person does meanwhile, or a look at what was saved (support/helper.php).
export const helper = (...args) => execFileSync('php', ['tests/Browser/support/helper.php', ...args.map(String)], { env: phpEnvironment, encoding: 'utf8' });

export const lastOrder = (table) => JSON.parse(helper('last-order', table));

export const setRicePrice = (price) => helper('rice-price', price);

export const signIn = async (page, email) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', 'password');
    await Promise.all([
        page.waitForURL((url) => !url.pathname.endsWith('/login')),
        page.locator('form button[type="submit"]').click(),
    ]);
};

// A click on a sidebar link, opening its group first when that is folded.
export const sidebar = async (page, pathname) => {
    const link = page.locator(`#app-sidebar nav a[href$="${pathname}"]`);

    if (!await link.isVisible()) {
        await link.locator('xpath=ancestor::*[@data-nav-group][1]').locator('button[aria-controls]').click();
    }

    await link.click();
    await page.waitForURL((url) => url.pathname === pathname);
};
