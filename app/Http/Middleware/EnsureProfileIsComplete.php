<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Holds a signed-in user on "Complete your profile" until they have given the details the
// app needs (see CompleteProfileController). Only accounts that came in through Google
// start incomplete; the page and logout live outside this middleware.
class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->profile_completed_at === null) {
            // A script (AJAX / fetch) cannot follow a page redirect, so it gets a plain 403.
            if ($request->expectsJson()) {
                abort(403, __('Complete your profile first.'));
            }

            return redirect()->route('profile.complete');
        }

        return $next($request);
    }
}
