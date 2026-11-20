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
            'app' => GoogleChat::appName(),
            'url' => (string) (config('services.google_chat.app_url') ?: 'https://chat.google.com'),
            // The link is the app's own page (its Marketplace listing): the button then says Install.
            'listing' => filled(config('services.google_chat.app_url')),
        ];
    }

    // The "synced" indicator of the Profile card: is the person's chat with the Chat app there?
    // Asked by the page after it has loaded (one Google lookup), so opening Profile never waits
    // for Google. `state` is extra to the usual {status,message}: connected | not_added | error |
    // no_google (never signed in with Google, so the app cannot find them). A person found
    // connected for the first time is sent the Welcome message.
    public function googleChatStatus(Request $request)
    {
        $state = $this->googleChatState($request->user());

        if ($state === null) {
            return $this->errorResponse('Google Chat notifications are not switched on.', 404);
        }

        $result = $state['connected']
            ? GoogleChat::directMessageState((string) $request->user()->google_id)
            : 'no_google';

        // The moment the chat exists, the app says hello (once; the first message is never an order).
        if ($result === 'connected') {
            GoogleChat::welcome((int) $request->user()->id, (string) $request->user()->google_id);
        }

        return response()->json(['status' => 'success', 'message' => $result, 'state' => $result]);
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
