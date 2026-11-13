<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
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
