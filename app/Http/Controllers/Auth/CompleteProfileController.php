<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

// The step after a first "Continue with Google": Google gave us a name and an email, the app
// also needs the company (picked from the Companies list, and a name that is right). Until it is saved the
// `profile.complete` middleware sends the user back here.
class CompleteProfileController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()->profile_completed_at) {
            return redirect()->route('dashboard');
        }

        return view('auth.complete-profile', ['user' => $request->user(), 'companies' => Company::options()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->profile_completed_at) {
            return redirect()->route('dashboard');
        }

        // The company is picked from the list staff keep on the Companies page.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company_id' => Company::rules(),
        ]);

        $user->forceFill($validated + ['profile_completed_at' => now()])->save();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
