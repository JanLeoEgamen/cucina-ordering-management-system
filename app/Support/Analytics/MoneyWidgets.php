<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The Money tab. "Collected" = VERIFIED payments, counted on the day they were verified.
class MoneyWidgets
{
    // Payment methods drawn in their own colour; any further one is "Other" (the validated
    // palette in app.css has this many).
    public const CHART_SERIES = 5;

    public const REFERENCES_SHOWN = 25;

    public const HELD_SHOWN = 10;

    public static function collected(string $startAt, string $endAt): Collection
    {
        return Payment::collectedBetween($startAt, $endAt);
    }

    // The same for the range of the page, cached when it may be.
    public static function collectedIn(Period $period): Collection
    {
        return $period->remember(['payments', 'orders', 'payment_methods', 'users'], 'collected', fn () => self::collected($period->startAt(), $period->endAt()));
    }

    // For checking the money at the end of a day: what was verified each day by each method, and
    // by whom, with the cash set apart (it is what should be in the drawer).
    public static function collections(User $user, Period $period): array
    {
        $rows = self::collectedIn($period);
        $series = self::seriesFor($rows);
        $days = $rows->groupBy('day')->sortKeys()->map(function (Collection $day, string $date) use ($series) {
            return [
                'label' => Carbon::parse($date)->translatedFormat('D, M j'),
                'short' => Carbon::parse($date)->translatedFormat('M j'),
                'amounts' => array_map(
                    fn (array $one) => round((float) $day->filter(fn (object $row) => in_array((string) $row->method_name, $one['names'], true))->sum('amount'), 2),
                    $series
                ),
                'payments' => (int) $day->sum('payment_count'),
                'total' => round((float) $day->sum('amount'), 2),
            ];
        })->values();

        $people = $rows->groupBy(fn (object $row) => (string) $row->verified_by)
            ->map(fn (Collection $mine) => [
                'name' => trim((string) $mine->first()->verifier_name) ?: __('Not recorded'),
                'payments' => (int) $mine->sum('payment_count'),
                'cash' => round((float) $mine->where('method_name', PaymentMethod::CASH_ON_PICKUP)->sum('amount'), 2),
                'total' => round((float) $mine->sum('amount'), 2),
            ])
            ->sortBy([['total', 'desc'], ['name', 'asc']])
            ->values();

        return [
            'total' => round((float) $rows->sum('amount'), 2),
            'cash' => round((float) $rows->where('method_name', PaymentMethod::CASH_ON_PICKUP)->sum('amount'), 2),
            'payments' => (int) $rows->sum('payment_count'),
            'methods' => array_column($series, 'label'),
            // Newest first in the table, oldest first in the chart.
            'days' => $days->reverse()->values()->all(),
            'people' => $people->all(),
            'chart' => [
                'labels' => $days->pluck('short')->all(),
                'datasets' => array_map(fn (array $one, int $index) => [
                    'label' => $one['label'],
                    'color' => $one['color'],
                    'data' => $days->map(fn (array $day) => $day['amounts'][$index])->all(),
                ], $series, array_keys($series)),
                'stacked' => true,
            ],
        ];
    }

    // Money already received for orders that are still open: they are owed their food (or their
    // money back). This is "right now" and ignores the date range.
    public static function held(User $user, Period $period): array
    {
        $held = Order::openForDashboard()->where('payment_state', 'verified')->values();

        return [
            'orders' => $held->count(),
            'amount' => round((float) $held->sum('total_amount'), 2),
            'rows' => $held->take($period->limit(self::HELD_SHOWN))->map(fn (object $order) => [
                'number' => $order->order_number,
                'customer' => $order->customer_name,
                'method' => $order->method_name,
                'amount' => (float) $order->total_amount,
                'session' => $order->session_date ? Carbon::parse($order->session_date)->translatedFormat('M j') : null,
                'ready' => $order->ready_at !== null,
                'url' => $user->can('view order-sessions') ? route('order-sessions.show', $order->session_id) : route('orders.index'),
            ])->all(),
        ];
    }

