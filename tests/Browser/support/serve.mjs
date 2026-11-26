/**
 * Started by Playwright (playwright.config.js): a fresh temporary database, then PHP's built-in
 * server on it. Nothing here can reach the developer's database: see support/app.php.
 */
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { directory, phpEnvironment, port } from './environment.js';

fs.rmSync(directory, { recursive: true, force: true });

for (const folder of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/public', 'app/private']) {
    fs.mkdirSync(path.join(directory, 'storage', folder), { recursive: true });
}

fs.writeFileSync(path.join(directory, 'database.sqlite'), '');
fs.writeFileSync(path.join(directory, 'app-key'), 'base64:' + randomBytes(32).toString('base64'));

const setup = spawnSync('php', ['tests/Browser/support/setup.php'], { env: phpEnvironment, stdio: 'inherit' });

if (setup.status !== 0) {
    process.exit(setup.status ?? 1);
}

const server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'public', 'tests/Browser/support/router.php'], {
    env: phpEnvironment,
    // The access log is noise; PHP's errors land in the temporary storage/logs.
    stdio: 'ignore',
});

server.on('exit', (code) => process.exit(code ?? 0));

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => server.kill());
}
