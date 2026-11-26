<?php

// The app as the browser tests run it: its own database, cache, sessions, logs and uploads
// inside one temporary folder (BROWSER_TEST_DIR, made by serve.mjs). Everything that touches
// data (setup.php, router.php, the helper scripts) gets the app from here, so all of it passes
// the same guard: if the app would use ANYTHING else, it stops before doing anything. It must
// never reach a developer's or a server's database.

$directory = getenv('BROWSER_TEST_DIR');

if (! $directory || ! is_file($directory . '/app-key')) {
    fwrite(fopen('php://stderr', 'w'), "BROWSER_TEST_DIR is not set up. Run the browser tests with: npm run test:browser\n");
    exit(1);
}

$directory = str_replace('\\', '/', realpath($directory));
$database = $directory . '/database.sqlite';

// These win over .env: Laravel never overwrites a variable that is already set.
$environment = [
    'APP_ENV' => 'local',
    'APP_DEBUG' => 'true',
    'APP_KEY' => trim(file_get_contents($directory . '/app-key')),
    'APP_URL' => getenv('BROWSER_TEST_URL') ?: 'http://127.0.0.1:8137',
    // A path that never exists: a cached config would ignore every value below.
    'APP_CONFIG_CACHE' => 'bootstrap/cache/browser-tests-never-cached.php',
    'APP_ROUTES_CACHE' => 'bootstrap/cache/browser-tests-never-cached-routes.php',
    'LARAVEL_STORAGE_PATH' => $directory . '/storage',
    'VIEW_COMPILED_PATH' => $directory . '/storage/framework/views',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'DB_URL' => '',
    'DB_PERSISTENT' => 'false',
    // In the temporary database, so nothing is shared with the developer's file cache.
    'CACHE_STORE' => 'database',
    'DB_CACHE_CONNECTION' => '',
    'SESSION_DRIVER' => 'file',
    'SESSION_DOMAIN' => '',
    'SESSION_SECURE_COOKIE' => 'false',
    'TRUSTED_PROXIES' => '',
    'QUEUE_CONNECTION' => 'database',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'single',
    'BCRYPT_ROUNDS' => '4',
    // No call to an outside service.
    'GOOGLE_CLIENT_ID' => '',
    'GOOGLE_CLIENT_SECRET' => '',
    'GOOGLE_CHAT_CREDENTIALS' => '',
    'SEED_OWNER_EMAIL' => '',
    'SEED_OWNER_PASSWORD' => '',
];

foreach ($environment as $name => $value) {
    putenv($name . '=' . $value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require_once __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';

// Runs once the configuration is loaded, before any request or command touches data.
$app->booted(function ($app) use ($directory, $database) {
    $same = fn (?string $path) => str_replace('\\', '/', (string) $path);

    $checks = [
        'the config is not cached' => ! $app->configurationIsCached(),
        'the database is SQLite' => config('database.default') === 'sqlite',
        'the database file is the temporary one' => $same(config('database.connections.sqlite.database')) === $database,
        'no database URL overrides it' => blank(config('database.connections.sqlite.url')),
        'the cache is in that database' => config('cache.default') === 'database' && blank(config('cache.stores.database.connection')),
        'storage is in the temporary folder' => str_starts_with($same($app->storagePath()), $directory . '/'),
    ];

    foreach ($checks as $what => $holds) {
        if (! $holds) {
            fwrite(fopen('php://stderr', 'w'), "Browser tests refused to start: could not confirm that {$what}.\n");
            exit(1);
        }
    }

    // A running `npm run dev` leaves public/hot behind: the tests use the built assets.
    $app->make(Illuminate\Foundation\Vite::class)->useHotFile($directory . '/no-hot-file');
});

return $app;
