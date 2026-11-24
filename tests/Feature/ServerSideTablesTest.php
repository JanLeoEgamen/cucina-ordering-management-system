<?php

namespace Tests\Feature;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\User;
use App\Support\ServerTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// F22: the staff Orders and Payments tables used to receive EVERY row (8 MB and ~200 MB of PHP memory at
// 5,000 orders). A table that sends `draw` now gets ONE page, searched, sorted and filtered by the
// database; one that does not still gets everything (OrdersPageTest / PaymentTest cover that shape).
class ServerSideTablesTest extends TestCase
{
    use RefreshDatabase;

    private function feed(string $route, array $query)
    {
        return $this->actingAs($this->superAdmin())->getJson(route($route, $query + ['draw' => 7]), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    // $count orders of $session (a new one by default), each with a payment and one line.
    private function orders(int $count, ?OrderSession $session = null, array $order = [], ?PaymentMethod $method = null, ?SessionDish $dish = null): array
    {
        $session ??= OrderSession::factory()->create();
        $method ??= PaymentMethod::factory()->create();
        $dish ??= SessionDish::factory()->create(['session_id' => $session->session_id]);
        $made = [];

        for ($i = 0; $i < $count; $i++) {
            $made[] = $row = Order::factory()->create(['session_id' => $session->session_id] + $order);
            Payment::factory()->create(['order_id' => $row->order_id, 'payment_method_id' => $method->method_id, 'reference_number' => 'REF-' . $row->order_id]);
            OrderItem::factory()->create(['order_id' => $row->order_id, 'session_dish_id' => $dish->session_dish_id]);
        }

        return $made;
    }

    public function test_it_answers_one_page_with_the_totals_and_the_filter_options(): void
    {
        $this->orders(25);

        $json = $this->feed('orders.index', ['start' => 10, 'length' => 10])->assertOk()->json();

        $this->assertSame(7, $json['draw']);
        $this->assertCount(10, $json['data']);
        $this->assertSame(25, $json['recordsTotal']);
        $this->assertSame(25, $json['recordsFiltered']);
        $this->assertArrayHasKey('methods', $json['filters']);
        $this->assertArrayHasKey('sessions', $json['filters']);
        $this->assertNotEmpty($json['data'][0]['items'], 'a row keeps its lines');
    }

    public function test_nothing_can_ask_for_the_whole_table_in_one_request(): void
    {
        $length = fn (int $asked) => ServerTable::params(Request::create('/x', 'GET', ['length' => $asked]))['length'];

        $this->assertSame(10, $length(-1), '"all" is not honoured');
        $this->assertSame(10, $length(0));
        $this->assertSame(ServerTable::MAX_LENGTH, $length(1000000));
        $this->assertSame(25, $length(25));
    }

    public function test_a_page_costs_the_same_number_of_queries_however_many_orders_there_are(): void
    {
        $this->orders(3);
        $this->feed('orders.index', ['length' => 10])->assertOk(); // warm: permissions, user

        $count = function () {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->feed('orders.index', ['length' => 10])->assertOk();

            return $n;
        };

        $few = $count();
        $this->orders(60);

        $this->assertSame($few, $count());
    }

    public function test_the_search_needs_every_word_and_finds_a_customer_a_dish_or_a_number(): void
    {
        $maria = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Santos']);
        $adobo = SessionDish::factory()->create(['dish_id' => Dish::factory()->create(['dish_name' => 'Chicken Adobo'])->dish_id]);
        $this->orders(1, null, ['user_id' => $maria->id], null, $adobo);
        $this->orders(4); // other customers, other dishes

        $found = fn (string $text) => $this->feed('orders.index', ['search' => ['value' => $text], 'length' => 50])->json();

        $this->assertSame(1, $found('maria')['recordsFiltered'], 'the customer');
        $this->assertSame(1, $found('adobo')['recordsFiltered'], 'a dish in the lines');
        $this->assertSame(1, $found('MARIA adobo')['recordsFiltered'], 'every word, any case');
        $this->assertSame(0, $found('maria nosuchdish')['recordsFiltered'], 'a word nowhere means no row');
        $this->assertSame(5, $found('')['recordsFiltered'], 'no search, every order');
        $this->assertSame(0, $found('%')['recordsFiltered'], 'a wildcard is text, not "everything"');
        $this->assertSame(0, $found('_')['recordsFiltered']);
        $this->assertSame(5, $found('')['recordsTotal'], 'the total ignores the search');
    }

    public function test_the_page_filters_narrow_the_list(): void
    {
        $morning = OrderSession::factory()->create();
        $cash = PaymentMethod::factory()->create();
        $this->orders(2, $morning, ['order_status' => 'completed', 'payment_status' => 'verified', 'order_date' => '2026-11-01 10:00:00'], $cash);
        $this->orders(3, null, ['order_status' => 'pending', 'payment_status' => 'pending', 'order_date' => '2026-11-20 10:00:00']);

        $count = fn (array $filter) => $this->feed('orders.index', $filter + ['length' => 50])->json('recordsFiltered');

        $this->assertSame(2, $count(['status' => 'completed']));
        $this->assertSame(3, $count(['status' => 'pending']));
        $this->assertSame(2, $count(['payment' => 'verified']));
        $this->assertSame(2, $count(['method' => $cash->method_id]));
        $this->assertSame(2, $count(['session' => $morning->session_id]));
        $this->assertSame(3, $count(['from' => '2026-11-10']));
        $this->assertSame(2, $count(['to' => '2026-11-10']));
        $this->assertSame(0, $count(['status' => 'completed', 'from' => '2026-11-10']), 'filters add up');
        $this->assertSame(5, $count(['status' => 'nonsense', 'from' => 'not a date']), 'an unknown value filters nothing');
    }

    public function test_it_sorts_by_the_column_the_table_asks_for(): void
    {
        $this->orders(1, null, ['total_amount' => 300]);
        $this->orders(1, null, ['total_amount' => 100]);
        $this->orders(1, null, ['total_amount' => 200]);

        $totals = fn (string $dir) => collect($this->feed('orders.index', ['order' => [['column' => 5, 'dir' => $dir]], 'length' => 10])->json('data'))->pluck('total_amount')->map(fn ($v) => (float) $v)->all();

        $this->assertSame([100.0, 200.0, 300.0], $totals('asc'));
        $this->assertSame([300.0, 200.0, 100.0], $totals('desc'));
    }

    public function test_text_in_a_row_is_still_escaped(): void
    {
        $evil = User::factory()->create(['first_name' => '<b>Mia</b>', 'last_name' => 'Cruz']);
        $this->orders(1, null, ['user_id' => $evil->id]);

        $name = $this->feed('orders.index', ['length' => 10])->json('data.0.customer_name');

        $this->assertStringNotContainsString('<b>', $name);
        $this->assertStringContainsString('&lt;b&gt;', $name);
    }

    public function test_the_payments_table_pages_searches_and_filters_too(): void
    {
        $session = OrderSession::factory()->create();
        $gcash = PaymentMethod::factory()->create(['method_name' => 'GCash']);
        $this->orders(12, $session, [], $gcash);
        $verified = Payment::query()->first();
        DB::table('payments')->where('order_id', $verified->order_id)->update(['payment_status' => 'verified', 'reference_number' => 'UNIQUE-777']);

        $page = $this->feed('payments.index', ['start' => 10, 'length' => 10])->assertOk()->json();
        $this->assertCount(2, $page['data']);
        $this->assertSame(12, $page['recordsTotal']);
        $this->assertArrayHasKey('methods', $page['filters']);

        $this->assertSame(1, $this->feed('payments.index', ['status' => 'verified', 'length' => 50])->json('recordsFiltered'));
        $this->assertSame(12, $this->feed('payments.index', ['search' => ['value' => 'gcash'], 'length' => 50])->json('recordsFiltered'));
        $this->assertSame(1, $this->feed('payments.index', ['search' => ['value' => 'unique-777'], 'length' => 50])->json('recordsFiltered'));
        $this->assertSame(12, $this->feed('payments.index', ['session' => $session->session_id, 'length' => 50])->json('recordsFiltered'));
    }

    public function test_the_paged_feed_keeps_its_permission(): void
    {
        $this->orders(1);

        $this->actingAs($this->userWith('view dishes'))
            ->getJson(route('orders.index', ['draw' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
        $this->actingAs($this->userWith('view dishes'))
            ->getJson(route('payments.index', ['draw' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
    }
}
