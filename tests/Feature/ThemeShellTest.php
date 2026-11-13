<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_menu_shows_who_is_signed_in_once_without_calling_an_avatar_service(): void
    {
        $user = $this->superAdmin();
        $user->update(['first_name' => 'Alex', 'last_name' => 'Morgan']);

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Alex Morgan')
            ->assertSee(Role::SUPER_ADMIN)
            // The initials in a circle replace the picture from an external avatar
            // service, so the user's name no longer leaves this server.
            ->assertSee('>AM</span>', false)
            ->assertDontSee('ui-avatars.com', false)
            ->getContent();

        // The identity card lives in the account menu only (one per top bar: the phone's
        // and the desktop's); the sidebar does not repeat it.
        $this->assertSame(2, substr_count($html, 'Alex Morgan</p>'));
        $this->assertStringNotContainsString('sidebar-user', $html);
    }

    public function test_a_user_without_a_role_gets_the_card_without_a_role_chip(): void
    {
        $user = User::factory()->create(['first_name' => 'Bea', 'last_name' => 'Cruz']);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Bea Cruz')
            ->assertDontSee(Role::SUPER_ADMIN);
    }

    public function test_the_dashboard_shows_only_the_places_the_user_may_open(): void
    {
        $this->actingAs($this->userWith('view menu', 'view payments'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('menu.index'), false)
            ->assertSee(route('payments.index'), false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('roles.index'), false);

        $this->actingAs($this->userWith())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Nothing to open yet');
    }

    public function test_the_dashboard_counts_the_payments_waiting_and_shows_the_live_session(): void
    {
        $session = OrderSession::factory()->create();
        $order = Order::factory()->create(['session_id' => $session->session_id]);
        Payment::factory()->create(['order_id' => $order->order_id, 'payment_status' => 'pending']);

        $this->assertSame(1, Payment::pendingCount());

        $this->actingAs($this->userWith('view payments', 'view order-sessions'))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Waiting for a decision')
            ->assertSee('pending payment')
            ->assertSee(route('order-sessions.show', $session->session_id), false);

        // Someone who may not check payments is not shown the count.
        $this->actingAs($this->userWith('view menu'))
            ->get(route('dashboard'))
            ->assertDontSee('Waiting for a decision');
    }

    public function test_status_badges_are_one_gray_pill_with_the_state_in_data_status(): void
    {
        $dish = Dish::factory()->create(['is_active' => true]);

        $row = $this->actingAs($this->userWith('view dishes'))
            ->getJson(route('dishes.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0');

        // The colour is a dot drawn by CSS from data-status, not a coloured pill.
        $this->assertStringContainsString('data-status="active"', $row['is_active']);
        $this->assertStringContainsString('bg-gray-100', $row['is_active']);
        $this->assertStringNotContainsString('bg-green', $row['is_active']);
        $this->assertSame($dish->dish_id, $row['dish_id']);
    }

    public function test_the_app_has_a_dark_light_switch_in_the_top_right_of_both_bars(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        // Two switches, one per top bar (the phone's and the desktop's; only one shows): the
        // round icon next to the bell. The sidebar no longer has a labelled row.
        $this->assertSame(2, substr_count($html, 'data-theme-toggle'));
        $this->assertStringContainsString('Switch between dark and light mode', $html);
        $this->assertStringNotContainsString('sidebar-link flex w-full items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-left', $html);

        // The theme is set before first paint, from the saved choice or the OS.
        $this->assertStringContainsString("localStorage.getItem('cucina.theme')", $html);
        $this->assertStringContainsString("classList.toggle('dark', dark)", $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, "localStorage.getItem('cucina.theme')"));
    }

    public function test_the_login_page_has_the_switch_and_the_before_paint_script_too(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-theme-toggle', false)
            ->assertSee("localStorage.getItem('cucina.theme')", false);
    }

    public function test_every_add_button_floats_on_a_phone_and_sits_in_the_header_from_sm(): void
    {
        $admin = $this->superAdmin();

        // The modules with an "Add ..." action, and the id its page script wires.
        foreach ([
            'dishes.index' => 'create-dish',
            'payment-methods.index' => 'create-payment-method',
            'order-sessions.index' => 'create-order-session',
            'roles.index' => 'create-role',
            'users.index' => 'create-user',
        ] as $route => $id) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, 'data-fab'), $route);
            $this->assertStringContainsString('id="' . $id . '"', $html);
            // Fixed in the corner (clear of a phone's home bar), a round plus that becomes a pill.
            $this->assertStringContainsString('fixed bottom-[calc(1.25rem+env(safe-area-inset-bottom))] right-5', $html);
            $this->assertStringContainsString('sm:static', $html);
        }

        // A page with nothing to add has no floating button.
        $this->actingAs($admin)->get(route('payments.index'))->assertOk()->assertDontSee('data-fab', false);
    }

    public function test_the_row_actions_are_outlined_pills(): void
    {
        Dish::factory()->create();

        $actions = $this->actingAs($this->superAdmin())
            ->getJson(route('dishes.index'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('data.0.action');

        // View, Edit and Delete each carry a border of their own (grey, grey, red),
        // and a little air so the borders do not touch.
        $this->assertStringContainsString('rounded-full border border-gray-200', $actions);
        $this->assertStringContainsString('rounded-full border border-gray-300', $actions);
        $this->assertStringContainsString('rounded-full border border-red-200', $actions);
        $this->assertSame(3, substr_count($actions, 'mx-0.5'));
    }

    public function test_the_collapse_toggle_is_an_edge_handle_at_the_vertical_middle(): void
    {
        $html = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'id="sidebar-toggle"'));

        // Halfway down the sidebar's right edge (clear of the brand at the
        // top), centred with a margin: a transform would be overridden by the press
        // effect and make the handle jump.
        $this->assertStringContainsString('absolute -right-[calc(1.125rem+0.5px)] top-1/2', $html);
        $this->assertStringContainsString('-mt-[1.125rem]', $html);
        $this->assertStringNotContainsString('-translate-y-1/2', $html);

        // The halo is part of the handle at all times, not only while it has focus.
        $this->assertStringContainsString('focus:outline-none ring-4 ring-gray-200', $html);
    }

    public function test_the_sidebar_links_are_grouped_and_a_group_with_no_visible_link_is_dropped(): void
    {
        $all = $this->actingAs($this->superAdmin())->get(route('dashboard'))->assertOk()->getContent();

        // Each title is a button that folds its group (not a plain label).
        foreach (['ordering' => 'Ordering', 'service' => 'Service', 'catalog' => 'Catalog', 'access' => 'Access'] as $key => $title) {
            $this->assertStringContainsString('<span>' . $title . '</span>', $all);
            $this->assertStringContainsString("@click=\"toggleGroup('" . $key . "')\"", $all);
            $this->assertStringContainsString('data-nav-group="' . $key . '"', $all);
            $this->assertStringContainsString('id="nav-group-' . $key . '"', $all);
        }

        // A customer sees only the ordering group.
        $customer = $this->actingAs($this->userWith('view menu', 'view my orders'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<span>Ordering</span>', $customer);
        $this->assertStringNotContainsString('<span>Service</span>', $customer);
        $this->assertStringNotContainsString('<span>Access</span>', $customer);
    }

    public function test_a_sidebar_group_can_be_folded_and_the_group_of_the_current_page_is_marked_to_open(): void
    {
        $admin = $this->superAdmin();

        $html = $this->actingAs($admin)->get(route('menu.index'))->assertOk()->getContent();

        // The folded state is restored before paint from localStorage, and toggled by one method.
        $this->assertStringContainsString("localStorage.getItem('cucina.nav.closed')", $html);
        $this->assertStringContainsString('toggleGroup(key)', $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, "localStorage.getItem('cucina.nav.closed')"));

        // Only the group that holds the page you are on carries `data-has-active`.
        $this->assertSame(1, preg_match_all('/data-nav-group="[a-z]+"\s+data-has-active/', $html));
        $this->assertMatchesRegularExpression('/data-nav-group="ordering"\s+data-has-active/', $html);

        $dishes = $this->actingAs($admin)->get(route('dishes.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-nav-group="catalog"\s+data-has-active/', $dishes);
        $this->assertDoesNotMatchRegularExpression('/data-nav-group="ordering"\s+data-has-active/', $dishes);
    }

    public function test_the_active_link_is_the_ink_pill(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('menu.index'))
            ->assertOk()
            ->assertSee('bg-gray-900 text-white shadow-sm dark:bg-mist dark:text-ink', false);
    }
}
