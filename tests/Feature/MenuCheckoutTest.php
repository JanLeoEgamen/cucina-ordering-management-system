<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_bar_offers_search_sort_and_filters(): void
    {
        $session = OrderSession::factory()->create();
        SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create()->dish_id,
        ]);

        $this->actingAs($this->userWith('view menu'))
            ->get(route('menu.index'))
            ->assertOk()
            // One bar: search, then the Sort and Filter dropdowns.
            ->assertSee('id="menu-search"', false)
            ->assertSee('id="sort-toggle"', false)
            ->assertSee('id="sort-panel"', false)
            ->assertSee('id="filter-toggle"', false)
            ->assertSee('id="filter-panel"', false)
            // Every way to sort...
            ->assertSee('data-sort="default"', false)
            ->assertSee('data-sort="price-asc"', false)
            ->assertSee('data-sort="price-desc"', false)
            ->assertSee('data-sort="name"', false)
            ->assertSee('data-sort="calories"', false)
            ->assertSee('data-sort="stock"', false)
            // ...and every way to narrow: availability, cart, price, calories, category.
            ->assertSee('id="filter-in-stock"', false)
            ->assertSee('id="filter-in-cart"', false)
            ->assertSee('id="filter-min-price"', false)
            ->assertSee('id="filter-max-price"', false)
            ->assertSee('id="filter-min-calories"', false)
            ->assertSee('id="filter-max-calories"', false)
            ->assertSee('data-filter-type', false);
    }

    public function test_a_dish_card_is_a_photo_first_card_with_its_stock_state(): void
    {
        $session = OrderSession::factory()->create();

        foreach ([['Plenty', 20], ['Almost gone', 2], ['Finished', 0]] as [$name, $stock]) {
            SessionDish::factory()->create([
                'session_id' => $session->session_id,
                'dish_id' => Dish::factory()->create(['dish_name' => $name, 'image' => 'https://example.test/' . $name . '.jpg'])->dish_id,
                'available_stock' => $stock,
            ]);
        }

        $html = $this->actingAs($this->userWith('view menu'))->get(route('menu.index'))->assertOk()->getContent();

        // The photo fills the card and zooms on hover; the text sits on a gradient over it.
        $this->assertStringContainsString('group-hover:scale-110', $html);
        $this->assertStringContainsString('bg-gradient-to-t from-black/80', $html);
        $this->assertStringContainsString('aspect-[5/4]', $html);

        // Stock: a dot level for each dish, and a sold-out flag the script keeps in step.
        $this->assertSame(1, substr_count($html, 'data-level="ok"'));
        $this->assertSame(1, substr_count($html, 'data-level="low"'));
        $this->assertSame(1, substr_count($html, 'data-level="out"'));
        $this->assertSame(1, substr_count($html, 'data-sold-out="true"'));
        $this->assertSame(2, substr_count($html, 'data-sold-out="false"'));
    }

    public function test_the_checkout_error_banner_is_pinned_so_it_stays_in_view_while_scrolling(): void
    {
        $session = OrderSession::factory()->create();
        SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create()->dish_id,
        ]);

        $this->actingAs($this->userWith('view menu'))
            ->get(route('menu.index'))
            ->assertOk()
            ->assertSee('id="checkout-errors"', false)
            // Sticky inside the scrolling body, and announced at once.
            ->assertSee('sticky top-0 z-10', false)
            ->assertSee('aria-live="assertive"', false)
            // The proof drop zone is what the script shakes into view.
            ->assertSee('id="proof-drop-zone"', false);
    }
}
