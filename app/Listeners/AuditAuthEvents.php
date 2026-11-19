<?php

namespace App\Listeners;

use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;

// Sign in, sign out and everything that goes wrong around them, from Laravel's own auth events
// (so Breeze, Google sign-in and any future way in are all covered the same). Registered in
// AppServiceProvider with Event::subscribe(). Each handler is inside rescue(): a failure to
// write the entry is logged and never stops someone from signing in or out.
class AuditAuthEvents
{
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            Lockout::class => 'onLockout',
            Registered::class => 'onRegistered',
            PasswordReset::class => 'onPasswordReset',
        ];
    }

    public function onLogin(Login $event): void
    {
        rescue(function () use ($event) {
            $google = request()->routeIs('google.callback');

            Audit::auth($google ? 'auth.login.google' : 'auth.login', $google ? 'Signed in with Google' : 'Signed in', $event->user);
        });
    }

    public function onLogout(Logout $event): void
    {
        rescue(fn () => Audit::auth('auth.logout', 'Signed out', $event->user));
    }

    // A wrong password or an unknown address. Only the address typed is kept, never the password.
    public function onFailed(Failed $event): void
    {
        rescue(function () use ($event) {
            $email = (string) ($event->credentials['email'] ?? '');

            Audit::auth('auth.failed', 'Failed sign in' . ($email !== '' ? ' for ' . $email : ''), $event->user, 'failed', $email !== '' ? $email : null, 'The credentials did not match.');
        });
    }

    // Too many wrong tries: the rate limiter said stop.
    public function onLockout(Lockout $event): void
    {
        rescue(function () use ($event) {
            $email = (string) $event->request->input('email', '');

            Audit::auth('auth.lockout', 'Locked out after too many sign in attempts' . ($email !== '' ? ' for ' . $email : ''), null, 'denied', $email !== '' ? $email : null, 'Too many attempts.');
        });
    }

    public function onRegistered(Registered $event): void
    {
        rescue(fn () => Audit::auth('auth.registered', 'Created an account', $event->user));
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        rescue(fn () => Audit::auth('auth.password-reset', 'Reset their password', $event->user));
    }
}
