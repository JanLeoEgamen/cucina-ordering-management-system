<?php

namespace App\Providers;

use App\Auth\CachedUserProvider;
use App\Listeners\AuditAuthEvents;
use App\Support\TableCache;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->sendConnectionSetupOnce();
    }

    // With DB_PERSISTENT a PHP worker keeps its MySQL connection between web requests, but Laravel
    // still re-sends `SET NAMES ... , sql_mode` on every request: one more network trip each time
    // (~0.5 s to a database on another machine). Here the same settings are sent ONCE, as the init
    // command of the physical connection, and Laravel is told to skip them. Web only: the console
    // opens a fresh connection per command, and migrations want the normal charset clause.
    private function sendConnectionSetupOnce(): void
    {
        $config = config('database.connections.mysql');

        if ($this->app->runningInConsole() || ! is_array($config) || empty($config['options'][\PDO::ATTR_PERSISTENT])) {
            return;
        }

        $init = sprintf(
            "SET NAMES '%s' COLLATE '%s', SESSION sql_mode='%s'",
            $config['charset'] ?? 'utf8mb4',
            $config['collation'] ?? 'utf8mb4_unicode_ci',
            'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        );

        config([
            'database.connections.mysql.options' => $config['options'] + [\PDO::MYSQL_ATTR_INIT_COMMAND => $init],
            'database.connections.mysql.charset' => null,
            'database.connections.mysql.collation' => null,
            'database.connections.mysql.strict' => null,
            // The database name is already in the connection string: Laravel's extra `use db` is one more trip.
            'database.connections.mysql.use_db_after_connecting' => false,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Cached reads (the signed-in user, master data) are dropped by the write that changes their tables.
        Auth::provider('cached-eloquent', fn ($app, array $config) => new CachedUserProvider($app['hash'], $config['model']));
        TableCache::watch();

        // The audit trail's sign in / out / failed sign in entries.
        Event::subscribe(AuditAuthEvents::class);

        // The Google Chat sign-in prompt asks once per login (GoogleChat::shouldPrompt). The
        // "already asked" flag has to go on every sign-in, or someone who signs in again without
        // signing out in between (the session is kept) would not be asked again until synced.
        Event::listen(Login::class, function () {
            if (app()->bound('session.store')) {
                session()->forget('google_chat_checked');
            }
        });

        // The one password rule: register, reset password and change password all call
        // Password::defaults(). At least 8 characters with a letter, a number and a special
        // character. resources/js/ui/password-field.js shows the same rules live (keep them in
        // step: the same Unicode classes). No uncompromised(): it calls an outside service.
        Password::defaults(fn () => Password::min(8)->letters()->numbers()->symbols());

        // Public sign-up: per IP, a short burst cap and an hourly cap, so a script cannot mass-create accounts.
        // An invalid attempt counts too (the point is to slow flooding). Behind a proxy the IP is the client's
        // only if TRUSTED_PROXIES is set (see security.md).
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);
    }
}
