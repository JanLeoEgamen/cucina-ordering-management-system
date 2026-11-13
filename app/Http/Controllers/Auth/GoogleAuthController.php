<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

// "Continue with Google": sign in, or sign up, with a Google account. Off until
// GOOGLE_CLIENT_ID is set, so a fresh install shows no dead button.
class GoogleAuthController extends Controller
{
    // Send the visitor to Google's consent screen.
    public function redirect(): SymfonyRedirect
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    // Google sends the visitor back here with a code; swap it for their profile.
    public function callback(): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Denied consent, an expired state (back button), Google down: all end up here.
            report($e);

            return $this->back(__('Google sign-in did not complete. Please try again.'));
        }

        // An unverified address proves nothing, and we match accounts on the email.
        $raw = (array) ($google->user ?? []);

        if (! filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || ! $google->getEmail()) {
            return $this->back(__('Your Google email address is not verified.'));
        }

        $email = Str::lower($google->getEmail());
        $googleId = (string) $google->getId();

        // withTrashed: users.email is unique even for soft deleted rows, so a removed
        // account must be refused here, not hit by a duplicate insert below.
        $user = User::withTrashed()->where('google_id', $googleId)->first()
            ?? User::withTrashed()->where('email', $email)->first();

        if ($user?->trashed()) {
            return $this->back(__('This account has been deactivated. Contact an administrator.'));
        }

        // Same email, but already tied to a different Google account: not ours to take over.
        if ($user && $user->google_id && $user->google_id !== $googleId) {
            return $this->back(__('This email is linked to a different Google account.'));
        }

        $isNew = ! $user;

        if ($isNew) {
            // New accounts get no role, like /register (see security.md). The password is
            // random and unknown: they use Google, or "Forgot your password?" to set one.
            $user = (new User)->forceFill([
                'first_name' => $raw['given_name'] ?? Str::before((string) $google->getName(), ' '),
                'last_name' => $raw['family_name'] ?? '',
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
                'google_id' => $googleId,
                'email_verified_at' => now(),
            ]);
            $user->save();

            event(new Registered($user));
        } elseif (! $user->google_id) {
            // First Google sign-in of an existing account: link it.
            $user->forceFill(['google_id' => $googleId])->save();
        }

        Auth::login($user);
        request()->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    // Whether the button shows and the routes answer.
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
