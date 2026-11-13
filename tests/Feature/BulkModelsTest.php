<?php

namespace Tests\Feature;

use App\Models\BulkOrder;
use App\Models\BulkOrderItem;
use App\Models\BulkSession;
use App\Models\BulkSessionDish;
use App\Models\Dish;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The data layer of Bulk Orders: session status, the overlap guard, the dish
// list sync, order numbers and the permissions that come with the feature.
class BulkModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_day_session_is_live_and_a_past_one_is_ended(): void
    {
        $live = BulkSession::factory()->create();
        $ended = BulkSession::factory()->ended()->create();
        $scheduled = BulkSession::factory()->scheduled()->create();

        $this->assertSame('live', BulkSession::findById($live->bulk_session_id)->status);
        $this->assertSame('ended', BulkSession::findById($ended->bulk_session_id)->status);
        $this->assertSame('scheduled', BulkSession::findById($scheduled->bulk_session_id)->status);
        $this->assertSame($live->bulk_session_id, BulkSession::live()->bulk_session_id);
    }

    public function test_ending_a_session_closes_it_once(): void
    {
        $session = BulkSession::factory()->create();

        $this->assertTrue(BulkSession::endSession($session->bulk_session_id));
        $this->assertFalse(BulkSession::endSession($session->bulk_session_id));
        $this->assertSame('ended', BulkSession::findById($session->bulk_session_id)->status);
        $this->assertNull(BulkSession::live());
    }

    public function test_overlapping_windows_are_detected_and_an_ended_session_does_not_count(): void
    {
        $date = now()->addDays(2)->toDateString();
        $first = BulkSession::factory()->create([
            'order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);

        $this->assertTrue(BulkSession::overlaps($date, '11:00', '14:00'));
        $this->assertTrue(BulkSession::overlaps($date, '08:00', '10:00'));
        // Back to back is not an overlap.
        $this->assertFalse(BulkSession::overlaps($date, '12:00', '15:00'));
        $this->assertFalse(BulkSession::overlaps(now()->addDays(3)->toDateString(), '09:00', '12:00'));
        // Editing the session itself ignores it.
        $this->assertFalse(BulkSession::overlaps($date, '09:00', '12:00', $first->bulk_session_id));

        BulkSession::endSession($first->bulk_session_id);
        $this->assertFalse(BulkSession::overlaps($date, '09:00', '12:00'));
    }

    public function test_the_dish_list_is_replaced_and_a_returning_dish_is_restored(): void
    {
        $a = Dish::factory()->create();
        $b = Dish::factory()->create();

        $id = BulkSession::createSession(
            ['created_by' => $this->superAdmin()->id, 'order_date' => now()->toDateString(),
                'start_time' => '09:00', 'end_time' => '17:00', 'min_quantity' => 10],
            [$a->dish_id, $b->dish_id, $a->dish_id]
        );

        $this->assertCount(2, BulkSession::dishesOfSession($id));

        BulkSession::updateSession($id, ['min_quantity' => 20], [$a->dish_id]);
        $this->assertSame([$a->dish_id], array_column(BulkSession::dishesOfSession($id), 'dish_id'));
        $this->assertSame(20, (int) BulkSession::findById($id)->min_quantity);

        // The removed row stays (order lines may point at it) and comes back.
        $this->assertDatabaseHas('bulk_session_dishes', ['bulk_session_id' => $id, 'dish_id' => $b->dish_id]);
        BulkSession::updateSession($id, [], [$a->dish_id, $b->dish_id]);
        $this->assertCount(2, BulkSession::dishesOfSession($id));
        $this->assertDatabaseCount('bulk_session_dishes', 2);
    }

    public function test_the_menu_lists_and_prices_only_dishes_still_on_offer(): void
    {
        $session = BulkSession::factory()->create();
        $offered = BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'dish_id' => Dish::factory()->create(['price' => 120.5])->dish_id,
        ]);
        BulkSessionDish::factory()->create([
            'bulk_session_id' => $session->bulk_session_id,
            'is_available' => false,
        ]);

        $menu = BulkSessionDish::offeredForSession($session->bulk_session_id);

        $this->assertCount(1, $menu);
        $this->assertSame(
            [$offered->bulk_session_dish_id => 120.5],
            BulkSessionDish::pricesOfSession($session->bulk_session_id)
        );
    }

    public function test_bulk_order_numbers_count_up_with_their_own_prefix(): void
    {
        $prefix = 'BLK-' . now()->format('Ymd') . '-';

        $this->assertSame($prefix . '0001', BulkOrder::nextOrderNumber());

        BulkOrder::factory()->create(['order_number' => $prefix . '0007']);

        $this->assertSame($prefix . '0008', BulkOrder::nextOrderNumber());
    }

    public function test_an_order_lists_its_lines_and_payment_for_staff_and_customer(): void
    {
        $order = BulkOrder::factory()->create();
        BulkOrderItem::factory()->create([
            'bulk_order_id' => $order->bulk_order_id,
            'bulk_session_dish_id' => BulkSessionDish::factory()->create([
                'bulk_session_id' => $order->bulk_session_id,
            ])->bulk_session_dish_id,
            'quantity' => 12,
            'unit_price' => 10,
            'sub_total' => 120,
        ]);

        $staff = BulkOrder::ofSession($order->bulk_session_id);
        $mine = BulkOrder::ofUser($order->user_id);

        $this->assertCount(1, $staff);
        $this->assertSame(12, $staff[0]->items[0]['quantity']);
        $this->assertSame($order->order_number, $mine[0]->order_number);
        $this->assertSame(120.0, $mine[0]->items[0]['sub_total']);
        $this->assertSame([], BulkOrder::ofUser(0));
    }

    public function test_payment_decisions_are_stamped_and_cleared(): void
    {
        $reviewer = $this->superAdmin();
        $order = BulkOrder::factory()->create();

        BulkOrder::setPaymentStatus($order->bulk_order_id, 'rejected', $reviewer->id, 'Blurry proof');
        $row = BulkOrder::findById($order->bulk_order_id);
        $this->assertSame('rejected', $row->payment_status);
        $this->assertSame('Blurry proof', $row->rejection_reason);
        $this->assertSame($reviewer->id, (int) $row->verified_by);

        BulkOrder::resubmitPayment($order->bulk_order_id, $row->payment_method_id, 'REF-1', 'proofs/x.jpg');
        $row = BulkOrder::findById($order->bulk_order_id);
        $this->assertSame('pending', $row->payment_status);
        $this->assertNull($row->rejection_reason);
        $this->assertNull($row->verified_by);
        $this->assertSame('REF-1', $row->reference_number);

        $state = \Illuminate\Support\Facades\DB::transaction(fn () => BulkOrder::lockedState($order->bulk_order_id));
        $this->assertSame('pending', $state->order_status);
        $this->assertSame('pending', $state->payment_status);
    }

    public function test_session_with_orders_is_reported_and_pending_payments_are_counted(): void
    {
        $order = BulkOrder::factory()->create();
        BulkOrder::factory()->create(['bulk_session_id' => $order->bulk_session_id, 'payment_status' => 'verified']);

        $this->assertTrue(BulkOrder::existsForSession($order->bulk_session_id));
        $this->assertFalse(BulkOrder::existsForSession(0));
        $this->assertSame(1, BulkOrder::pendingPaymentCount());

        BulkOrder::updateStatus($order->bulk_order_id, 'cancelled');
        $this->assertSame(0, BulkOrder::pendingPaymentCount());
    }

    public function test_bulk_permissions_are_seeded_and_the_customer_can_order_in_bulk(): void
    {
        $this->seedPermissions();

        foreach (['view bulk-sessions', 'verify bulk-payments', 'view bulk-menu', 'create bulk-orders'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }

        $this->assertSame(
            ['view bulk-menu', 'create bulk-orders', 'view my bulk-orders'],
            array_values(array_intersect(Role::CUSTOMER_PERMISSIONS, [
                'view bulk-menu', 'create bulk-orders', 'view my bulk-orders',
            ]))
        );
        // A customer gets nothing of the staff side.
        $this->assertSame([], array_values(array_intersect(Role::CUSTOMER_PERMISSIONS, [
            'view bulk-sessions', 'view bulk-orders', 'complete bulk-orders', 'verify bulk-payments',
        ])));
    }
}
