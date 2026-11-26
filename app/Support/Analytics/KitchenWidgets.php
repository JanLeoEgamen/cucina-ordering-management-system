<?php

namespace App\Support\Analytics;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\SessionDish;
use App\Models\User;
use App\Support\Memo;
use App\Support\TableCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The Kitchen tab. Two sources: the order LINES of orders placed in the range (what sold), and the
// STOCK of the order sessions held in the range (what was prepared). A session that has not
// started is never counted.
class KitchenWidgets
{
    public const TOP_DISHES = 10;

    public const SLOW_DISHES = 5;

    public const ROWS_SHOWN = 15;

    public const SESSIONS_SHOWN = 30;

    // What sold best, what sold worst when it was offered, and by dish type.
    public static function dishSales(User $user, Period $period): array
    {
        $dishes = self::lines($period)
            ->groupBy('dish_id')
            ->map(fn (Collection $rows, int $dishId) => [
                'dish_id' => $dishId,
                'dish_name' => $rows->first()->dish_name,
                'plates' => (int) $rows->sum('plates'),
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->sortBy([['plates', 'desc'], ['amount', 'desc'], ['dish_name', 'asc']])
            ->values();

        $top = $dishes->take($period->limit(self::TOP_DISHES));
        $highest = (int) $top->max('plates');

        // Slow = fewest plates per session it was actually offered in, so a dish offered once is
        // not judged against one offered every day.
        $slow = self::sessionDishes($period)
            ->filter(fn (object $row) => $row->prepared > 0)
            ->groupBy('dish_id')
            ->map(fn (Collection $rows) => [
                'dish_name' => $rows->first()->dish_name,
                'sessions' => $rows->count(),
                'average' => round($rows->sum('ordered') / $rows->count(), 1),
            ])
            ->sortBy([['average', 'asc'], ['sessions', 'desc'], ['dish_name', 'asc']])
            ->values();

        $types = Dish::typesByDish();
        $byType = $dishes
            ->flatMap(fn (array $dish) => collect($types[$dish['dish_id']] ?? [])
                ->map(fn (object $type) => ['type' => $type->type_name, 'plates' => $dish['plates']]))
            ->groupBy('type')
            ->map(fn (Collection $rows, string $type) => ['type' => $type, 'plates' => (int) $rows->sum('plates')])
            ->sortBy([['plates', 'desc'], ['type', 'asc']])
            ->take($period->limit(self::TOP_DISHES))
            ->values();
        $typeHighest = (int) $byType->max('plates');

        return [
            'plates' => (int) $dishes->sum('plates'),
            'top' => $top->map(fn (array $dish) => $dish + ['width' => $highest > 0 ? (int) round($dish['plates'] / $highest * 100) : 0])->all(),
            // With only a handful of dishes the slow list would just repeat the top one.
            'slow' => $slow->count() > self::SLOW_DISHES ? $slow->take($period->limit(self::SLOW_DISHES))->all() : [],
            'types' => $byType->map(fn (array $type) => $type + ['width' => $typeHighest > 0 ? (int) round($type['plates'] / $typeHighest * 100) : 0])->all(),
        ];
    }

    // Plates prepared against plates ordered, over ENDED sessions: what was left over.
    public static function sellThrough(User $user, Period $period): array
    {
        $rows = self::sessionDishes($period)->filter(fn (object $row) => $row->ended && $row->prepared > 0);

        $dishes = $rows->groupBy('dish_id')
            ->map(fn (Collection $mine) => [
                'dish_name' => $mine->first()->dish_name,
                'sessions' => $mine->count(),
                'prepared' => (int) $mine->sum('prepared'),
                'ordered' => (int) $mine->sum('ordered'),
                'left' => (int) $mine->sum('left'),
                'leftPerSession' => round($mine->sum('left') / $mine->count(), 1),
                'rate' => (int) round($mine->sum('ordered') / $mine->sum('prepared') * 100),
            ])
            ->sortBy([['left', 'desc'], ['rate', 'asc'], ['dish_name', 'asc']])
            ->values();

        $prepared = (int) $rows->sum('prepared');

        return [
            'sessions' => $rows->unique('session_id')->count(),
            'prepared' => $prepared,
            'ordered' => (int) $rows->sum('ordered'),
            'left' => (int) $rows->sum('left'),
            'rate' => $prepared > 0 ? (int) round($rows->sum('ordered') / $prepared * 100) : 0,
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $dishes->count() - $period->limit(self::ROWS_SHOWN)),
        ];
    }

    // Which dishes run out, how often, and about how long after the session opened. The time is
    // the dish's LAST order, only for a dish with nothing left: later cancellations or stock edits
    // can change it.
    public static function selloutSpeed(User $user, Period $period): array
    {
        $rows = self::sessionDishes($period)->filter(fn (object $row) => $row->prepared > 0);

        if (! $rows->contains(fn (object $row) => $row->soldOut)) {
            return ['dishes' => [], 'more' => 0];
        }

        $last = $period->remember(
            ['order_items', 'orders', 'order_sessions'],
            'last_ordered',
            fn () => SessionDish::lastOrderedBetween($period->fromDate(), $period->toDate())
        );

        $dishes = self::selloutStats($rows, $last)
            ->filter(fn (array $dish) => $dish['soldOut'] > 0)
            // Most often first; between equals, the one that goes sooner.
            ->sortBy([['rate', 'desc'], ['soldOut', 'desc'], ['minutes', 'asc'], ['dish_name', 'asc']])
            ->values();

        return [
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->map(fn (array $dish) => $dish + ['after' => self::duration($dish['minutes'])])->all(),
            'more' => max(0, $dishes->count() - $period->limit(self::ROWS_SHOWN)),
        ];
    }

    // One line per session, newest first.
    public static function scorecard(User $user, Period $period): array
    {
        $totals = $period->remember(
            ['orders', 'order_sessions'],
            'by_session',
            fn () => Order::totalsBySession($period->fromDate(), $period->toDate())
        );

        $sessions = self::sessionDishes($period)
            ->groupBy('session_id')
            ->map(function (Collection $rows, int $sessionId) use ($totals, $user) {
                $first = $rows->first();
                $prepared = (int) $rows->sum('prepared');

                return [
                    'startsAt' => $first->startsAt->toDateTimeString(),
                    'date' => $first->startsAt->translatedFormat('D, M j'),
                    'window' => substr((string) $first->start_time, 0, 5) . ' – ' . substr((string) $first->end_time, 0, 5),
                    'ended' => $first->ended,
                    'orders' => (int) ($totals[$sessionId]->order_count ?? 0),
                    'sales' => round((float) ($totals[$sessionId]->sales ?? 0), 2),
                    'prepared' => $prepared,
                    'ordered' => (int) $rows->sum('ordered'),
                    'rate' => $prepared > 0 ? (int) round($rows->sum('ordered') / $prepared * 100) : 0,
                    'soldOut' => $rows->filter(fn (object $row) => $row->soldOut)->count(),
                    // Still running: what is left is not left over yet.
                    'left' => $first->ended ? (int) $rows->sum('left') : null,
                    'url' => $user->can('view order-sessions') ? route('order-sessions.show', $sessionId) : null,
                ];
            })
            ->sortByDesc('startsAt')
            ->values();

        return [
            'sessions' => $sessions->take($period->limit(self::SESSIONS_SHOWN))->all(),
            'more' => max(0, $sessions->count() - $period->limit(self::SESSIONS_SHOWN)),
        ];
    }

    // How much rice to cook: plates sold with and without it. An order line only records
    // "without", so a RICE DISH is one marked as coming with rice today, or one that was ordered
    // without it in the range; all of such a dish's other plates count as "with". (Taking only
    // the "without" lines of a dish since unticked would report 100% without rice.)
    public static function rice(User $user, Period $period): array
    {
        $lines = self::lines($period);
        $riceDishes = $lines->filter(fn (object $row) => $row->includes_rice || $row->no_rice)->pluck('dish_id')->flip();
        $rows = $lines->filter(fn (object $row) => $riceDishes->has($row->dish_id));

        $with = (int) $rows->where('no_rice', 0)->sum('plates');
        $without = (int) $rows->where('no_rice', 1)->sum('plates');
        $sessions = self::sessionDishes($period)->unique('session_id')->count();

        $dishes = $rows->groupBy('dish_id')
            ->map(function (Collection $mine) {
                $plates = (int) $mine->sum('plates');
                $without = (int) $mine->where('no_rice', 1)->sum('plates');

                return [
                    'dish_name' => $mine->first()->dish_name,
                    'with' => $plates - $without,
                    'without' => $without,
                    'rate' => $plates > 0 ? (int) round($without / $plates * 100) : 0,
                ];
            })
            ->filter(fn (array $dish) => $dish['without'] > 0)
            ->sortBy([['without', 'desc'], ['dish_name', 'asc']])
            ->take($period->limit(self::SLOW_DISHES))
            ->values();

        return [
            'with' => $with,
            'without' => $without,
            'rate' => $with + $without > 0 ? (int) round($without / ($with + $without) * 100) : 0,
            'perSession' => $sessions > 0 ? (int) ceil($with / $sessions) : null,
            'sessions' => $sessions,
            'dishes' => $dishes->all(),
        ];
    }

    /* -------- Shared with the planning widgets and the dashboard -------- */

    // Per dish id: how often it was offered in these session rows, how often it sold out, and the
    // middle number of minutes from a session's start to the last order of a sold out dish.
    // $last is SessionDish::lastOrderedBetween() for the same days.
    public static function selloutStats(Collection $rows, Collection $last): Collection
    {
        return $rows->filter(fn (object $row) => $row->prepared > 0)
            ->groupBy('dish_id')
            ->map(function (Collection $mine, int $dishId) use ($last) {
                $soldOut = $mine->filter(fn (object $row) => $row->soldOut);

                $minutes = $soldOut
                    ->filter(fn (object $row) => isset($last[$row->session_dish_id]))
                    ->map(fn (object $row) => max(0, (int) floor($row->startsAt->diffInMinutes(Carbon::parse($last[$row->session_dish_id]), false))))
                    ->values();

                return [
                    'dish_id' => $dishId,
                    'dish_name' => $mine->first()->dish_name,
                    'offered' => $mine->count(),
                    'soldOut' => $soldOut->count(),
                    'rate' => (int) round($soldOut->count() / $mine->count() * 100),
                    'minutes' => $minutes->isEmpty() ? null : (int) round($minutes->median()),
                ];
            });
    }

    // Every session dish between two days (Y-m-d), scheduled sessions included, each with what
    // the widgets need. The days are passed in, so the cached rows never depend on the clock.
    // ONLY for days the code works out itself (the last N days): they become a cache key. A
    // range a person picked goes through sessionDishes(), which never caches a typed one.
    public static function between(string $fromDate, string $toDate): Collection
    {
        return Memo::get('kitchen.between.' . $fromDate . '.' . $toDate, fn () => self::decorate(TableCache::remember(
            ['session_dishes', 'order_sessions', 'dishes'],
            'analytics.session_rows.' . $fromDate . '.' . $toDate,
            fn () => SessionDish::ofSessionsBetween($fromDate, $toDate)
        )));
    }

    // The same for the last $days days, today included, without the sessions not started yet.
    public static function recent(int $days): Collection
    {
        return self::between(now()->subDays($days - 1)->toDateString(), now()->toDateString())
            ->reject(fn (object $row) => $row->status === 'scheduled')
            ->values();
    }

    // When each dish was last ordered, for days the code works out itself (see between()).
    public static function lastOrdered(string $fromDate, string $toDate): Collection
    {
        return Memo::get('kitchen.last_ordered.' . $fromDate . '.' . $toDate, fn () => TableCache::remember(
            ['order_items', 'orders', 'order_sessions'],
            'analytics.last_ordered.' . $fromDate . '.' . $toDate,
            fn () => SessionDish::lastOrderedBetween($fromDate, $toDate)
        ));
    }

    // The stock rows of the range's started sessions, each with what the widgets need. The rows
    // are cached (a preset range only); everything that depends on the clock is worked out here,
    // once per page: six widgets of the Kitchen tab read them.
    public static function sessionDishes(Period $period): Collection
    {
        $key = 'kitchen.started.' . $period->fromDate() . '.' . $period->toDate() . ($period->exporting ? '.csv' : '');

        return Memo::get($key, function () use ($period) {
            $rows = $period->remember(
                ['session_dishes', 'order_sessions', 'dishes'],
                'session_dishes',
                fn () => SessionDish::ofSessionsBetween($period->fromDate(), $period->toDate())
            );

            return self::decorate($rows)->filter(fn (object $row) => $row->status !== 'scheduled')->values();
        });
    }

    // 135 -> "2 h 15 min"
    public static function duration(?int $minutes): ?string
    {
        if ($minutes === null) {
            return null;
        }

        if ($minutes < 60) {
            return __(':count min', ['count' => $minutes]);
        }

        return __(':hours h :minutes min', ['hours' => intdiv($minutes, 60), 'minutes' => $minutes % 60]);
    }

    /* -------- Internals -------- */

    protected static function lines(Period $period): Collection
    {
        return $period->remember(
            ['order_items', 'orders', 'session_dishes', 'dishes'],
            'sold',
            fn () => OrderItem::soldBetween($period->startAt(), $period->endAt())
        );
    }

    // The raw stock rows turned into what the widgets work with.
    protected static function decorate(Collection $rows): Collection
    {
        $states = [];

        return $rows->map(function (object $row) use (&$states) {
            $state = $states[$row->session_id] ??= [
                'status' => OrderSession::statusOf((object) [
                    'order_date' => $row->order_date,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                    'ended_at' => $row->ended_at,
                    'is_active' => $row->session_is_active,
                ]),
                'startsAt' => Carbon::parse(substr((string) $row->order_date, 0, 10) . ' ' . $row->start_time),
            ];

            $offered = $row->is_available && $row->is_active && $row->deleted_at === null;
            $ordered = (int) $row->reserved_stock;
            $left = $offered ? (int) $row->available_stock : 0;

            return (object) [
                'session_dish_id' => (int) $row->session_dish_id,
                'session_id' => (int) $row->session_id,
                'dish_id' => (int) $row->dish_id,
                'dish_name' => $row->dish_name,
                'start_time' => $row->start_time,
                'end_time' => $row->end_time,
                'startsAt' => $state['startsAt'],
                'status' => $state['status'],
                'ended' => $state['status'] === 'ended',
                'ordered' => $ordered,
                'left' => $left,
                'prepared' => $ordered + $left,
                'offered' => $offered,
                'soldOut' => $offered && $left === 0 && $ordered > 0,
                // Today's state of the dish itself (not of that session).
                'dishLive' => (bool) $row->dish_is_active && $row->dish_deleted_at === null,
                'dishAddedOn' => substr((string) $row->dish_created_at, 0, 10),
            ];
        });
    }
}
