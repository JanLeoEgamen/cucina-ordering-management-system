<?php

namespace App\Support\Analytics;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The Customers tab. A customer "ordered" when they placed an order that was not cancelled.
// Everything is a total, except lapsed() and top(), which name people (and need `view orders`).
class CustomerWidgets
{
    public const REPEAT_DAYS = 30;

    public const REPEAT_COHORTS = 4;

    // No order for this many days = lapsed; longer than the second one ago is not listed.
    public const LAPSED_AFTER_DAYS = 30;

    public const LAPSED_WITHIN_DAYS = 180;

    public const NEVER_GRACE_DAYS = 7;

    public const ROWS_SHOWN = 15;

    public const TOP_SHOWN = 10;

    // New against returning, and how often they came back within the range.
    public static function overview(User $user, Period $period): array
    {
        $rows = $period->remember(['orders'], 'frequency', fn () => Order::customerFrequency($period->startAt(), $period->endAt()));

        $customers = (int) $rows->sum('customers');
        $new = (int) $rows->where('is_new', 1)->sum('customers');
        $orders = (int) $rows->sum('order_count');

        $buckets = collect([1 => __('1 order'), 2 => __('2 to 3 orders'), 4 => __('4 to 9 orders'), 10 => __('10 or more')])
            ->map(fn (string $label, int $bucket) => ['label' => $label, 'customers' => (int) $rows->where('bucket', $bucket)->sum('customers')])
            ->values();
        $highest = (int) $buckets->max('customers');

        return [
            'customers' => $customers,
            'new' => $new,
            'returning' => $customers - $new,
            'perCustomer' => $customers > 0 ? round($orders / $customers, 1) : 0.0,
            'buckets' => $buckets->map(fn (array $row) => $row + [
                'width' => $highest > 0 ? (int) round($row['customers'] / $highest * 100) : 0,
                'share' => $customers > 0 ? (int) round($row['customers'] / $customers * 100) : 0,
            ])->all(),
        ];
    }

    // Do they come back? For each recent month whose follow-up is over: of those who ordered in
    // it, the share who ordered again within 30 days of their first order that month.
    public static function repeat(User $user, Period $period): array
    {
        $cohorts = [];
        $month = now()->startOfMonth();

        // A month counts only once 30 days have passed since its last day.
        while (count($cohorts) < self::REPEAT_COHORTS) {
            $month = $month->copy()->subMonthNoOverflow();

            if ($month->copy()->endOfMonth()->addDays(self::REPEAT_DAYS)->lt(now())) {
                $cohorts[$month->format('Y-m')] = [$month->toDateString() . ' 00:00:00', $month->copy()->endOfMonth()->toDateString() . ' 23:59:59'];
            }
        }

        $cohorts = array_reverse($cohorts, true);
        $found = self::cached(['orders'], 'repeat.' . implode('.', array_keys($cohorts)), fn () => Order::repeatCohorts($cohorts, self::REPEAT_DAYS), $period);

        $months = collect(array_keys($cohorts))->map(function (string $key) use ($found) {
            $customers = (int) ($found[$key]->customers ?? 0);
            $returned = (int) ($found[$key]->returned ?? 0);

            return [
                'label' => Carbon::parse($key . '-01')->translatedFormat('M Y'),
                'customers' => $customers,
                'returned' => $returned,
                'rate' => $customers > 0 ? (int) round($returned / $customers * 100) : null,
            ];
        });

        return ['months' => $months->all(), 'days' => self::REPEAT_DAYS, 'empty' => $months->sum('customers') === 0];
    }

