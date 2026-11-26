<?php

namespace App\Support\Dashboard;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The dashboard widgets for whoever runs the counter and the kitchen. attention(), unclaimed()
// and cash() all read the one cached list of pending orders (Order::openForDashboard()). A link
// to another page is only given to someone who may open it.
class CounterWidgets
{
    // A dish with this many plates left or fewer is "low".
    public const LOW_STOCK = 5;

    public const UNCLAIMED_SHOWN = 5;

    // What is waiting for somebody. Each line is behind the permission to act on it, and a line
    // with nothing in it is left out.
    public static function attention(User $user): array
    {
        $open = Order::openForDashboard();
        $ordersUrl = $user->can('view orders') ? route('orders.index') : null;
        $lines = [];

        if ($user->can('view payments')) {
            // Cash on Pick up is not here: it is verified by completing the order.
            $count = $open->filter(fn (object $order) => $order->payment_state === 'pending' && ! self::isCash($order))->count();

            $lines[] = [
                'count' => $count,
                'label' => trans_choice('payment to verify|payments to verify', $count),
                'detail' => null,
                'url' => route('payments.index'),
            ];
        }

        if ($user->can('review discounts')) {
            $count = $open->where('discount_status', 'pending')->count();

            $lines[] = [
                'count' => $count,
                'label' => trans_choice('discount to review|discounts to review', $count),
                'detail' => null,
                'url' => $ordersUrl,
            ];
        }

        if ($user->can('view orders')) {
            // Both keep their plates until staff cancel or complete them.
            $rejected = $open->where('payment_state', 'rejected');

            $lines[] = self::heldLine($rejected, 'order with a rejected payment|orders with a rejected payment', $ordersUrl);

            $late = $open->reject(fn (object $order) => $order->payment_state === 'rejected')
                ->filter(fn (object $order) => self::sessionEnded($order));

            $lines[] = self::heldLine($late, 'order still open after its session ended|orders still open after their session ended', $ordersUrl);
        }

        return ['lines' => array_values(array_filter($lines, fn (array $line) => $line['count'] > 0))];
    }

    // Announced as ready and still not handed over, the longest waiting first.
    public static function unclaimed(User $user): ?array
    {
        $ready = Order::openForDashboard()->whereNotNull('ready_at')->sortBy('ready_at')->values();

        if ($ready->isEmpty()) {
            return null;
        }

        return [
            'orders' => $ready->take(self::UNCLAIMED_SHOWN)->map(fn (object $order) => [
                'number' => $order->order_number,
                'customer' => $order->customer_name,
                'waiting' => Carbon::parse($order->ready_at)->diffForHumans(),
                'url' => $user->can('view order-sessions') ? route('order-sessions.show', $order->session_id) : route('orders.index'),
            ])->all(),
            'total' => $ready->count(),
        ];
    }

    // Cash that is still to be taken at the counter.
    public static function cash(User $user): array
    {
        $cash = Order::openForDashboard()
            ->filter(fn (object $order) => $order->payment_state === 'pending' && self::isCash($order));
        $ready = $cash->whereNotNull('ready_at');

        return [
            'total' => round((float) $cash->sum('total_amount'), 2),
            'orders' => $cash->count(),
            'readyTotal' => round((float) $ready->sum('total_amount'), 2),
            'readyOrders' => $ready->count(),
            // Their discount is not decided, so the amount can still go up.
            'undecided' => $cash->where('discount_status', 'pending')->count(),
        ];
    }

