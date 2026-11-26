/**
 * Where a browser test run keeps everything it writes. Always a `cucina-browser-run` folder of
 * its own, inside the system's temporary folder or inside BROWSER_TEST_BASE: serve.mjs empties
 * it at the start of a run, so it must never be a folder that holds anything else.
 */
import os from 'node:os';
import path from 'node:path';

export const port = Number(process.env.BROWSER_TEST_PORT ?? 8137);
export const baseURL = 'http://127.0.0.1:' + port;
export const directory = path.join(process.env.BROWSER_TEST_BASE ?? os.tmpdir(), 'cucina-browser-run');

// What the PHP side (support/app.php) reads.
export const phpEnvironment = { ...process.env, BROWSER_TEST_DIR: directory, BROWSER_TEST_URL: baseURL };
