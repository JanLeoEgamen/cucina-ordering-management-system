/**
 * Browser tests (`npm run test:browser`, docs/testing.md). They drive a real Chrome (or Edge:
 * BROWSER_CHANNEL=msedge) already installed on the machine, against a throwaway database that
 * tests/Browser/support/serve.mjs creates. Not part of `php scripts/harness.php`.
 */
import { defineConfig } from '@playwright/test';
import path from 'node:path';
import { baseURL, directory } from './tests/Browser/support/environment.js';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.js',
    // One database and a single threaded PHP server: one test at a time.
    workers: 1,
    fullyParallel: false,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    // Beside the run's folder, not in it: serve.mjs empties that one.
    outputDir: path.join(path.dirname(directory), 'cucina-browser-results'),
    use: {
        baseURL,
        channel: process.env.BROWSER_CHANNEL ?? 'chrome',
        viewport: { width: 1366, height: 900 },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'node tests/Browser/support/serve.mjs',
        url: baseURL + '/login',
        reuseExistingServer: false,
        timeout: 180_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
