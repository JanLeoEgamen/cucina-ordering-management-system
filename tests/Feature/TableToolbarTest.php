<?php

namespace Tests\Feature;

use App\Models\OrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TableToolbarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every module with a table: its route, how many sort choices it offers, whether it
     * has a Filter dropdown, and which view tabs it shows.
     *
     * @return array<string, array{string, int, bool, array<int, string>}>
     */
    public static function modules(): array
    {
        return [
            'dishes' => ['dishes.index', 4, true, ['table', 'card']],
            'order sessions' => ['order-sessions.index', 5, true, ['table', 'card', 'calendar']],
            'payment methods' => ['payment-methods.index', 3, true, ['table', 'card']],
            'payments' => ['payments.index', 6, true, ['table', 'card']],
            'roles' => ['roles.index', 3, false, []],
            'users' => ['users.index', 3, true, []],
        ];
    }

    /**
     * @param  array<int, string>  $views
     */
    #[DataProvider('modules')]
    public function test_every_table_page_has_the_search_sort_filter_toolbar(string $route, int $sorts, bool $filter, array $views): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route($route))->assertOk()->getContent();

        // One bar: the field that searches the table (DataTables' own box is off)...
        $this->assertSame(1, substr_count($html, 'data-table-toolbar'));
        $this->assertSame(1, substr_count($html, 'data-table-search'));

        // ...the Sort choices of this module...
        $this->assertSame($sorts, substr_count($html, 'data-table-sort data-col'));
        $this->assertSame(1, substr_count($html, 'data-table-sort-toggle'));

        // ...a Filter dropdown only where there is something to filter...
        $this->assertSame($filter ? 1 : 0, substr_count($html, 'data-table-filter-toggle'));
        $this->assertSame($filter ? 1 : 0, substr_count($html, 'data-table-clear'));

        // ...and the view tabs on the left.
        $this->assertSame(count($views), substr_count($html, 'data-view-mode='));

        foreach ($views as $view) {
            $this->assertStringContainsString('data-view-mode="' . $view . '"', $html);
        }
    }

    public function test_the_bar_is_one_row_and_the_dropdowns_span_it_on_a_phone(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dishes.index'))->assertOk()->getContent();

        // One rounded row at every width (it used to stack on a phone)...
        $this->assertStringContainsString('relative flex min-w-0 items-center rounded-full', $html);
        $this->assertStringNotContainsString('flex-col gap-1.5 rounded-3xl', $html);

        // ...and a dropdown spans the whole bar on a phone (inset-x-0 on the bar, which is the
        // positioning parent), then hangs under its own button from `sm`.
        $this->assertStringContainsString('absolute inset-x-0 top-full', $html);
        $this->assertStringContainsString('sm:inset-x-auto sm:right-0 sm:w-80', $html);
        $this->assertStringContainsString('sm:relative', $html);

        // The sort choice is clipped, and left out on the narrowest phones.
        $this->assertStringContainsString('hidden max-w-[6.5rem] truncate min-[400px]:inline', $html);
    }

    public function test_the_filters_keep_their_ids_inside_the_dropdown(): void
    {
        $admin = $this->superAdmin();

        // The page scripts still find each control by id; only its place changed.
        $this->actingAs($admin)->get(route('dishes.index'))
            ->assertSee('id="dish-status-filter"', false)
            ->assertSee('id="dish-type-filter"', false);

        $this->actingAs($admin)->get(route('order-sessions.index'))->assertSee('id="session-status-filter"', false);
        $this->actingAs($admin)->get(route('payment-methods.index'))->assertSee('id="method-status-filter"', false);
        $this->actingAs($admin)->get(route('users.index'))->assertSee('id="user-role-filter"', false);

        $this->actingAs($admin)->get(route('payments.index'))
            ->assertSee('id="payment-status-filter"', false)
            ->assertSee('id="payment-method-filter"', false)
            ->assertSee('id="payment-session-filter"', false)
            ->assertSee('id="payment-date-from"', false)
            ->assertSee('id="payment-date-to"', false);
    }

    public function test_the_orders_table_of_a_session_has_the_toolbar_too(): void
    {
        $session = OrderSession::factory()->create();

        $html = $this->actingAs($this->superAdmin())
            ->get(route('order-sessions.show', $session->session_id))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-table-toolbar'));
        $this->assertSame(6, substr_count($html, 'data-table-sort data-col'));
        $this->assertStringContainsString('id="order-status-filter"', $html);
        $this->assertStringContainsString('id="payment-status-filter"', $html);
        // The page's own heading sits in the toolbar.
        $this->assertStringContainsString('Orders', $html);
    }
}
