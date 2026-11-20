<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Str;

// The two public legal pages: the privacy policy (GET /privacy) and the terms of service of
// ordering (GET /terms). Anyone may read them (the sign in and sign up pages link to them, so they
// cannot sit behind a login). Their text is System Configuration -> Privacy policy / Terms of service.
class LegalPageController extends Controller
{
    // The page's name in the title, the footer links and the menu (the route default `page` picks it).
    public const TITLES = [
        'privacy' => 'Privacy Policy',
        'terms' => 'Terms of Service',
    ];

    public function show(string $page)
    {
        abort_unless(isset(Setting::LEGAL_PAGES[$page]), 404);

        $content = Setting::legalPage($page);

        return view('legal', [
            'title' => __(self::TITLES[$page]),
            'html' => $this->render($content['content']),
            'contactEmail' => $content['contact_email'],
            'updatedAt' => $content['updated_at'],
        ]);
    }

    // Markdown -> HTML, safe to print unescaped: raw HTML in the text is escaped (an admin can
    // write headings, lists, bold and links, never a script) and javascript: style links are dropped.
    // {app_name} is filled in AFTER the conversion, escaped, so a name with markdown characters
    // in it cannot change the layout.
    protected function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return str_replace('{app_name}', e(Setting::appName()), $html);
    }
}
