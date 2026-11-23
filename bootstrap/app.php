<?php

use App\Http\Middleware\AuditTrail;
use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\ServerTiming;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // The controllers gate their methods with `permission:<name>` (see the
        // HasMiddleware interface), so the aliases are registered here: the
        // package does not register them by itself.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'profile.complete' => EnsureProfileIsComplete::class,
        ]);

        // The audit trail: last in the `web` group so the session (and the signed-in user) is
        // ready, and it covers every route of the group, present and future.
        $middleware->appendToGroup('web', AuditTrail::class);

        // Opt-in `Server-Timing` header (SERVER_TIMING=true): first, so it wraps everything else.
        $middleware->prependToGroup('web', ServerTiming::class);

        // Behind a load balancer the app only sees the balancer's address: trust its
        // X-Forwarded-* headers so the client IP (login throttling!), the host and
        // https URLs are right. Off unless TRUSTED_PROXIES is set ("*" or a comma list of
        // IPs / CIDRs): trusting everyone on a server that faces the internet directly would
        // let any client forge its IP. Read from the real environment on purpose: with
        // `config:cache` the .env file is not loaded here (docker-compose passes it as env vars).
        $proxies = env('TRUSTED_PROXIES');

        if (is_string($proxies) && trim($proxies) !== '') {
            $middleware->trustProxies(
                at: trim($proxies) === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            );
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
