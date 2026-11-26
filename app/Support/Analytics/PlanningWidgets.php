<?php

namespace App\Support\Analytics;

use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Support\Collection;

// The Kitchen tab's widgets that help decide what to cook. All but weekdays() and pairings()
// look at a FIXED stretch up to today and ignore the page's date range: the question is "what
// now". Each says "not enough data" rather than guessing from a session or two.
class PlanningWidgets
{
    /* ---- how many plates to prepare ---- */

    public const PREP_LOOKBACK_DAYS = 90;

    // The newest ended sessions a dish's suggestion is averaged over, and the fewest it needs.
    public const PREP_SAMPLE = 6;

    public const MIN_SESSIONS = 3;

    // Added on top of the average ordered; more when the dish sold out in half the sample or
    // more, because then people wanted more than could be ordered. Such a dish also gets at
    // least that much on top of the number of plates it ran out at.
    public const PREP_BUFFER = 0.10;

    public const PREP_SOLD_OUT_BUFFER = 0.20;

    /* ---- the others ---- */

    public const BRING_BACK_DAYS = 180;

    public const BRING_BACK_AWAY_DAYS = 14;

    public const FATIGUE_DAYS = 90;

    public const FATIGUE_MIN_SESSIONS = 6;

    public const FATIGUE_DROP = 25;

    public const NEW_DISH_DAYS = 60;

    public const NEW_DISH_SESSIONS = 3;

    // Within this many percent of the menu's average is "about average".
    public const NEW_DISH_BAND = 15;

    public const PAIR_MIN_ORDERS = 3;

    public const ROWS_SHOWN = 15;

