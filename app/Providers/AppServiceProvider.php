<?php

namespace App\Providers;

use App\Listeners\AuditAuthEvents;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The audit trail's sign in / out / failed sign in entries.
        Event::subscribe(AuditAuthEvents::class);

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