    // A reason to look, not proof of anything: a typo repeats a number too.
    public static function references(User $user, Period $period): array
    {
        $found = $period->remember(
            ['payments', 'orders', 'users', 'payment_methods'],
            'references',
            fn () => Payment::repeatedReferences($period->startAt(), $period->endAt(), $period->limit(self::REFERENCES_SHOWN))
        );

        return [
            'groups' => $found['groups']->map(fn (Collection $orders, string $reference) => [
                'reference' => $reference,
                'customers' => $orders->unique('user_id')->count(),
                'orders' => $orders->map(fn (object $order) => [
                    'number' => $order->order_number,
                    'customer' => $order->customer_name,
                    'date' => Carbon::parse($order->order_date)->translatedFormat('M j, Y'),
                    'method' => $order->method_name,
                    'amount' => (float) $order->amount,
                    'payment' => $order->payment_status,
                    'cancelled' => $order->order_status === 'cancelled',
                ])->all(),
            ])
                // The same number from two different people is the one to look at first.
                ->sortByDesc(fn (array $group) => $group['customers'] > 1 ? 1 : 0)
                ->values()
                ->all(),
            'more' => $found['more'],
            'ordersUrl' => $user->can('view orders') ? route('orders.index') : route('payments.index'),
        ];
    }

    // How long bank / e-wallet payments and discount IDs waited for a decision.
    public static function reviewTimes(User $user, Period $period): array
    {
        $payments = $period->remember(['payments', 'orders', 'payment_methods'], 'review_payments', fn () => Payment::reviewTimes($period->startAt(), $period->endAt()));
        $discounts = $period->remember(['orders'], 'review_discounts', fn () => Order::discountReviewTimes($period->startAt(), $period->endAt()));

        $summary = function (Collection $bands, string $title) {
            $total = (int) $bands->sum('decisions');
            $labels = [1 => __('Up to 5 minutes'), 2 => __('5 to 15 minutes'), 3 => __('15 minutes to 1 hour'), 4 => __('Over 1 hour')];

            return [
                'title' => $title,
                'decisions' => $total,
                'average' => $total > 0 ? (int) round($bands->sum('minutes') / $total) : null,
                'longest' => $total > 0 ? (int) round((float) $bands->max('longest')) : null,
                'bands' => collect($labels)->map(function (string $label, int $band) use ($bands, $total) {
                    $count = (int) ($bands[$band]->decisions ?? 0);
                    $share = $total > 0 ? (int) round($count / $total * 100) : 0;

                    return ['label' => $label, 'decisions' => $count, 'share' => $share, 'width' => $share];
                })->values()->all(),
            ];
        };

        return [
            'groups' => [
                $summary($payments, __('Payments (bank and e-wallet)')),
                $summary($discounts, __('Discount IDs')),
            ],
        ];
    }

    // What the discounts cost, by the name the order kept. Totals only: who used one (a senior or
    // PWD discount says something about them) is never read here.
    public static function discounts(User $user, Period $period): array
    {
        $rows = $period->remember(['orders'], 'discounts', fn () => Order::discountTotals($period->startAt(), $period->endAt()));

        $discounts = $rows->groupBy(fn (object $row) => (string) $row->discount_name)
            ->map(function (Collection $mine, string $name) {
                $kept = $mine->where('order_status', '!=', 'cancelled');
                $count = fn (string $status) => (int) $kept->where('discount_status', $status)->sum('order_count');

                return [
                    'name' => $name,
                    'orders' => (int) $kept->sum('order_count'),
                    'approved' => $count('approved'),
                    'rejected' => $count('rejected'),
                    'pending' => $count('pending'),
                    // Given = approved on COMPLETED orders.
                    'given' => round((float) $mine->where('discount_status', 'approved')->where('order_status', 'completed')->sum('amount'), 2),
                ];
            })
            ->filter(fn (array $discount) => $discount['orders'] > 0)
            ->sortBy([['given', 'desc'], ['orders', 'desc'], ['name', 'asc']])
            ->values();

        return [
            'discounts' => $discounts->all(),
            'given' => round((float) $discounts->sum('given'), 2),
            'orders' => (int) $discounts->sum('orders'),
            'rejected' => (int) $discounts->sum('rejected'),
        ];
    }

    /* -------- Internals -------- */

    // The chart's series. A method keeps its colour whatever the range shows: the colour follows
    // its place among ALL the methods (oldest first), not its rank in this result. Methods past
    // the palette, deleted ones and payments without a method are folded into "Other".
    protected static function seriesFor(Collection $rows): array
    {
        $ordered = PaymentMethod::getAll()->sortBy('method_id')->pluck('method_name')->values();
        $present = $rows->map(fn (object $row) => (string) $row->method_name)->unique();
        $series = [];

        foreach ($ordered->take(self::CHART_SERIES) as $index => $name) {
            if ($present->contains($name)) {
                $series[] = ['label' => $name, 'color' => $index + 1, 'names' => [$name]];
            }
        }

        $others = $present->diff($ordered->take(self::CHART_SERIES))->values()->all();

        if ($others !== []) {
            $series[] = ['label' => __('Other'), 'color' => 'other', 'names' => $others];
        }

        return $series;
    }
}