    public static function prep(User $user, Period $period): array
    {
        $byDish = KitchenWidgets::recent(self::PREP_LOOKBACK_DAYS)
            ->filter(fn (object $row) => $row->ended && $row->dishLive && $row->offered && $row->prepared > 0)
            ->groupBy('dish_id');

        $dishes = $byDish
            ->filter(fn (Collection $rows) => $rows->count() >= self::MIN_SESSIONS)
            ->map(function (Collection $rows) {
                $sample = $rows->sortByDesc(fn (object $row) => $row->startsAt->getTimestamp())->take(self::PREP_SAMPLE);
                $ordered = $sample->sum('ordered') / $sample->count();
                $prepared = $sample->sum('prepared') / $sample->count();
                $ranOut = $sample->filter(fn (object $row) => $row->soldOut);
                $soldOut = $ranOut->count();
                $often = $soldOut * 2 >= $sample->count();
                // How many plates there were when it ran out (everything offered was ordered).
                $ranOutAt = $often ? $ranOut->sum('ordered') / $soldOut : 0;
                // round() first: 10 x 1.1 is 11.000000000000002, which ceil() would make 12.
                $plates = fn (float $value, float $buffer) => (int) ceil(round($value * (1 + $buffer), 4));
                // A dish that often runs out is never suggested below what was not enough: quiet
                // days with a big stock would otherwise pull the average under it.
                $suggested = max(
                    1,
                    $plates($ordered, $often ? self::PREP_SOLD_OUT_BUFFER : self::PREP_BUFFER),
                    $often ? $plates($ranOutAt, self::PREP_SOLD_OUT_BUFFER) : 0
                );

                return [
                    'dish_name' => $sample->first()->dish_name,
                    'sessions' => $sample->count(),
                    'ordered' => round($ordered, 1),
                    'prepared' => round($prepared, 1),
                    'soldOut' => $soldOut,
                    'demandHigher' => $often,
                    'ranOutAt' => $often ? (int) round($ranOutAt) : null,
                    'suggested' => $suggested,
                    'change' => (int) round($suggested - $prepared),
                ];
            })
            // The biggest change from what has been prepared comes first.
            ->sortBy([[fn (array $a, array $b) => abs($b['change']) <=> abs($a['change'])], ['dish_name', 'asc']])
            ->values();

        return [
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $dishes->count() - $period->limit(self::ROWS_SHOWN)),
            // Dishes still on the menu with some history, but too little of it.
            'tooFew' => $byDish->filter(fn (Collection $rows) => $rows->count() < self::MIN_SESSIONS)->count(),
            'lookbackDays' => self::PREP_LOOKBACK_DAYS,
        ];
    }

    // Average plates ordered per dish on each weekday, over the range's ENDED sessions.
    public static function weekdays(User $user, Period $period): array
    {
        // The range's own rows (a typed range is never cached): NOT between(), which is for days
        // the code works out itself.
        $rows = KitchenWidgets::sessionDishes($period)
            ->filter(fn (object $row) => $row->ended && $row->prepared > 0);

        // Monday first.
        $days = $rows->map(fn (object $row) => $row->startsAt->dayOfWeekIso)->unique()->sort()->values();
        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];

        $dishes = $rows->groupBy('dish_id')
            ->map(function (Collection $mine) use ($days) {
                $averages = $days->map(function (int $day) use ($mine) {
                    $that = $mine->filter(fn (object $row) => $row->startsAt->dayOfWeekIso === $day);

                    return $that->isEmpty() ? null : round($that->sum('ordered') / $that->count(), 1);
                });

                $known = $averages->filter(fn ($value) => $value !== null);

                return [
                    'dish_name' => $mine->first()->dish_name,
                    'ordered' => (int) $mine->sum('ordered'),
                    'averages' => $averages->all(),
                    // A best day only means something with two days to choose between.
                    'best' => $known->count() > 1 && $known->max() > $known->min() ? $averages->search($known->max()) : null,
                ];
            })
            ->sortBy([['ordered', 'desc'], ['dish_name', 'asc']])
            ->values();

        return [
            'days' => $days->map(fn (int $day) => $names[$day])->all(),
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $dishes->count() - $period->limit(self::ROWS_SHOWN)),
        ];
    }

    // Dishes that sold at least as well as the typical one, have not been offered lately and are
    // not on any session that is running or planned.
    public static function bringBack(User $user, Period $period): array
    {
        $today = now()->startOfDay();
        $all = KitchenWidgets::between($today->copy()->subDays(self::BRING_BACK_DAYS)->toDateString(), $today->copy()->addDays(60)->toDateString());

        $planned = $all->filter(fn (object $row) => $row->offered && $row->status !== 'ended')->pluck('dish_id')->flip();

        $history = $all->filter(fn (object $row) => $row->ended && $row->prepared > 0)
            ->groupBy('dish_id')
            ->filter(fn (Collection $rows) => $rows->count() >= 2)
            ->map(fn (Collection $rows) => [
                'dish_id' => $rows->first()->dish_id,
                'dish_name' => $rows->first()->dish_name,
                'live' => $rows->first()->dishLive,
                'sessions' => $rows->count(),
                'average' => round($rows->sum('ordered') / $rows->count(), 1),
                'last' => $rows->max(fn (object $row) => $row->startsAt->toDateString()),
            ]);

        $typical = (float) $history->median('average');
        $cutoff = $today->copy()->subDays(self::BRING_BACK_AWAY_DAYS)->toDateString();

        $dishes = $history
            ->filter(fn (array $dish) => $dish['live'] && ! $planned->has($dish['dish_id']) && $dish['last'] < $cutoff && $dish['average'] >= $typical)
            ->sortBy([['average', 'desc'], ['last', 'asc'], ['dish_name', 'asc']])
            ->values()
            ->map(fn (array $dish) => [
                'dish_name' => $dish['dish_name'],
                'sessions' => $dish['sessions'],
                'average' => $dish['average'],
                'last' => \Illuminate\Support\Carbon::parse($dish['last'])->translatedFormat('M j'),
                'daysAgo' => (int) \Illuminate\Support\Carbon::parse($dish['last'])->diffInDays($today),
            ]);

        return [
            'dishes' => $dishes->take($period->limit(10))->all(),
            'typical' => round($typical, 1),
            'awayDays' => self::BRING_BACK_AWAY_DAYS,
            'lookbackDays' => self::BRING_BACK_DAYS,
        ];
    }

    // Dishes offered often whose plates per session fell: the newer half of their sessions
    // against the older half (with an odd number, the middle one is in neither).
    public static function fatigue(User $user, Period $period): array
    {
        $dishes = KitchenWidgets::recent(self::FATIGUE_DAYS)
            ->filter(fn (object $row) => $row->ended && $row->dishLive && $row->prepared > 0)
            ->groupBy('dish_id')
            ->filter(fn (Collection $rows) => $rows->count() >= self::FATIGUE_MIN_SESSIONS)
            ->map(function (Collection $rows) {
                $ordered = $rows->sortBy(fn (object $row) => $row->startsAt->getTimestamp())->values();
                $half = intdiv($ordered->count(), 2);
                $before = $ordered->take($half)->sum('ordered') / $half;
                $now = $ordered->slice(-$half)->sum('ordered') / $half;

                return [
                    'dish_name' => $ordered->first()->dish_name,
                    'sessions' => $ordered->count(),
                    'before' => round($before, 1),
                    'now' => round($now, 1),
                    'drop' => $before > 0 ? (int) round(($before - $now) / $before * 100) : 0,
                ];
            })
            ->filter(fn (array $dish) => $dish['drop'] >= self::FATIGUE_DROP)
            ->sortBy([['drop', 'desc'], ['dish_name', 'asc']])
            ->values();

        return [
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->all(),
            'minSessions' => self::FATIGUE_MIN_SESSIONS,
            'lookbackDays' => self::FATIGUE_DAYS,
        ];
    }

    // How dishes added lately did in their first sessions, beside the average dish of the same
    // days.
    public static function newDishes(User $user, Period $period): array
    {
        $rows = KitchenWidgets::recent(self::NEW_DISH_DAYS)->filter(fn (object $row) => $row->ended && $row->prepared > 0);
        $usual = $rows->isEmpty() ? 0.0 : $rows->sum('ordered') / $rows->count();
        $since = now()->subDays(self::NEW_DISH_DAYS - 1)->toDateString();

        $dishes = $rows->filter(fn (object $row) => $row->dishLive && $row->dishAddedOn >= $since)
            ->groupBy('dish_id')
            ->map(function (Collection $mine) use ($usual) {
                $first = $mine->sortBy(fn (object $row) => $row->startsAt->getTimestamp())->take(self::NEW_DISH_SESSIONS);
                $average = $first->sum('ordered') / $first->count();
                $against = $usual > 0 ? (int) round(($average - $usual) / $usual * 100) : 0;

                return [
                    'dish_name' => $first->first()->dish_name,
                    'added' => \Illuminate\Support\Carbon::parse($first->first()->dishAddedOn)->translatedFormat('M j'),
                    'addedOn' => $first->first()->dishAddedOn,
                    'sessions' => $first->count(),
                    'average' => round($average, 1),
                    'rate' => (int) round($first->sum('ordered') / $first->sum('prepared') * 100),
                    'against' => $against,
                    'verdict' => match (true) {
                        $first->count() < self::NEW_DISH_SESSIONS => 'early',
                        $against > self::NEW_DISH_BAND => 'above',
                        $against < -self::NEW_DISH_BAND => 'below',
                        default => 'average',
                    },
                ];
            })
            ->sortBy([['addedOn', 'desc'], ['dish_name', 'asc']])
            ->values();

        return [
            'dishes' => $dishes->take($period->limit(self::ROWS_SHOWN))->all(),
            'more' => max(0, $dishes->count() - $period->limit(self::ROWS_SHOWN)),
            'usual' => round($usual, 1),
            'days' => self::NEW_DISH_DAYS,
            'sample' => self::NEW_DISH_SESSIONS,
        ];
    }

    // Dishes often ordered together.
    public static function pairings(User $user, Period $period): array
    {
        $pairs = $period->remember(
            ['order_items', 'orders', 'session_dishes', 'dishes'],
            'pairs',
            fn () => OrderItem::dishPairs($period->startAt(), $period->endAt(), self::PAIR_MIN_ORDERS, $period->limit(10))
        );

        if ($pairs->isEmpty()) {
            return ['pairs' => [], 'minOrders' => self::PAIR_MIN_ORDERS];
        }

        $perDish = $period->remember(
            ['order_items', 'orders', 'session_dishes'],
            'orders_per_dish',
            fn () => OrderItem::ordersPerDish($period->startAt(), $period->endAt())
        );

        return [
            'pairs' => $pairs->map(function (object $pair) use ($perDish) {
                $first = (int) ($perDish[$pair->first_id] ?? 0);
                $other = (int) ($perDish[$pair->other_id] ?? 0);
                // The share is read from the dish ordered less: "of those who took it".
                [$rare, $common] = $first <= $other
                    ? [[$pair->first_name, $first], $pair->other_name]
                    : [[$pair->other_name, $other], $pair->first_name];

                return [
                    'dishes' => $pair->first_name . ' + ' . $pair->other_name,
                    'orders' => (int) $pair->order_count,
                    'of' => $rare[0],
                    'with' => $common,
                    'share' => $rare[1] > 0 ? (int) round($pair->order_count / $rare[1] * 100) : 0,
                ];
            })->all(),
            'minOrders' => self::PAIR_MIN_ORDERS,
        ];
    }
}
