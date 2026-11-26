<?php

namespace App\Support\Dashboard;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Analytics\KitchenWidgets;
use App\Support\TableCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// "Smart insights": short sentences from plain rules (no AI), each saying what was seen, with its
// numbers. A rule only runs for someone who may see the data behind it (rules()), and a trend
// needs a few sessions or orders before it says anything.
class InsightWidgets
{
    public const LOOKBACK_DAYS = 60;

    // Sold out in this share of a dish's last SOLD_OUT_LAST sessions (at least MIN_SESSIONS).
    public const SOLD_OUT_LAST = 5;

    public const SOLD_OUT_SHARE = 60;

    // More than this share left over in each of the last LEFTOVER_LAST sessions.
    public const LEFTOVER_LAST = 3;

    public const LEFTOVER_SHARE = 40;

    public const MIN_SESSIONS = 3;

    public const WAITING_MINUTES = 30;

    // Sales this much lower than the week before, which had at least MIN_ORDERS orders.
    public const SALES_DROP = 20;

    public const MIN_ORDERS = 10;

    // The most dishes one rule names.
    public const PER_RULE = 3;

    // The permissions that unlock at least one rule: the widget `requires` one of them.
    public const SOURCES = [
        'view payments', 'review discounts', 'view order-sessions', 'view analytics-sellout-speed',
        'view analytics-prep-suggestions', 'view analytics-sell-through', 'view analytics-sales-summary',
    ];

    // null = nothing stands out, and the card is not drawn.
    public static function insights(User $user): ?array
    {
        $insights = array_merge(self::waiting($user), self::tomorrow($user), self::sales($user), self::dishes($user));

        return $insights === [] ? null : ['insights' => $insights];
    }

    /* -------- Rules -------- */

    protected static function waiting(User $user): array
    {
        $found = [];

        if (! $user->canAny(['view payments', 'review discounts'])) {
            return $found;
        }

        $late = now()->subMinutes(self::WAITING_MINUTES)->toDateTimeString();
        // Waiting since the order was placed (a resent proof is not timed apart).
        $old = Order::openForDashboard()->filter(fn (object $order) => (string) $order->placed_at <= $late);

        if ($user->can('view payments')) {
            $count = $old->filter(fn (object $order) => $order->payment_state === 'pending' && $order->method_name !== PaymentMethod::CASH_ON_PICKUP)->count();

            if ($count > 0) {
                $found[] = self::insight(
                    'rejected',
                    trans_choice(':count payment has waited more than :minutes minutes to be verified.|:count payments have waited more than :minutes minutes to be verified.', $count, ['minutes' => self::WAITING_MINUTES]),
                    route('payments.index')
                );
            }
        }

        if ($user->can('review discounts')) {
            $count = $old->where('discount_status', 'pending')->count();

            if ($count > 0) {
                $found[] = self::insight(
                    'rejected',
                    trans_choice(':count discount ID has waited more than :minutes minutes for a review.|:count discount IDs have waited more than :minutes minutes for a review.', $count, ['minutes' => self::WAITING_MINUTES]),
                    $user->can('view orders') ? route('orders.index') : null
                );
            }
        }

        return $found;
    }

    protected static function tomorrow(User $user): array
    {
        if (! $user->can('view order-sessions')) {
            return [];
        }

        $day = now()->addDay()->toDateString();
        $planned = OrderSession::getAll()->contains(
            fn (object $session) => substr((string) $session->order_date, 0, 10) === $day && $session->status !== 'ended'
        );

        return $planned ? [] : [self::insight('pending', __('No order session is scheduled for tomorrow.'), route('order-sessions.index'))];
    }

