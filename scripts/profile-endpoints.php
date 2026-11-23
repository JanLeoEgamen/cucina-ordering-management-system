<?php

/*
 * Times every parameterless GET route in-process as the Super Admin: wall ms, number of DB queries
 * and ms spent in them (page and AJAX feed). Read only; the audit trail's terminate() is not called.
 *
 *   php scripts/profile-endpoints.php [--only=orders] [--sql]   (--sql prints every query of every endpoint)
 *
 * With a remote DB the query count is the number to cut: each query costs one network round trip.
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

chdir(dirname(__DIR__));
require 'vendor/autoload.php';

putenv('SESSION_DRIVER=array');
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';

$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$only = null;
$showSql = in_array('--sql', $argv, true);
foreach ($argv as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = substr($a, 7);
    }
}

$user = App\Models\User::role('Super Admin')->first();
if (! $user) {
    fwrite(STDERR, "No Super Admin user.\n");
    exit(1);
}

$uris = [];
foreach (Route::getRoutes() as $route) {
    $uri = $route->uri();
    if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || str_starts_with($uri, '_') || str_starts_with($uri, 'up')) {
        continue;
    }
    if (in_array($uri, ['auth/google', 'auth/google/callback', 'audit-logs/export', 'logout'], true)) {
        continue;
    }
    if ($only && ! str_contains($uri, $only)) {
        continue;
    }
    $uris[] = '/' . ltrim($uri, '/');
}
sort($uris);

$rows = [];
foreach ($uris as $uri) {
    foreach ([false, true] as $ajax) {
        // Only the index routes have a feed; skip the rest for the AJAX pass.
        if ($ajax && ! preg_match('#^/(companies|discounts|dishes|dish-types|orders|order-sessions|payments|payment-methods|pre-order-sessions|users|roles|audit-logs|notifications|menu/stock)#', $uri)) {
            continue;
        }

        $n = 0;
        $q = 0.0;
        DB::flushQueryLog();
        DB::listen(function ($e) use (&$n, &$q, $uri, $ajax, $showSql) {
            $n++;
            $q += $e->time;
            if ($showSql) {
                printf("   %-30s %4dms %s\n", $uri . ($ajax ? ' [ajax]' : ''), $e->time, substr(preg_replace('/\s+/', ' ', $e->sql), 0, 140));
            }
        });

        $req = Request::create($uri . ($ajax && $uri === '/notifications/feed' ? '?after=0' : ''), 'GET');
        if ($ajax) {
            $req->headers->set('X-Requested-With', 'XMLHttpRequest');
            $req->headers->set('Accept', 'application/json');
        }
        $req->setUserResolver(fn () => $user);

        $app['auth']->guard('web')->setUser($user);
        $t = microtime(true);
        try {
            $res = $kernel->handle($req);
            $status = $res->getStatusCode();
        } catch (Throwable $e) {
            $status = 'ERR ' . get_class($e);
        }
        $ms = (microtime(true) - $t) * 1000;

        $rows[] = ['uri' => $uri . ($ajax ? ' [ajax]' : ''), 'status' => $status, 'ms' => round($ms), 'queries' => $n, 'query_ms' => round($q)];
        DB::getEventDispatcher()->forget(Illuminate\Database\Events\QueryExecuted::class);
    }
}

usort($rows, fn ($a, $b) => $b['queries'] <=> $a['queries']);
printf("%-34s %-8s %7s %8s %9s\n", 'ENDPOINT', 'STATUS', 'MS', 'QUERIES', 'QUERY_MS');
foreach ($rows as $r) {
    printf("%-34s %-8s %7d %8d %9d\n", $r['uri'], $r['status'], $r['ms'], $r['queries'], $r['query_ms']);
}