    // Today so far beside the same weekday last week up to the same time of day, so a morning is
    // never compared with a whole day.
    public static function today(User $user): array
    {
        $today = now()->toDateString();
        $before = now()->subWeek()->toDateString();
        $time = now()->format('H:i:s');
        $days = [$today, $before];

        $placed = Order::placedOnDays($days);
        $verified = Payment::verifiedOnDays($days);

        $upToNow = fn (Collection $rows, string $column, string $day) => $rows->filter(
            fn (object $row) => substr((string) $row->{$column}, 0, 10) === $day && substr((string) $row->{$column}, 11, 8) <= $time
        );

        $figures = function (string $day) use ($placed, $verified, $upToNow) {
            $all = $upToNow($placed, 'order_date', $day);
            $kept = $all->where('order_status', '!=', 'cancelled');
            $value = (float) $kept->sum('total_amount');

            return [
                'orders' => $kept->count(),
                'value' => round($value, 2),
                'average' => $kept->count() > 0 ? round($value / $kept->count(), 2) : 0.0,
                'collected' => round((float) $upToNow($verified, 'verified_at', $day)->sum('amount'), 2),
                'sales' => round((float) $all->where('order_status', 'completed')->sum('total_amount'), 2),
                'expected' => round((float) $all->where('order_status', 'pending')->sum('total_amount'), 2),
                'cancelled' => $all->count() - $kept->count(),
            ];
        };

        $now = $figures($today);
        $then = $figures($before);

        $tiles = collect([
            'orders' => [__('Orders'), false],
            'value' => [__('Order value'), true],
            'average' => [__('Average order'), true],
            'collected' => [__('Collected'), true],
        ])->map(fn (array $tile, string $key) => [
            'label' => $tile[0],
            'money' => $tile[1],
            'value' => $now[$key],
            'before' => $then[$key],
            // No percentage against nothing.
            'change' => $then[$key] > 0 ? (int) round(($now[$key] - $then[$key]) / $then[$key] * 100) : null,
        ])->values()->all();

        return [
            'tiles' => $tiles,
            'sales' => $now['sales'],
            'expected' => $now['expected'],
            'cancelled' => $now['cancelled'],
            'comparedWith' => now()->subWeek()->translatedFormat('D, M j'),
        ];
    }

    // The running session's stock, dish by dish, the ones running out first.
    public static function liveSession(User $user): ?array
    {
        $live = OrderSession::live();

        if (! $live) {
            return null;
        }

        $rank = ['sold_out' => 0, 'low' => 1, 'ok' => 2, 'off' => 3];

        $dishes = SessionDish::stockForDashboard((int) $live->session_id)
            // A dish taken off the menu still shows while orders hold its plates.
            ->filter(fn (object $dish) => $dish->offered || $dish->reserved > 0)
            ->map(fn (object $dish) => [
                'dish_name' => $dish->dish_name,
                'ordered' => $dish->reserved,
                'prepared' => $dish->available + $dish->reserved,
                'left' => $dish->available,
                'state' => match (true) {
                    ! $dish->offered => 'off',
                    $dish->available <= 0 => 'sold_out',
                    $dish->available <= self::LOW_STOCK => 'low',
                    default => 'ok',
                },
            ])
            ->sortBy(fn (array $dish) => [$rank[$dish['state']], $dish['left'], $dish['dish_name']])
            ->values();

        return [
            'dishes' => $dishes->all(),
            'ordered' => $dishes->sum('ordered'),
            'prepared' => $dishes->sum('prepared'),
            'url' => $user->can('view order-sessions') ? route('order-sessions.show', $live->session_id) : null,
        ];
    }

    /* -------- Internals -------- */

    protected static function heldLine(Collection $orders, string $label, ?string $url): array
    {
        $plates = (int) $orders->sum('plates');

        return [
            'count' => $orders->count(),
            'label' => trans_choice($label, $orders->count()),
            'detail' => trans_choice(':count plate held|:count plates held', $plates),
            'url' => $url,
        ];
    }

    protected static function isCash(object $order): bool
    {
        return $order->method_name === PaymentMethod::CASH_ON_PICKUP;
    }

    // Depends on the clock, so it is worked out from the cached rows on every call.
    protected static function sessionEnded(object $order): bool
    {
        if ($order->session_date === null) {
            return false;
        }

        return OrderSession::statusOf((object) [
            'order_date' => $order->session_date,
            'start_time' => $order->session_start,
            'end_time' => $order->session_end,
            'ended_at' => $order->session_ended_at,
            'is_active' => $order->session_is_active,
        ]) === 'ended';
    }
}
