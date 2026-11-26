<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The Sales tab. "Sales" = COMPLETED orders, counted on the day they were placed. summary(),
// trend(), status(), methods() and basket() read the same daily totals.
class SalesWidgets
{
    // Longer than this many days the trend is drawn per week, and past the next one per month.
    public const DAILY_UP_TO = 62;

    public const WEEKLY_UP_TO = 190;

    public const REASONS_SHOWN = 5;

    public static function summary(User $user, Period $period): array
    {
        $previous = $period->previous();
        // A range that stops part way through a day moves with the clock, so it is read fresh.
        $load = fn () => Order::dailyTotals($previous['startAt'], $previous['endAt']);
        $before = $previous['partial'] ? $load() : $period->remember(['orders', 'payments', 'payment_methods'], 'before.daily', $load);
        $collectedBefore = fn () => MoneyWidgets::collected($previous['startAt'], $previous['endAt']);
        $paidBefore = $previous['partial'] ? $collectedBefore() : $period->remember(['payments', 'orders', 'payment_methods', 'users'], 'before.collected', $collectedBefore);

        $now = self::figures(self::daily($period), MoneyWidgets::collectedIn($period));
        $then = self::figures($before, $paidBefore);

        $tiles = collect([
            'sales' => [__('Sales'), true],
            'orders' => [__('Orders'), false],
            'average' => [__('Average order'), true],
            'collected' => [__('Collected'), true],
        ])->map(fn (array $tile, string $key) => [
            'label' => $tile[0],
            'money' => $tile[1],
            'value' => $now[$key],
            'before' => $then[$key],
            'change' => $then[$key] > 0 ? (int) round(($now[$key] - $then[$key]) / $then[$key] * 100) : null,
        ])->values()->all();

        return [
            'tiles' => $tiles,
            'pending' => $now['pending'],
            'comparedWith' => Period::span($previous['from'], $previous['to']),
            'partial' => $previous['partial'],
        ];
    }

    public static function trend(User $user, Period $period): array
    {
        $unit = $period->days() <= self::DAILY_UP_TO ? 'day' : ($period->days() <= self::WEEKLY_UP_TO ? 'week' : 'month');
        $bucket = fn (Carbon $day) => match ($unit) {
            'day' => $day->toDateString(),
            // The first week of the range starts where the range does.
            'week' => $day->copy()->startOfWeek()->max($period->from)->toDateString(),
            'month' => $day->format('Y-m'),
        };

        // Every bucket exists, so a quiet day is a gap in the bars and not a missing column.
        $rows = [];

        for ($day = $period->from->copy(); $day->lte($period->to); $day->addDay()) {
            $key = $bucket($day);
            $rows[$key] ??= [
                'label' => $day->translatedFormat($unit === 'month' ? 'M Y' : 'M j'),
                'sales' => 0.0,
                'pending' => 0.0,
                'orders' => 0,
            ];
        }

        foreach (self::daily($period) as $total) {
            $key = $bucket(Carbon::parse($total->day));

            if ($total->order_status === 'cancelled' || ! isset($rows[$key])) {
                continue;
            }

            $rows[$key]['orders'] += (int) $total->order_count;
            $rows[$key][$total->order_status === 'completed' ? 'sales' : 'pending'] += (float) $total->amount;
        }

        $rows = collect($rows)->map(fn (array $row) => ['sales' => round($row['sales'], 2), 'pending' => round($row['pending'], 2)] + $row)->values();

        return [
            'unit' => $unit,
            'rows' => $rows->all(),
            'empty' => $rows->sum('orders') === 0,
            'chart' => [
                'labels' => $rows->pluck('label')->all(),
                'datasets' => [['label' => __('Sales'), 'color' => 'accent', 'data' => $rows->pluck('sales')->all()]],
                'stacked' => false,
            ],
        ];
    }

    // When people order: by hour of the day, and by weekday and hour (cancelled left out).
    public static function times(User $user, Period $period): array
    {
        $rows = $period->remember(['orders'], 'hours', fn () => Order::hourlyCounts($period->startAt(), $period->endAt()));
        $total = (int) $rows->sum('order_count');

        if ($total === 0) {
            return ['empty' => true, 'chart' => null, 'hours' => [], 'days' => [], 'busiest' => null];
        }

        $byHour = $rows->groupBy(fn (object $row) => (int) $row->hour_of_day);
        $range = range((int) $byHour->keys()->min(), (int) $byHour->keys()->max());

        // The weekday is worked out here: SQLite and MySQL number the days differently.
        $weekdays = $rows->map(fn (object $row) => ['day' => Carbon::parse($row->day)->dayOfWeekIso, 'hour' => (int) $row->hour_of_day, 'orders' => (int) $row->order_count]);
        $days = $weekdays->pluck('day')->unique()->sort()->values();
        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];

        $hours = collect($range)->map(fn (int $hour) => [
            'label' => sprintf('%02d:00', $hour),
            'orders' => (int) ($byHour[$hour] ?? collect())->sum('order_count'),
            'byDay' => $days->map(fn (int $day) => (int) $weekdays->where('day', $day)->where('hour', $hour)->sum('orders'))->all(),
        ]);

        $busiest = $hours->sortByDesc('orders')->first();

