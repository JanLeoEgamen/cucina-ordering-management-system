<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

// Opt-in diagnostics for "why is this request slow on the server?" (SERVER_TIMING=true in .env).
// It adds a `Server-Timing` header that Chrome / Edge DevTools print in the Network tab, under
// Timing -> Server Timing:
//
//   total  = PHP time from the start of the script to the finished response
//   db     = time inside SQL statements, with how many ran
//
// The request's "Waiting for server response" minus `total` is time spent BEFORE PHP started (the web
// server's queue, TLS, a busy PHP pool): a big gap means the host, not this code.
//
// The work done AFTER the response (the audit trail, cache versions) cannot be in a header, so a
// slow one is written to the log instead. Under PHP-FPM the browser has already left by then; under
// mod_php / CGI it keeps waiting, which shows up as a long "Content download" for a tiny reply.
//
// Off by default, and only people who can read the audit trail get the header.
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.server_timing')) {
            return $next($request);
        }

        $queries = 0;
        $dbMs = 0.0;

        DB::listen(function ($query) use (&$queries, &$dbMs) {
            $queries++;
            $dbMs += $query->time;
        });

        $response = $next($request);

        $ready = microtime(true);
        $started = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', $ready);
        $total = ($ready - $started) * 1000;

        if ($request->user()?->can('view audit-logs')) {
            $response->headers->set('Server-Timing', sprintf('total;dur=%.0f, db;dur=%.0f;desc="%d queries"', $total, $dbMs, $queries));
        }

        app()->terminating(function () use ($ready, $request, $total) {
            $after = (microtime(true) - $ready) * 1000;

            if ($after > 200) {
                Log::warning(sprintf('Slow after-response work: %.0f ms after a %.0f ms request (%s /%s)', $after, $total, $request->method(), $request->path()));
            }
        });

        return $response;
    }
}
