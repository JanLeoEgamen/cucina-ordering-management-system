<?php

namespace Tests\Feature;

use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The public landing page and the signed-out pages share one look (layouts/guest).
class WelcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_sees_the_pitch_and_the_way_in(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Order fresh,', false)
            ->assertSee('How ordering works')
            ->assertSee(route('login'), false)
            ->assertSee(route('register'), false)
            ->assertSee('Create an account')
            // Nothing private: no menu link for a visitor.
            ->assertDontSee(route('menu.index'), false)
            ->assertDontSee('Open dashboard');
    }

    public function test_someone_signed_in_gets_their_way_back_in(): void
    {
        $this->actingAs($this->userWith('view menu'))->get('/')
            ->assertOk()
            ->assertSee(route('dashboard'), false)
            ->assertSee('Order from the menu')
            ->assertDontSee('Create an account');

        // Without the menu permission there is no menu button.
        $this->actingAs($this->userWith())->get('/')
            ->assertOk()->assertSee('Open dashboard')->assertDontSee('Order from the menu');
    }

    public function test_it_says_whether_ordering_is_open(): void
    {
        $this->get('/')->assertOk()->assertSee('No session is open right now');

        OrderSession::factory()->create(['start_time' => '00:00:00', 'end_time' => '23:59:59']);

        $this->get('/')->assertOk()->assertSee('Ordering is open')->assertSee('00:00 – 23:59');
    }

    public function test_it_names_the_next_session_when_none_is_live(): void
    {
        OrderSession::factory()->create([
            'order_date' => now()->addDays(3)->toDateString(),
            'start_time' => '08:00:00',
            'end_time' => '12:30:00',
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Next session')
            ->assertSee('08:00 – 12:30')
            ->assertDontSee('Ordering is open');
    }

    public function test_it_lists_the_payment_methods_that_are_switched_on(): void
    {
        PaymentMethod::factory()->create(['method_name' => 'GCash', 'is_active' => true]);
        PaymentMethod::factory()->create(['method_name' => 'Maya', 'is_active' => false]);

        $this->get('/')->assertOk()->assertSee('Ways to pay')->assertSee('GCash')->assertDontSee('Maya');
    }

    public function test_a_visitor_sees_the_live_menu_with_prices_and_is_sent_through_login_to_order(): void
    {
        // Nothing live: no preview section.
        $this->get('/')->assertOk()->assertDontSee('On the menu now');

        $session = OrderSession::factory()->create(['start_time' => '00:00:00', 'end_time' => '23:59:59']);
        SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Menu Adobo', 'price' => 123.45])->dish_id,
            'available_stock' => 7,
        ]);
        SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Gone Sinigang', 'price' => 99.00])->dish_id,
            'available_stock' => 0,
        ]);
        // A dish switched off in the masterlist is not advertised.
        SessionDish::factory()->create([
            'session_id' => $session->session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Hidden Dish', 'is_active' => false])->dish_id,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('On the menu now')
            ->assertSee('Menu Adobo')
            ->assertSee('₱123.45')
            ->assertSee('Gone Sinigang')
            ->assertSee('Sold out')
            ->assertDontSee('Hidden Dish')
            // The stock number is never public.
            ->assertDontSee('>7<', false)
            ->assertSee('Log in to order')
            // The button goes to the menu: a guest is bounced to login and brought back.
            ->assertSee('href="' . route('menu.index') . '"', false);

        // The button really does lead through login and back to the menu.
        $this->get(route('menu.index'))->assertRedirect(route('login'));
        $this->assertSame(route('menu.index'), session('url.intended'));
    }

    public function test_the_preview_is_closed_with_a_count_when_the_menu_is_longer(): void
    {
        $session = OrderSession::factory()->create(['start_time' => '00:00:00', 'end_time' => '23:59:59']);

        foreach (range(1, 10) as $number) {
            SessionDish::factory()->create([
                'session_id' => $session->session_id,
                'dish_id' => Dish::factory()->create(['dish_name' => sprintf('Dish %02d', $number)])->dish_id,
            ]);
        }

        $html = $this->get('/')->assertOk()
            ->assertSee('and 2 more on the full menu')
            ->getContent();

        // Eight cards (the hero collage also names dishes, so count the cards, not names).
        $this->assertSame(8, substr_count($html, 'data-preview-dish'));
    }

    public function test_signed_in_customers_get_a_direct_order_button_in_the_preview(): void
    {
        $session = OrderSession::factory()->create(['start_time' => '00:00:00', 'end_time' => '23:59:59']);
        SessionDish::factory()->create(['session_id' => $session->session_id]);

        $this->actingAs($this->userWith('view menu'))->get('/')->assertOk()
            ->assertSee('Order from the menu')
            ->assertDontSee('Log in to order');

        // Without the menu permission the preview has no button at all.
        $this->actingAs($this->userWith())->get('/')->assertOk()
            ->assertSee('On the menu now')
            ->assertDontSee('Order from the menu')
            ->assertDontSee('Log in to order');
    }

    public function test_a_running_pre_order_session_is_previewed_with_its_minimum(): void
    {
        $session = PreOrderSession::factory()->create(['min_quantity' => 25]);
        PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['dish_name' => 'Party Pancit', 'price' => 80])->dish_id,
        ]);

        $this->get('/')->assertOk()
            ->assertSee('Pre-orders are open')
            ->assertSee('at least 25 plates per order')
            ->assertSee('Party Pancit')
            ->assertSee('₱80.00')
            ->assertSee('Log in to pre-order')
            ->assertSee('href="' . route('pre-order-menu.index') . '"', false)
            // No order session is live, so there is no regular menu preview.
            ->assertDontSee('On the menu now');

        $this->actingAs($this->userWith('view pre-order-menu'))->get('/')->assertOk()
            ->assertSee('Pre-order')
            ->assertDontSee('Log in to pre-order');

        // A pre-order session that has ended is not advertised.
        PreOrderSession::query()->update(['ended_at' => now()->subMinute()]);
        $this->get('/')->assertOk()->assertDontSee('Pre-orders are open');
    }

    public function test_registering_after_being_sent_to_login_lands_back_on_the_menu(): void
    {
        $this->seedPermissions();

        $this->get(route('menu.index'))->assertRedirect(route('login'));

        $this->post('/register', [
            'first_name' => 'Ana',
            'last_name' => 'Cruz',
            'email' => 'ana@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ])->assertRedirect(route('menu.index'));
    }

    public function test_without_any_dish_the_sign_in_panel_shows_its_plain_points(): void
    {
        $this->get('/login')->assertOk()
            ->assertDontSee('data-collage-track', false)
            ->assertSee('A live menu')
            ->assertSee('Follow every order');
    }

    public function test_the_sign_in_panel_is_a_collage_of_the_available_dishes(): void
    {
        Dish::factory()->create(['dish_name' => 'Photo Adobo', 'image' => 'dishes/adobo.jpg', 'price' => 123.45]);
        Dish::factory()->create(['dish_name' => 'Plain Mechado', 'image' => null]);
        // A dish that is switched off is not advertised, with or without a picture.
        Dish::factory()->create(['dish_name' => 'Hidden Dish', 'image' => 'dishes/hidden.jpg', 'is_active' => false]);

        $html = $this->get('/login')->assertOk()
            ->assertSee('data-collage-track', false)
            ->assertSee('/storage/dishes/adobo.jpg', false)
            ->assertSee('Photo Adobo')
            ->assertSee('Plain Mechado')
            ->assertDontSee('Hidden Dish')
            ->assertDontSee('hidden.jpg', false)
            // Nothing private: no price.
            ->assertDontSee('123.45')
            ->getContent();

        // Three columns, each holding its tiles twice (the loop has no seam).
        $this->assertSame(3, substr_count($html, 'data-collage-track'));

        // The same collage on the register page, and it stands still for reduced motion.
        $this->get('/register')->assertOk()->assertSee('data-collage-track', false);
    }

    public function test_the_landing_hero_has_the_same_collage_and_none_without_dishes(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('data-collage-track', false)
            // The decorative rings stand in.
            ->assertSee('-right-32 -top-32', false);

        Dish::factory()->create(['dish_name' => 'Photo Adobo', 'image' => 'dishes/adobo.jpg', 'price' => 123.45]);
        Dish::factory()->create(['dish_name' => 'Plain Mechado', 'image' => null]);
        Dish::factory()->create(['dish_name' => 'Hidden Dish', 'image' => 'dishes/hidden.jpg', 'is_active' => false]);

        $html = $this->get('/')->assertOk()
            ->assertSee('/storage/dishes/adobo.jpg', false)
            ->assertSee('Plain Mechado')
            ->assertDontSee('Hidden Dish')
            ->assertDontSee('123.45')
            ->assertDontSee('-right-32 -top-32', false)
            ->getContent();

        // Three columns, and they are decoration for assistive technology.
        $this->assertSame(3, substr_count($html, 'data-collage-track'));
        $this->assertStringContainsString('pointer-events-none absolute inset-y-0 right-0 hidden w-[62%] overflow-hidden lg:block" aria-hidden="true"', $html);
    }

    public function test_the_showcase_spreads_photos_among_the_plain_dishes(): void
    {
        foreach (range(1, 10) as $number) {
            Dish::factory()->create(['dish_name' => 'Plain ' . $number, 'image' => null]);
        }

        Dish::factory()->create(['dish_name' => 'Photo A', 'image' => 'dishes/a.jpg']);
        Dish::factory()->create(['dish_name' => 'Photo B', 'image' => 'dishes/b.jpg']);

        $tiles = Dish::showcase();

        $this->assertCount(12, $tiles);

        $positions = array_keys(array_filter($tiles, fn (array $tile) => $tile['image'] !== null));

        // Two photos, evenly spaced over twelve tiles: not next to each other.
        $this->assertCount(2, $positions);
        $this->assertGreaterThan(2, $positions[1] - $positions[0]);
        $this->assertArrayNotHasKey('price', $tiles[0]);
    }

    public function test_the_login_and_register_pages_carry_the_brand_panel_and_link_to_each_other(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('Welcome back')
            ->assertSee('Good food, ordered in a few taps.')
            ->assertSee(route('register'), false);

        $this->get('/register')->assertOk()
            ->assertSee('Create your account')
            ->assertSee('Good food, ordered in a few taps.')
            ->assertSee('name="first_name"', false)
            ->assertSee(route('login'), false);

        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }
}