        return [
            'empty' => false,
            'busiest' => ['label' => $busiest['label'], 'orders' => $busiest['orders'], 'share' => (int) round($busiest['orders'] / $total * 100)],
            'days' => $days->map(fn (int $day) => $names[$day])->all(),
            'hours' => $hours->all(),
            'chart' => [
                'labels' => $hours->pluck('label')->all(),
                'datasets' => [['label' => __('Orders'), 'color' => 'accent', 'data' => $hours->pluck('orders')->all()]],
                'stacked' => false,
                // Counts, not pesos.
                'format' => 'count',
            ],
        ];
    }

    public static function status(User $user, Period $period): array
    {
        $daily = self::daily($period);
        $placed = (int) $daily->sum('order_count');

        $statuses = collect(['completed' => __('Completed'), 'pending' => __('Pending'), 'cancelled' => __('Cancelled')])
            ->map(function (string $label, string $status) use ($daily, $placed) {
                $rows = $daily->where('order_status', $status);
                $count = (int) $rows->sum('order_count');

                return [
                    'status' => $status,
                    'label' => $label,
                    'orders' => $count,
                    'amount' => round((float) $rows->sum('amount'), 2),
                    'share' => $placed > 0 ? round($count / $placed * 100, 1) : 0.0,
                ];
            })->values();

        return ['statuses' => $statuses->all(), 'placed' => $placed];
    }

    public static function methods(User $user, Period $period): array
    {
        $methods = self::daily($period)
            ->where('order_status', '!=', 'cancelled')
            ->groupBy(fn (object $row) => (string) $row->method_name)
            ->map(fn (Collection $rows, string $name) => [
                'name' => $name !== '' ? $name : __('No payment'),
                'orders' => (int) $rows->sum('order_count'),
                'sales' => round((float) $rows->where('order_status', 'completed')->sum('amount'), 2),
            ])
            ->sortBy([['sales', 'desc'], ['orders', 'desc'], ['name', 'asc']])
            ->values();

        $highest = (float) $methods->max('sales');
        $orders = (int) $methods->sum('orders');

        return [
            'methods' => $methods->map(fn (array $method) => $method + [
                'width' => $highest > 0 ? (int) round($method['sales'] / $highest * 100) : 0,
                'share' => $orders > 0 ? (int) round($method['orders'] / $orders * 100) : 0,
            ])->all(),
        ];
    }

    public static function basket(User $user, Period $period): array
    {
        $counts = $period->remember(['orders', 'order_items'], 'plates', fn () => Order::plateCounts($period->startAt(), $period->endAt()));
        $orders = (int) $counts->sum('order_count');
        $plates = (int) $counts->sum(fn (object $row) => $row->plates * $row->order_count);

        $kept = self::daily($period)->where('order_status', '!=', 'cancelled');
        $keptOrders = (int) $kept->sum('order_count');

        $sizes = collect([[1, 1, __('1 plate')], [2, 2, __('2 plates')], [3, 3, __('3 plates')], [4, 5, __('4 to 5 plates')], [6, PHP_INT_MAX, __('6 or more')]])
            ->map(fn (array $size) => [
                'label' => $size[2],
                'orders' => (int) $counts->filter(fn (object $row) => $row->plates >= $size[0] && $row->plates <= $size[1])->sum('order_count'),
            ]);
        $highest = (int) $sizes->max('orders');

        return [
            'orders' => $orders,
            'averagePlates' => $orders > 0 ? round($plates / $orders, 1) : 0.0,
            'averageAmount' => $keptOrders > 0 ? round((float) $kept->sum('amount') / $keptOrders, 2) : 0.0,
            'sizes' => $sizes->map(fn (array $size) => $size + [
                'width' => $highest > 0 ? (int) round($size['orders'] / $highest * 100) : 0,
                'share' => $orders > 0 ? (int) round($size['orders'] / $orders * 100) : 0,
            ])->all(),
        ];
    }

    public static function cancelled(User $user, Period $period): array
    {
        $totals = $period->remember(['orders', 'order_items'], 'cancelled', fn () => Order::cancelledTotals($period->startAt(), $period->endAt()));
        $reasons = $period->remember(['orders'], 'cancel_reasons', fn () => Order::cancelReasons($period->startAt(), $period->endAt(), $period->limit(self::REASONS_SHOWN)));

        $who = collect(['customer' => __('By the customer'), 'staff' => __('By staff'), 'unknown' => __('Not recorded')])
            ->map(function (string $label, string $key) use ($totals) {
                $row = $totals->firstWhere('who', $key);

                return ['label' => $label, 'orders' => (int) ($row->order_count ?? 0), 'amount' => round((float) ($row->amount ?? 0), 2)];
            })
            ->filter(fn (array $row) => $row['orders'] > 0)
            ->values();

        return [
            'orders' => (int) $totals->sum('order_count'),
            'amount' => round((float) $totals->sum('amount'), 2),
            'plates' => (int) $totals->sum('plates'),
            'who' => $who->all(),
            'reasons' => $reasons->map(fn (object $row) => ['reason' => $row->cancel_reason, 'orders' => (int) $row->order_count])->all(),
        ];
    }

    /* -------- Internals -------- */

    protected static function daily(Period $period): Collection
    {
        return $period->remember(['orders', 'payments', 'payment_methods'], 'daily', fn () => Order::dailyTotals($period->startAt(), $period->endAt()));
    }

    protected static function figures(Collection $daily, Collection $collected): array
    {
        $kept = $daily->where('order_status', '!=', 'cancelled');
        $orders = (int) $kept->sum('order_count');

        return [
            'sales' => round((float) $daily->where('order_status', 'completed')->sum('amount'), 2),
            'orders' => $orders,
            'average' => $orders > 0 ? round((float) $kept->sum('amount') / $orders, 2) : 0.0,
            'collected' => round((float) $collected->sum('amount'), 2),
            'pending' => round((float) $daily->where('order_status', 'pending')->sum('amount'), 2),
        ];
    }
}
