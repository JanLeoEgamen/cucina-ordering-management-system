<?php

/*
 * Project harness: verifies the code and the docs still agree.
 *
 *   php scripts/harness.php            full run: docs drift + test suite
 *   php scripts/harness.php --docs     docs drift only (fast, no tests)
 *   php scripts/harness.php --hook     Claude Code Stop hook mode (see below)
 *
 * What "drift" means here (each check reads the code, then looks for it in the docs):
 *   1. every app route (METHOD /uri)        is listed in docs/api.md
 *   2. every App\Models\Role::PERMISSIONS   is listed in docs/security.md
 *   3. every Schema::create() table         is listed in docs/database.md
 *   4. every resources/js/pages/*.js module is listed in docs/frontend.md
 *   5. code changed (app/, routes/, database/, resources/, config/) but
 *      docs/changelog.md was not touched in the same working tree
 *
 * Hook mode: exit code 2 + stderr makes Claude Code keep working, so the model
 * is told exactly which docs to update. It only checks docs (never the slow
 * test suite), and it stands down on the second pass (stop_hook_active) so it
 * can never loop forever.
 *
 * Process: docs/harness.md. Keep this file dependency free (plain PHP).
 */

$root = dirname(__DIR__);
chdir($root);

$args = array_slice($argv, 1);
$hook = in_array('--hook', $args, true);
$docsOnly = $hook || in_array('--docs', $args, true);

if ($hook) {
    $payload = json_decode((string) stream_get_contents(STDIN), true);

    // Claude already got one reminder this turn: do not block it again.
    if (is_array($payload) && ! empty($payload['stop_hook_active'])) {
        exit(0);
    }
}

$problems = [];

// ---------------------------------------------------------------- helpers
$read = fn (string $path): string => is_file($path) ? (string) file_get_contents($path) : '';

$docApi = $read('docs/api.md');
$docSecurity = $read('docs/security.md');
$docDatabase = $read('docs/database.md');
$docFrontend = $read('docs/frontend.md');

foreach (['api', 'security', 'database', 'frontend', 'changelog'] as $name) {
    if (! is_file("docs/{$name}.md")) {
        $problems[] = "docs/{$name}.md is missing.";
    }
}

// ----------------------------------------------- 1. routes vs docs/api.md
$routes = shell_exec('php artisan route:list --json --except-vendor 2>&1');
$routes = json_decode((string) $routes, true);

if (! is_array($routes)) {
    $problems[] = 'Could not read `php artisan route:list --json` (does the app boot?).';
} else {
    foreach ($routes as $route) {
        $uri = '/' . ltrim($route['uri'], '/');

        // Framework / Breeze internals are out of scope for api.md.
        if (preg_match('#^/(up|sanctum|storage|_ignition)#', $uri)) {
            continue;
        }

        foreach (explode('|', $route['method']) as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            if (! str_contains($docApi, "`{$method} {$uri}`")) {
                $problems[] = "docs/api.md: route `{$method} {$uri}` is not documented.";
            }
        }
    }
}

// ------------------------------- 2. permissions vs docs/security.md
$roleSource = $read('app/Models/Role.php');

if (preg_match('/const PERMISSIONS = \[(.*?)\];/s', $roleSource, $block)) {
    preg_match_all("/'([^']+)'/", $block[1], $matches);

    foreach ($matches[1] as $permission) {
        if (! str_contains($docSecurity, "`{$permission}`")) {
            $problems[] = "docs/security.md: permission `{$permission}` is not documented.";
        }
    }
} else {
    $problems[] = 'Could not find Role::PERMISSIONS in app/Models/Role.php.';
}

// ---------------------------------- 3. tables vs docs/database.md
foreach (glob('database/migrations/*.php') ?: [] as $migration) {
    if (preg_match_all("/Schema::create\('([^']+)'/", $read($migration), $tables)) {
        foreach ($tables[1] as $table) {
            if (! str_contains($docDatabase, "`{$table}`")) {
                $problems[] = "docs/database.md: table `{$table}` is not documented.";
            }
        }
    }
}

// ------------------------------- 4. page modules vs docs/frontend.md
foreach (glob('resources/js/pages/*.js') ?: [] as $page) {
    $name = basename($page);

    if (! str_contains($docFrontend, "`{$name}`")) {
        $problems[] = "docs/frontend.md: page module `{$name}` is not documented.";
    }
}

// --------------------------------- 5. code changed but changelog untouched
$status = shell_exec('git status --porcelain -uall 2>&1');
$changed = array_filter(array_map(fn ($line) => trim(substr($line, 3)), explode("\n", (string) $status)));

$codeChanged = array_filter($changed, fn ($file) => (bool) preg_match('#^(app|routes|database|resources|config)/#', str_replace('\\', '/', $file)));
$changelogTouched = array_filter($changed, fn ($file) => str_replace('\\', '/', $file) === 'docs/changelog.md');

if ($codeChanged && ! $changelogTouched) {
    $problems[] = 'Code changed but docs/changelog.md was not updated (add an entry under [Unreleased]).';
}

// ----------------------------------------------------------------- report
if ($problems) {
    $message = "HARNESS: docs are out of date with the code. Update them (see docs/harness.md, step 5), then re-run `php scripts/harness.php --docs`:\n- "
        . implode("\n- ", array_unique($problems)) . "\n";

    fwrite($hook ? STDERR : STDOUT, $message);

    exit($hook ? 2 : 1);
}

echo "HARNESS: docs drift check passed.\n";

if ($docsOnly) {
    exit(0);
}

// -------------------------------------------------------------- test suite
echo "HARNESS: running the test suite...\n";
passthru('php artisan test', $code);

exit($code);
