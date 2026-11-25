<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The colour theme (System Configuration -> Branding -> Colour theme): a choice among config/themes.php,
// printed on every page as CSS variables that tailwind.config.js reads.
class ThemeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return $overrides + ['session_start_time' => '08:30', 'session_end_time' => '13:00'];
    }

    public function test_the_original_ink_look_is_the_default(): void
    {
        $this->assertSame('ink', Theme::default());
        $this->assertSame('ink', Theme::current());

        // Ink in the light page, mist in the dark one: what the app always looked like.
        $css = Theme::css();

        $this->assertStringContainsString('--brand-600:23 23 15;', $css);
        $this->assertStringContainsString('--accent:23 23 15;', $css);
        $this->assertStringContainsString('html.dark{--accent:232 230 225;', $css);
    }

    public function test_every_theme_in_the_config_is_complete_and_readable(): void
    {
        $this->assertGreaterThanOrEqual(2, count(Theme::all()));

        foreach (Theme::all() as $slug => $theme) {
            $this->assertNotSame('', (string) $theme['label'], $slug);

            foreach ([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950] as $shade) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $theme['scale'][$shade], $slug . ' ' . $shade);
            }

            foreach (['accent', 'accent_hover', 'accent_on', 'dark_accent', 'dark_accent_hover', 'dark_accent_on'] as $key) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $theme[$key], $slug . ' ' . $key);
            }

            // The text on a solid button must stay readable (WCAG AA for normal text), light and dark.
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($theme['accent'], $theme['accent_on']), $slug . ' light button');
            $this->assertGreaterThanOrEqual(4.5, $this->contrast($theme['dark_accent'], $theme['dark_accent_on']), $slug . ' dark button');

            // A gradient has TWO ends and the text sits on both of them.
            if (isset($theme['gradient'])) {
                $gradient = $theme['gradient'];

                $this->assertSame($theme['accent'], $gradient['from'], $slug . ' the accent is the first colour of the gradient');
                $this->assertGreaterThanOrEqual(4.5, $this->contrast($gradient['to'], $theme['accent_on']), $slug . ' light gradient end');
                $this->assertGreaterThanOrEqual(4.5, $this->contrast($gradient['dark_to'], $theme['dark_accent_on']), $slug . ' dark gradient end');
            }
        }
    }

    public function test_a_gradient_theme_paints_the_accent_with_an_image_and_a_plain_one_removes_it(): void
    {
        $gradient = array_filter(Theme::all(), fn (array $theme) => isset($theme['gradient']));

        $this->assertGreaterThanOrEqual(2, count($gradient));

        foreach ($gradient as $slug => $theme) {
            $css = Theme::css($slug);

            $this->assertStringContainsString('--accent-image:linear-gradient(135deg,' . $theme['gradient']['from'] . ',' . $theme['gradient']['to'] . ');', $css, $slug);
            // Dark mode has its own (lighter) ends, and the hero panels are never a gradient there.
            $this->assertStringContainsString('html.dark{', $css);
            $this->assertStringContainsString($theme['gradient']['dark_from'] . ',' . $theme['gradient']['dark_to'], $css, $slug);
            $this->assertStringContainsString('--hero-image:none;', $css, $slug);
            $this->assertTrue(Theme::swatches()[$slug]['gradient']);
            $this->assertStringStartsWith('linear-gradient(', Theme::swatches()[$slug]['paint']);
        }

        // Previewing a plain theme over a gradient one must switch the image off again.
        $this->assertStringContainsString('--accent-image:none;', Theme::css('ink'));
        $this->assertFalse(Theme::swatches()['ink']['gradient']);
        $this->assertSame('#17170f', Theme::swatches()['ink']['paint']);
    }

    // The big dark panels (sign in panel, welcome hero, dashboard card) are the theme's colour DEEPENED, not the nearly black
    // lowest Tailwind shade: that is what made every theme fade into black at the bottom of the sign in panel.
    public function test_the_dark_panels_are_a_rich_shade_of_the_theme_not_black(): void
    {
        $this->assertSame('#024230', Theme::deepen('#047857'));
        $this->assertSame('#000000', Theme::deepen('not a colour'));

        foreach (Theme::all() as $slug => $theme) {
            if ($slug === 'ink') {
                continue;
            }

            preg_match('/--hero:(\d+) (\d+) (\d+);/', Theme::css($slug), $hero);

            $this->assertGreaterThan(30, max((int) $hero[1], (int) $hero[2], (int) $hero[3]), $slug . ' panel is almost black');
            // ... and white text on it stays easy to read.
            $this->assertGreaterThanOrEqual(7, $this->contrast(sprintf('#%02x%02x%02x', $hero[1], $hero[2], $hero[3]), '#ffffff'), $slug . ' panel text');
        }

        // The default look keeps its ink.
        $this->assertStringContainsString('--hero:23 23 15;', Theme::css('ink'));
    }

    public function test_a_saved_theme_reaches_every_page_head_even_the_public_ones(): void
    {
        Setting::setThemeAccent('emerald');

        $block = '--brand-600:' . Theme::channels(config('themes.themes.emerald.scale.600')) . ';';

        foreach ([route('login'), route('support')] as $url) {
            $this->get($url)->assertOk()->assertSee('id="theme-vars"', false)->assertSee($block, false);
        }

        $this->actingAs($this->userWith('view menu'))->get(route('menu.index'))->assertOk()->assertSee($block, false);
    }

    public function test_a_theme_that_was_removed_from_the_config_falls_back_to_the_default(): void
    {
        Setting::set(Setting::THEME_ACCENT, 'does-not-exist');

        $this->assertSame('ink', Theme::current());
        $this->assertStringContainsString('--accent:23 23 15;', Theme::css());
        $this->get(route('login'))->assertOk()->assertSee('--accent:23 23 15;', false);
    }

    public function test_the_colour_function_never_breaks_on_a_bad_value(): void
    {
        $this->assertSame('37 99 235', Theme::channels('#2563eb'));
        $this->assertSame('255 255 255', Theme::channels('fff'));
        $this->assertSame('0 0 0', Theme::channels('red; } body { display:none'));
    }

    public function test_the_settings_page_offers_every_theme_with_the_saved_one_picked(): void
    {
        Setting::setThemeAccent('rose');

        $html = $this->actingAs($this->userWith('view settings', 'edit settings'))->get(route('settings.index'))
            ->assertOk()->assertSee('id="theme-accent"', false)->getContent();

        foreach (array_keys(Theme::all()) as $slug) {
            $this->assertStringContainsString('value="' . $slug . '"', $html);
            $this->assertStringContainsString('data-theme-swatch="' . $slug . '"', $html);
        }

        $this->assertMatchesRegularExpression('/<option value="rose"\s+selected/', $html);
    }

    public function test_saving_stores_the_theme_and_the_next_page_uses_it(): void
    {
        $this->actingAs($this->userWith('view settings', 'edit settings'))
            ->putJson(route('settings.update'), $this->payload(['theme_accent' => 'violet']))
            ->assertOk();

        $this->assertSame('violet', Setting::get(Setting::THEME_ACCENT));
        // The signed-in user sees it on the next page too (the sign in page would redirect them away).
        $this->get(route('dashboard'))->assertSee('--brand-600:' . Theme::channels(config('themes.themes.violet.scale.600')) . ';', false);
    }

    public function test_an_unknown_theme_is_refused_and_nothing_changes(): void
    {
        Setting::setThemeAccent('blue');

        $this->actingAs($this->userWith('view settings', 'edit settings'))
            ->putJson(route('settings.update'), $this->payload(['theme_accent' => 'hotpink']))
            ->assertStatus(422)->assertJsonValidationErrors('theme_accent');

        // An empty value is refused too (the form always sends one).
        $this->putJson(route('settings.update'), $this->payload(['theme_accent' => '']))->assertStatus(422);

        $this->assertSame('blue', Setting::get(Setting::THEME_ACCENT));
    }

    public function test_a_caller_that_leaves_the_theme_out_changes_nothing(): void
    {
        Setting::setThemeAccent('teal');

        $this->actingAs($this->userWith('view settings', 'edit settings'))
            ->putJson(route('settings.update'), $this->payload())->assertOk();

        $this->assertSame('teal', Setting::get(Setting::THEME_ACCENT));
    }

    public function test_only_people_who_may_edit_settings_change_the_theme(): void
    {
        Setting::setThemeAccent('amber');

        $this->actingAs($this->userWith('view settings'))
            ->putJson(route('settings.update'), $this->payload(['theme_accent' => 'blue']))->assertForbidden();

        $this->assertSame('amber', Setting::get(Setting::THEME_ACCENT));
    }

    // WCAG contrast ratio of two #rrggbb colours.
    private function contrast(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $channels = array_map(function (string $part): float {
                $value = hexdec($part) / 255;

                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        [$light, $dark] = [max($luminance($a), $luminance($b)), min($luminance($a), $luminance($b))];

        return ($light + 0.05) / ($dark + 0.05);
    }
}
