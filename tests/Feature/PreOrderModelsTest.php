<?php

namespace Tests\Feature;

use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Dish;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The data layer of Pre-Orders: session status, the overlap guard, the dish
// list sync, order numbers and the permissions that come with the feature.
class PreOrderModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_whole_day_session_is_live_and_a_past_one_is_ended(): void
    {
        $live = PreOrderSession::factory()->create();
        $ended = PreOrderSession::factory()->ended()->create();
        $scheduled = PreOrderSession::factory()->scheduled()->create();

        $this->assertSame('live', PreOrderSession::findById($live->pre_order_session_id)->status);
        $this->assertSame('ended', PreOrderSession::findById($ended->pre_order_session_id)->status);
        $this->assertSame('scheduled', PreOrderSession::findById($scheduled->pre_order_session_id)->status);
        $this->assertSame($live->pre_order_session_id, PreOrderSession::live()->pre_order_session_id);
    }

    public function test_ending_a_session_closes_it_once(): void
    {
        $session = PreOrderSession::factory()->create();

        $this->assertTrue(PreOrderSession::endSession($session->pre_order_session_id));
        $this->assertFalse(PreOrderSession::endSession($session->pre_order_session_id));
        $this->assertSame('ended', PreOrderSession::findById($session->pre_order_session_id)->status);
        $this->assertNull(PreOrderSession::live());
    }

    public function test_overlapping_windows_are_detected_and_an_ended_session_does_not_count(): void
    {
        $date = now()->addDays(2)->toDateString();
        $first = PreOrderSession::factory()->create([
            'order_date' => $date, 'start_time' => '09:00:00', 'end_time' => '12:00:00',
        ]);

        $this->assertTrue(PreOrderSession::overlaps($date, '11:00', '14:00'));
        $this->assertTrue(PreOrderSession::overlaps($date, '08:00', '10:00'));
        // Back to back is not an overlap.
        $this->assertFalse(PreOrderSession::overlaps($date, '12:00', '15:00'));
        $this->assertFalse(PreOrderSession::overlaps(now()->addDays(3)->toDateString(), '09:00', '12:00'));
        // Editing the session itself ignores it.
        $this->assertFalse(PreOrderSession::overlaps($date, '09:00', '12:00', $first->pre_order_session_id));

        PreOrderSession::endSession($first->pre_order_session_id);
        $this->assertFalse(PreOrderSession::overlaps($date, '09:00', '12:00'));
    }

    public function test_the_dish_list_is_replaced_and_a_returning_dish_is_restored(): void
    {
        $a = Dish::factory()->create();
        $b = Dish::factory()->create();

        $id = PreOrderSession::createSession(
            ['created_by' => $this->superAdmin()->id, 'order_date' => now()->toDateString(),
                'start_time' => '09:00', 'end_time' => '17:00', 'min_quantity' => 10],
            [$a->dish_id, $b->dish_id, $a->dish_id]
        );

        $this->assertCount(2, PreOrderSession::dishesOfSession($id));

        PreOrderSession::updateSession($id, ['min_quantity' => 20], [$a->dish_id]);
        $this->assertSame([$a->dish_id], array_column(PreOrderSession::dishesOfSession($id), 'dish_id'));
        $this->assertSame(20, (int) PreOrderSession::findById($id)->min_quantity);

        // The removed row stays (order lines may point at it) and comes back.
        $this->assertDatabaseHas('pre_order_session_dishes', ['pre_order_session_id' => $id, 'dish_id' => $b->dish_id]);
        PreOrderSession::updateSession($id, [], [$a->dish_id, $b->dish_id]);
        $this->assertCount(2, PreOrderSession::dishesOfSession($id));
        $this->assertDatabaseCount('pre_order_session_dishes', 2);
    }

    public function test_the_menu_lists_and_prices_only_dishes_still_on_offer(): void
    {
        $session = PreOrderSession::factory()->create();
        $offered = PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'dish_id' => Dish::factory()->create(['price' => 120.5])->dish_id,
        ]);
        PreOrderSessionDish::factory()->create([
            'pre_order_session_id' => $session->pre_order_session_id,
            'is_available' => false,
        ]);

        $menu = PreOrderSessionDish::offeredForSession($session->pre_order_session_id);

        $this->assertCount(1, $menu);
        $this->assertSame(
            [$offered->pre_order_session_dish_id => 120.5],
            PreOrderSessionDish::pricesOfSession($session->pre_order_session_id)
        );
    }

    public function test_pre_order_numbers_count_up_with_their_own_prefix(): void
    {
        $prefix = 'PRE-' . now()->format('Ymd') . '-';

        $this->assertSame($prefix . '0001', PreOrder::nextOrderNumber());

        PreOrder::factory()->create(['order_number' => $prefix . '0007']);

        $this->assertSame($prefix . '0008', PreOrder::nextOrderNumber());
    }

    public function test_an_order_lists_its_lines_and_payment_for_staff_and_customer(): void
    {
        $order = PreOrder::factory()->create();
        PreOrderItem::factory()->create([
            'pre_order_id' => $order->pre_order_id,
            'pre_order_session_dish_id' => PreOrderSessionDish::factory()->create([
                'pre_order_session_id' => $order->pre_order_session_id,
            ])->pre_order_session_dish_id,
            'quantity' => 12,
            'unit_price' => 10,
            'sub_total' => 120,
        ]);

        $staff = PreOrder::ofSession($order->pre_order_session_id);
        $mine = PreOrder::ofUser($order->user_id);

        $this->assertCount(1, $staff);
        $this->assertSame(12, $staff[0]->items[0]['quantity']);
        $this->assertSame($order->order_number, $mine[0]->order_number);
        $this->assertSame(120.0, $mine[0]->items[0]['sub_total']);
        $this->assertSame([], PreOrder::ofUser(0));
    }

    public function test_payment_decisions_are_stamped_and_cleared(): void
    {
        $reviewer = $this->superAdmin();
        $order = PreOrder::factory()->create();

        PreOrder::setPaymentStatus($order->pre_order_id, 'rejected', $reviewer->id, 'Blurry proof');
        $row = PreOrder::findById($order->pre_order_id);
        $this->assertSame('rejected', $row->payment_status);
        $this->assertSame('Blurry proof', $row->rejection_reason);
        $this->assertSame($reviewer->id, (int) $row->verified_by);

        PreOrder::resubmitPayment($order->pre_order_id, $row->payment_method_id, 'REF-1', 'proofs/x.jpg');
        $row = PreOrder::findById($order->pre_order_id);
        $this->assertSame('pending', $row->payment_status);
        $this->assertNull($row->rejection_reason);
        $this->assertNull($row->verified_by);
        $this->assertSame('REF-1', $row->reference_number);

        $state = \Illuminate\Support\Facades\DB::transaction(fn () => PreOrder::lockedState($order->pre_order_id));
        $this->assertSame('pending', $state->order_status);
        $this->assertSame('pending', $state->payment_status);
    }

    public function test_session_with_orders_is_reported_and_pending_payments_are_counted(): void
    {
        $order = PreOrder::factory()->create();
        PreOrder::factory()->create(['pre_order_session_id' => $order->pre_order_session_id, 'payment_status' => 'verified']);

        $this->assertTrue(PreOrder::existsForSession($order->pre_order_session_id));
        $this->assertFalse(PreOrder::existsForSession(0));
        $this->assertSame(1, PreOrder::pendingPaymentCount());

        PreOrder::updateStatus($order->pre_order_id, 'cancelled');
        $this->assertSame(0, PreOrder::pendingPaymentCount());
    }

    public function test_pre_order_permissions_are_seeded_and_the_customer_can_pre_order(): void
    {
        $this->seedPermissions();

        foreach (['view pre-order-sessions', 'verify pre-order-payments', 'view pre-order-menu', 'create pre-orders'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name]);
        }

        $this->assertSame(
            ['view pre-order-menu', 'create pre-orders', 'view my pre-orders'],
            array_values(array_intersect(Role::CUSTOMER_PERMISSIONS, [
                'view pre-order-menu', 'create pre-orders', 'view my pre-orders',
            ]))
        );
        // A customer gets nothing of the staff side.
        $this->assertSame([], array_values(array_intersect(Role::CUSTOMER_PERMISSIONS, [
            'view pre-order-sessions', 'view pre-orders', 'complete pre-orders', 'verify pre-order-payments',
        ])));
    }
}
