<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The markup contract of resources/js/ui/soft-nav.js (sidebar links swap only the page area).
 * The script itself runs in the browser; these tests pin what it relies on in the server's HTML.
 */
class SoftNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_page_has_the_swap_area_and_the_soft_sidebar(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="page-root"', false);
        $response->assertSee('<nav data-soft-nav', false);
        $response->assertSee('name="asset-hash"', false);
        // The page area holds the page, so the swap carries the heading and the main block together.
        $html = $response->getContent();
        $this->assertGreaterThan(strpos($html, 'id="page-root"'), strpos($html, '<main'));
    }

    public function test_a_page_names_its_script_module_with_a_marker_not_a_script_tag(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('companies.index'));

        $response->assertOk();
        $response->assertSee('data-page-module="companies"', false);
        // A <script> inside the swap area would not run after an innerHTML swap, so there is none.
        $html = $response->getContent();
        $root = substr($html, strpos($html, 'id="page-root"'));
        $this->assertStringNotContainsString('<script', substr($root, 0, strpos($root, '</main>')));
    }

    public function test_every_page_module_is_a_default_export_that_a_view_can_name(): void
    {
        $views = '';

        foreach (glob(base_path('resources/views/*/*.blade.php')) as $view) {
            $views .= file_get_contents($view);
        }

        foreach (glob(base_path('resources/js/pages/*.js')) as $file) {
            $source = file_get_contents($file);
            $name = basename($file, '.js');

            $this->assertStringContainsString('export default start;', $source, $name . ' must export its start function.');
            $this->assertStringNotContainsString("addEventListener('DOMContentLoaded', start)", $source, $name . ' must not start itself.');
            $this->assertStringContainsString('<x-page-module name="' . $name . '" />', $views, $name . ' is not named by any view.');
        }
    }

    public function test_signed_out_pages_have_no_swap_area(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('id="page-root"', false);
    }
}