    // The last seven FULL days against the seven before, so no part of a day is compared.
    protected static function sales(User $user): array
    {
        if (! $user->can('view analytics-sales-summary')) {
            return [];
        }

        $end = now()->subDay()->toDateString();
        $middle = now()->subDays(7)->toDateString();
        $start = now()->subDays(14)->toDateString();

        $totals = TableCache::remember(
            ['orders', 'payments', 'payment_methods'],
            'insights.sales.' . $start . '.' . $end,
            fn () => Order::dailyTotals($start . ' 00:00:00', $end . ' 23:59:59')
        );

        $recent = $totals->filter(fn (object $row) => $row->day >= $middle);
        $before = $totals->filter(fn (object $row) => $row->day < $middle);
        $sales = fn (Collection $rows) => (float) $rows->where('order_status', 'completed')->sum('amount');

        if ($before->where('order_status', '!=', 'cancelled')->sum('order_count') < self::MIN_ORDERS || $sales($before) <= 0) {
            return [];
        }

        $drop = (int) round(($sales($before) - $sales($recent)) / $sales($before) * 100);

        if ($drop < self::SALES_DROP) {
            return [];
        }

        return [self::insight('pending', __('Sales of the last 7 days are :drop% lower than the 7 days before (:now against :before).', [
            'drop' => $drop,
            'now' => '₱' . number_format($sales($recent), 2),
            'before' => '₱' . number_format($sales($before), 2),
        ]), route('analytics.index', ['tab' => 'sales']))];
    }

    protected static function dishes(User $user): array
    {
        $more = $user->canAny(['view analytics-sellout-speed', 'view analytics-prep-suggestions']);
        $less = $user->can('view analytics-sell-through');

        if (! $more && ! $less) {
            return [];
        }

        $byDish = KitchenWidgets::recent(self::LOOKBACK_DAYS)
            ->filter(fn (object $row) => $row->ended && $row->dishLive && $row->offered && $row->prepared > 0)
            ->groupBy('dish_id')
            // Newest first.
            ->map(fn (Collection $rows) => $rows->sortByDesc(fn (object $row) => $row->startsAt->getTimestamp())->values());

        $url = route('analytics.index', ['tab' => 'kitchen']);
        $found = [];

        if ($more) {
            $found = $byDish
                ->map(fn (Collection $rows) => $rows->take(self::SOLD_OUT_LAST))
                ->filter(fn (Collection $rows) => $rows->count() >= self::MIN_SESSIONS)
                ->map(fn (Collection $rows) => [
                    'name' => $rows->first()->dish_name,
                    'times' => $rows->filter(fn (object $row) => $row->soldOut)->count(),
                    'of' => $rows->count(),
                ])
                ->filter(fn (array $dish) => $dish['times'] * 100 >= $dish['of'] * self::SOLD_OUT_SHARE)
                ->sortBy([fn (array $a, array $b) => ($b['times'] / $b['of']) <=> ($a['times'] / $a['of']), ['times', 'desc'], ['name', 'asc']])
                ->take(self::PER_RULE)
                ->map(fn (array $dish) => self::insight('ready', __(':dish sold out in :times of its last :of sessions. Consider preparing more.', ['dish' => $dish['name'], 'times' => $dish['times'], 'of' => $dish['of']]), $url))
                ->values()
                ->all();
        }

        if ($less) {
            $over = $byDish
                ->map(fn (Collection $rows) => $rows->take(self::LEFTOVER_LAST))
                ->filter(fn (Collection $rows) => $rows->count() >= self::LEFTOVER_LAST
                    && $rows->every(fn (object $row) => $row->left * 100 > $row->prepared * self::LEFTOVER_SHARE))
                // The most plates left first.
                ->sortByDesc(fn (Collection $rows) => $rows->sum('left'))
                ->take(self::PER_RULE)
                ->map(fn (Collection $rows) => self::insight('pending', __(':dish had more than :share% left over in each of its last :of sessions (:left of :prepared plates). Consider preparing less.', [
                    'dish' => $rows->first()->dish_name,
                    'share' => self::LEFTOVER_SHARE,
                    'of' => $rows->count(),
                    'left' => $rows->sum('left'),
                    'prepared' => $rows->sum('prepared'),
                ]), $url))
                ->values()
                ->all();

            $found = array_merge($found, $over);
        }

        return $found;
    }

    // `status` is the dot's colour (data-status).
    protected static function insight(string $status, string $text, ?string $url): array
    {
        return ['status' => $status, 'text' => $text, 'url' => $url];
    }
}
