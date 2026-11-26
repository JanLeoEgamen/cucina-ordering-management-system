<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\GoogleChat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            // Active companies, plus the person's own if it was switched off since.
            'companies' => Company::options($request->user()->company_id),
            'googleChat' => $this->googleChatState($request->user()),
        ]);
    }

    // What the Profile card needs: see GoogleChat::pageState().
    protected function googleChatState(User $user): ?array
    {
        return GoogleChat::pageState($user);
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
        $welcomed = $result === 'connected'
            && GoogleChat::welcome((int) $request->user()->id, (string) $request->user()->google_id);

        // Asked once per login: the sign-in prompt does not ask again on the next page. A new
        // sign-in clears this (AppServiceProvider), so it asks again until the person is synced.
        session(['google_chat_checked' => true]);

        return response()->json(['status' => 'success', 'message' => $result, 'state' => $result, 'welcomed' => $welcomed]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // The person's current company stays valid even if it was switched off since.
            'company_id' => Company::rules($request->user()->company_id),
            // Required for every company except Guest (an old account without one is asked on its next save).
            'employee_id' => Company::employeeIdRules($request->input('company_id')),
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($request->user()->id)],
        ]);

        // A Guest has none: moving to Guest clears it.
        $validated['employee_id'] = Company::employeeIdFor((int) $validated['company_id'], $validated['employee_id'] ?? null);

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

        // A pending order still holds plates and may be waiting for its money, so the account
        // stays until staff (or the customer, where allowed) have closed it. Pre-orders are not
        // counted: nothing can complete or cancel one yet (known-issues D2).
        if (Order::userHasPending((int) $user->id)) {
            return Redirect::route('profile.edit')->withErrors(['password' => 'You have a pending order. You can delete your account after it is completed or cancelled.'], 'userDeletion');
        }

        Auth::logout();

        // The same soft delete as the Users page: it also drops the account's roles.
        User::deleteUser((int) $user->id);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
