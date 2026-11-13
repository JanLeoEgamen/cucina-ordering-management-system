<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// The step after a first "Continue with Google": Google gave us a name and an email, the app
// also needs the company (and a name that is right). Until it is saved the
// `profile.complete` middleware sends the user back here.
class CompleteProfileController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->profile_completed_at) {
            return redirect()->route('dashboard');
        }

        return view('auth.complete-profile', ['user' => $request->user()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->profile_completed_at) {
            return redirect()->route('dashboard');
        }

        // Company is required here, unlike the optional field on the register form: this
        // is the one moment we are asking for it.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['required', 'string', 'max:255'],
        ]);

        $user->forceFill($validated + ['profile_completed_at' => now()])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
