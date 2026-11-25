<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

// The colour theme the whole app is drawn with (System Configuration -> Branding).
//
// A theme is a block of config/themes.php. This class turns the saved one into CSS custom properties
// that tailwind.config.js reads (`indigo-*` = the brand scale, `accent` = the solid fill of primary
// buttons and of the current menu page, `on-accent` = the text on it). The values are Tailwind's
// `rgb(var(--x) / <alpha-value>)` channels ("23 23 15"), so opacity modifiers (`bg-indigo-900/20`) keep working.
// The page's own gray scale never changes: only the brand does.
class Theme
{
    // Every theme: [slug => config block]. A broken config reads as just the default theme.
    public static function all(): array
    {
        $themes = config('themes.themes');

        return is_array($themes) && $themes !== [] ? $themes : [];
    }

    public static function default(): string
    {
        $default = (string) config('themes.default', 'ink');

        return isset(self::all()[$default]) ? $default : (string) array_key_first(self::all());
    }

    public static function exists(string $slug): bool
    {
        return isset(self::all()[$slug]);
    }

    // The saved theme's slug. A theme that was removed from the config reads as the default, so a page
    // never loses its colours. It can never throw: error pages print it (see Setting::brand()).
    public static function current(): string
    {
        try {
            $slug = (string) Setting::get(Setting::THEME_ACCENT);
        } catch (Throwable $error) {
            $slug = '';
        }

        return self::exists($slug) ? $slug : self::default();
    }

    // [slug => label] for the dropdown.
    public static function options(): array
    {
        return array_map(fn (array $theme) => (string) ($theme['label'] ?? ''), self::all());
    }

    // The swatch of a theme for the picker: `paint` is a CSS background value (its accent, or its gradient), and
    // `gradient` says which kind it is. Built from config colours only (see hex()).
    public static function swatches(): array
    {
        return array_map(function (array $theme) {
            $gradient = self::gradient($theme);

            return [
                'paint' => $gradient ? self::linear(self::hex((string) $gradient['from']), self::hex((string) $gradient['to'])) : self::hex((string) $theme['accent']),
                'gradient' => $gradient !== null,
            ];
        }, self::all());
    }

    // The gradient block of a theme, or null for a plain colour.
    protected static function gradient(array $theme): ?array
    {
        $gradient = $theme['gradient'] ?? null;

        return is_array($gradient) && isset($gradient['from'], $gradient['to']) ? $gradient : null;
    }

    // "#2563EB" / "2563eb" -> "#2563eb". Anything that is not a colour reads as black, so a typo in the
    // config can never put text into the CSS this class prints.
    protected static function hex(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? '#' . strtolower($hex) : '#000000';
    }

    // A colour moved 45% of the way to black: the deep shade the big dark panels (the sign in panel, the welcome hero, the
    // dashboard card) are drawn in. Mixing keeps the hue, so the panel stays visibly the theme's colour; the lowest Tailwind
    // shade would be nearly black for most colours. White text on it stays far above 4.5:1.
    public static function deepen(string $hex): string
    {
        $channels = array_map('intval', explode(' ', self::channels($hex)));

        return sprintf('#%02x%02x%02x', ...array_map(fn (int $value) => (int) round($value * 0.55), $channels));
    }

    protected static function linear(string $from, string $to): string
    {
        return 'linear-gradient(135deg,' . $from . ',' . $to . ')';
    }

    // "#2563eb" -> "37 99 235". An invalid colour reads as black rather than breaking the page.
    public static function channels(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '0 0 0';
        }

        return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
    }

    // The CSS of one theme: the light variables on :root, the dark accent on html.dark. Only colours
    // from config/themes.php get in (never a request value), run through channels().
    public static function css(?string $slug = null): string
    {
        $slug = $slug !== null && self::exists($slug) ? $slug : self::current();
        $theme = self::all()[$slug] ?? null;

        if ($theme === null) {
            return '';
        }

        $light = '';

        foreach ($theme['scale'] as $shade => $hex) {
            $light .= '--brand-' . (int) $shade . ':' . self::channels((string) $hex) . ';';
        }

        $light .= '--accent:' . self::channels((string) $theme['accent']) . ';'
            . '--accent-hover:' . self::channels((string) $theme['accent_hover']) . ';'
            . '--accent-on:' . self::channels((string) $theme['accent_on']) . ';'
            . '--hero:' . self::channels((string) ($theme['hero'] ?? self::deepen((string) $theme['accent']))) . ';';

        $dark = '--accent:' . self::channels((string) $theme['dark_accent']) . ';'
            . '--accent-hover:' . self::channels((string) $theme['dark_accent_hover']) . ';'
            . '--accent-on:' . self::channels((string) $theme['dark_accent_on']) . ';';

        // A gradient theme paints every solid accent surface (`bg-accent`, `bg-indigo-600`) and the hero panels with an
        // IMAGE on top of the colour above (app.css). A plain theme says `none` explicitly, so previewing it over a
        // gradient one (the settings page) really removes the gradient. The hero panels are dark on their own in dark
        // mode (`dark:bg-gray-800`), so they never get the gradient there.
        $gradient = self::gradient($theme);

        if ($gradient) {
            $light .= '--accent-image:' . self::linear(self::hex((string) $gradient['from']), self::hex((string) $gradient['to'])) . ';'
                . '--hero-image:' . self::linear(self::hex((string) ($gradient['hero_from'] ?? self::deepen((string) $gradient['from']))), self::hex((string) ($gradient['hero_to'] ?? self::deepen((string) $gradient['to'])))) . ';'
                . '--accent-hover-filter:brightness(1.1);';
            $dark .= '--accent-image:' . self::linear(self::hex((string) ($gradient['dark_from'] ?? $gradient['from'])), self::hex((string) ($gradient['dark_to'] ?? $gradient['to']))) . ';';
        } else {
            $light .= '--accent-image:none;--hero-image:none;--accent-hover-filter:none;';
        }

        $dark .= '--hero-image:none;';

        return ':root{' . $light . '}html.dark{' . $dark . '}';
    }

    // The CSS of every theme by slug: the settings page swaps one in to preview a choice before it is saved.
    public static function previews(): array
    {
        $previews = [];

        foreach (array_keys(self::all()) as $slug) {
            $previews[$slug] = self::css($slug);
        }

        return $previews;
    }
}
