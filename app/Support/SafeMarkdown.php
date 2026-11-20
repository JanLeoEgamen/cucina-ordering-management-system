<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Str;

// Admin-written Markdown (the legal pages, the support page) -> HTML that is safe to print unescaped:
// raw HTML in the text is escaped (an admin can write headings, lists, bold and links, never a
// script) and javascript: style links are dropped. {app_name} is filled in AFTER the conversion,
// escaped, so a name with markdown characters in it cannot change the layout.
class SafeMarkdown
{
    public static function render(string $markdown): string
    {
        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return str_replace('{app_name}', e(Setting::appName()), $html);
    }
}