    // Who used to order and stopped. As of now: the date range does not apply.
    public static function lapsed(User $user, Period $period): array
    {
        $before = now()->subDays(self::LAPSED_AFTER_DAYS - 1)->toDateString() . ' 00:00:00';
        $since = now()->subDays(self::LAPSED_WITHIN_DAYS)->toDateString() . ' 00:00:00';
        $limit = $period->limit(self::ROWS_SHOWN);

        $found = self::cached(['orders', 'users'], 'lapsed.' . substr($since, 0, 10) . '.' . substr($before, 0, 10) . '.' . $limit, fn () => Order::lapsedCustomers($since, $before, $limit), $period);

        return [
            'total' => $found['total'],
            'rows' => $found['rows']->map(fn (object $row) => [
                'name' => $row->customer_name,
                'orders' => (int) $row->order_count,
                'last' => Carbon::parse($row->last_at)->translatedFormat('M j, Y'),
            ])->all(),
            'afterDays' => self::LAPSED_AFTER_DAYS,
            'withinDays' => self::LAPSED_WITHIN_DAYS,
        ];
    }

    // Accounts that can order and never have. As of now.
    public static function never(User $user, Period $period): array
    {
        $olderThan = now()->subDays(self::NEVER_GRACE_DAYS)->toDateString() . ' 00:00:00';

        $found = self::cached(
            ['users', 'orders', 'model_has_roles', 'role_has_permissions', 'permissions'],
            'never.' . substr($olderThan, 0, 10),
            fn () => User::neverOrdered($olderThan),
            $period
        );

        $accounts = (int) $found->accounts;
        $never = (int) $found->never_count;

        return [
            'accounts' => $accounts,
            'never' => $never,
            'older' => (int) $found->never_older,
            'share' => $accounts > 0 ? (int) round($never / $accounts * 100) : 0,
            'graceDays' => self::NEVER_GRACE_DAYS,
        ];
    }

    public static function companies(User $user, Period $period): array
    {
        $rows = $period->remember(['orders', 'users', 'companies'], 'companies', fn () => Order::totalsByCompany($period->startAt(), $period->endAt()));

        $companies = $rows->map(fn (object $row) => [
            'name' => $row->company_name ?? __('No company'),
            'orders' => (int) $row->order_count,
            'customers' => (int) $row->customers,
            'sales' => round((float) $row->sales, 2),
        ])->sortBy([['sales', 'desc'], ['orders', 'desc'], ['name', 'asc']])->values();

        return [
            'companies' => $companies->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $companies->count() - $period->limit(self::ROWS_SHOWN)),
        ];
    }

    // Of each company's accounts here, how many ordered. Not of its whole staff: the app only
    // knows the people who registered.
    public static function participation(User $user, Period $period): array
    {
        $rows = $period->remember(
            ['users', 'companies', 'orders', 'model_has_roles', 'role_has_permissions', 'permissions'],
            'participation',
            fn () => User::participationByCompany($period->startAt(), $period->endAt())
        );

        $companies = $rows->map(fn (object $row) => [
            'name' => $row->company_name ?? __('No company'),
            'registered' => (int) $row->registered,
            'ordering' => (int) $row->ordering,
            'rate' => $row->registered > 0 ? (int) round($row->ordering / $row->registered * 100) : 0,
        ])->sortBy([['registered', 'desc'], ['name', 'asc']])->values();

        return [
            'companies' => $companies->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $companies->count() - $period->limit(self::ROWS_SHOWN)),
        ];
    }

    public static function top(User $user, Period $period): array
    {
        $rows = $period->remember(
            ['orders', 'users', 'companies'],
            'top_customers',
            fn () => Order::topCustomers($period->startAt(), $period->endAt(), $period->limit(self::TOP_SHOWN))
        );

        return [
            'rows' => $rows->map(fn (object $row) => [
                'name' => $row->customer_name,
                'company' => $row->company_name,
                'orders' => (int) $row->order_count,
                'sales' => round((float) $row->sales, 2),
            ])->all(),
        ];
    }

    /* -------- Internals -------- */

    // For the widgets that ignore the page's range: cached by their own days (in the name).
    protected static function cached(array $tables, string $name, \Closure $load, Period $period): mixed
    {
        return $period->exporting ? $load() : \App\Support\TableCache::remember($tables, 'analytics.' . $name, $load);
    }
}
