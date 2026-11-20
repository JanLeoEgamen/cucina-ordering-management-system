<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SafeMarkdown;

// The two public legal pages: the privacy policy (GET /privacy-policy) and the terms of service of
// ordering (GET /terms-of-service). Anyone may read them (the sign in and sign up pages link to them, so they
// cannot sit behind a login). Their text is System Configuration -> Privacy policy / Terms of service.
class LegalPageController extends Controller
{
    // The page's name in the title, the footer links and the menu (the route default `page` picks it).
    public const TITLES = [
        'privacy-policy' => 'Privacy Policy',
        'terms-of-service' => 'Terms of Service',
    ];

    public function show(string $page)
    {
        abort_unless(isset(Setting::LEGAL_PAGES[$page]), 404);

        $content = Setting::legalPage($page);

        return view('legal', [
            'title' => __(self::TITLES[$page]),
            'html' => SafeMarkdown::render($content['content']),
            'contactEmail' => $content['contact_email'],
            'updatedAt' => $content['updated_at'],
        ]);
    }
}
