<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleChat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'googleChat' => $this->googleChatState($request->user()),
        ]);
    }

    // What the Profile page's Google Chat card needs, or null when the card is not shown: it only
    // exists while the switch in System Configuration is on and the Chat key is set up, so nobody
    // is told to add an app that nothing talks to.
    protected function googleChatState(User $user): ?array
    {
        // The key file is a free check; the setting costs a query, so it comes second (a test guards the number of settings queries per request).
        if (! GoogleChat::configured() || ! Setting::googleChatNotifications()) {
            return null;
        }

        return [
            // Has signed in with Google once: the app finds them by that account's id.
            'connected' => ! empty($user->google_id),
            'app' => (string) (config('services.google_chat.app_name') ?: Setting::appName()),
            'url' => (string) (config('services.google_chat.app_url') ?: 'https://chat.google.com'),
        ];
    }

    // "Send me a test message": checks the person's own chat with the Chat app and sends them one
    // card. The answer is in plain words and never shows the server's setup (keys, account names).
    public function googleChatTest(Request $request)
    {
        $state = $this->googleChatState($request->user());

        if ($state === null) {
            return $this->errorResponse('Google Chat notifications are not switched on.', 404);
        }

        if (! $state['connected']) {
            return $this->errorResponse('Sign in once with "Continue with Google" using this email first, so the app knows your Google account.', 422);
        }

        foreach (GoogleChat::diagnose((string) $request->user()->google_id, true, false) as [$label, $ok, $detail]) {
            if ($ok) {
                continue;
            }

            if ($label === 'Direct message space' && str_contains($detail, 'HTTP 404')) {
                return $this->errorResponse(__('We cannot find your chat with :app yet. Open Google Chat, search for :app, open it and say hi, then try again.', ['app' => $state['app']]), 422);
            }

            return $this->errorResponse('The test message could not be sent right now. Please tell the staff.', 422);
        }

        return $this->successResponse(__('Sent! Check your chat with :app in Google Chat.', ['app' => $state['app']]));
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($request->user()->id)],
        ]);

        $request->user()->fill($validated);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        // The self-service route must not be a way around the Super Admin rule.
        if (User::isSuperAdmin((int) $user->id)) {
            return Redirect::route('profile.edit')->withErrors(['password' => 'A Super Admin account cannot be deleted.'], 'userDeletion');
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
