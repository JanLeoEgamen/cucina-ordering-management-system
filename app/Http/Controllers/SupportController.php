<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SafeMarkdown;

// The public Help and Support page. Anyone may read it (visitors who cannot sign in need it most), so
// it sits outside `auth`. Its text and contact details are System Configuration -> Support.
class SupportController extends Controller
{
    public function __invoke()
    {
        $support = Setting::support();

        return view('support', [
            'html' => SafeMarkdown::render($support['content']),
            'email' => $support['email'],
            'phone' => $support['phone'],
            // tel: wants digits and a leading +, not the spaces and dashes people type.
            'phoneHref' => preg_replace('/[^0-9+]/', '', $support['phone']),
            'hours' => $support['hours'],
        ]);
    }
}
