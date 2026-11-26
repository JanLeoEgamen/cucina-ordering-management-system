<?php

namespace App\Support\Dashboard;

use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Models\Setting;
use App\Models\User;
use App\Support\Analytics\KitchenWidgets;
use App\Support\Memo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

// The dashboard widgets about the person looking at it. Every one reads ONLY the rows of the user
// it is given (always the signed-in one: nothing here takes an id from a request), except
// popular(), which is an anonymous list of dish names. A method returns null when there is
// nothing worth showing, and the widget is then not drawn.
class MyWidgets
{
    // How far back favourites and the usual order look: this month and the 11 before it.
    public const HISTORY_MONTHS = 12;

    // The months the spending bars show.
    public const SPENDING_MONTHS = 6;

    // Active orders listed on the card; the rest are counted.
    public const ACTIVE_SHOWN = 5;

    // The newest orders the usual order is looked for in, and how often it must repeat.
    public const USUAL_LOOKBACK = 50;

    public const USUAL_MIN_TIMES = 2;

    // "Popular this week": that many days (today included), and how many DIFFERENT people must
    // have ordered a dish before it is listed.
    public const POPULAR_DAYS = 7;

    public const POPULAR_MIN_BUYERS = 2;

    // The sell-out alert looks at the person's top dishes over this many days, and speaks when
    // one sold out in this share of at least this many sessions.
    public const ALERT_FAVOURITES = 5;

    public const ALERT_DAYS = 30;

    public const ALERT_MIN_SESSIONS = 3;

    public const ALERT_SHARE = 40;

    // Where each of the person's orders stands, the ones needing them first. Always drawn.
    public static function orders(User $user): array
    {
        $active = self::pending($user)
            ->map(fn (object $order) => [
                'number' => $order->order_number,
                'url' => self::orderUrl((int) $order->order_id),
                'total' => (float) $order->total_amount,
            ] + self::stateOf($order))
            // The list is newest first already, and sortBy keeps that order within one rank.
            ->sortBy('rank')
            ->values();

        return [
            'orders' => $active->take(self::ACTIVE_SHOWN)->all(),
            'more' => max(0, $active->count() - self::ACTIVE_SHOWN),
        ];
    }

    // What to have in hand at the counter. Orders whose payment was rejected are not here: they
    // need fixing first (the orders widget says so).
    public static function pickup(User $user): ?array
    {
        $open = self::pending($user)->reject(fn (object $order) => $order->payment_status === 'rejected');

        if ($open->isEmpty()) {
            return null;
        }

        $cash = $open->filter(fn (object $order) => self::isCash($order) && $order->payment_status === 'pending');
        $ready = $open->whereNotNull('ready_at');

        return [
            'cashDue' => round((float) $cash->sum('total_amount'), 2),
            'cashOrders' => $cash->count(),
            'ready' => $ready->map(fn (object $order) => [
                'number' => $order->order_number,
                'url' => self::orderUrl((int) $order->order_id),
            ])->values()->all(),
            'notReady' => $open->count() - $ready->count(),
            // A discount still being checked can change what is owed.
            'priceMayChange' => $cash->contains(fn (object $order) => $order->discount_status === 'pending'),
        ];
    }

    // The dishes the person orders most, by plates, with whether each can be had right now.
    public static function favorites(User $user): ?array
    {
        $dishes = self::history($user)
            ->flatMap(fn (object $order) => collect($order->lines)->map(fn (array $line) => $line + ['order_id' => $order->order_id]))
            ->groupBy('dish_id')
            ->map(fn (Collection $lines, int $dishId) => [
                'dish_id' => $dishId,
                'dish_name' => $lines->first()['dish_name'],
                'plates' => $lines->sum('quantity'),
                // With and without rice are two lines of one order.
                'orders' => $lines->unique('order_id')->count(),
            ])
            ->sortBy([['plates', 'desc'], ['orders', 'desc'], ['dish_name', 'asc']])
            ->take(5)
            ->values();

        if ($dishes->isEmpty()) {
            return null;
        }

        $stock = self::liveStock($user);

        return [
            'dishes' => $dishes->map(fn (array $dish) => $dish + [
                // null = nothing to say (no live menu, or the person may not open it).
                'left' => $stock === null ? null : ($stock[$dish['dish_id']] ?? null),
            ])->all(),
            'menuOpen' => $stock !== null,
        ];
    }

    // The same set of dishes and quantities ordered again and again.
    public static function usual(User $user): ?array
    {
        $usual = self::history($user)
            ->filter(fn (object $order) => $order->lines !== [])
            ->take(self::USUAL_LOOKBACK)
            ->map(function (object $order) {
                $lines = collect($order->lines)
                    ->groupBy('dish_id')
                    ->map(fn (Collection $same) => [
                        'dish_id' => $same->first()['dish_id'],
                        'dish_name' => $same->first()['dish_name'],
                        'quantity' => $same->sum('quantity'),
                    ])
                    ->sortBy('dish_id')
                    ->values();

                return [
                    'key' => $lines->map(fn (array $line) => $line['dish_id'] . 'x' . $line['quantity'])->implode(','),
                    'lines' => $lines->all(),
                ];
            })
            ->groupBy('key')
            ->filter(fn (Collection $same) => $same->count() >= self::USUAL_MIN_TIMES)
            // Groups come newest first and the sort is stable: a tie goes to the more recent one.
            ->sortByDesc(fn (Collection $same) => $same->count())
            ->first();

        if (! $usual) {
            return null;
        }

        $lines = $usual->first()['lines'];
        $stock = self::liveStock($user);

        // Dishes of the usual order that cannot be had right now in that quantity.
        $short = $stock === null ? [] : collect($lines)
            ->filter(fn (array $line) => ($stock[$line['dish_id']] ?? 0) < $line['quantity'])
            ->pluck('dish_name')
            ->values()
            ->all();

        return [
            'lines' => $lines,
            'times' => $usual->count(),
            'menuOpen' => $stock !== null,
            'short' => $short,
        ];
    }

    // What the person paid for completed orders, month by month.
    public static function spending(User $user): ?array
    {
        $months = collect(range(self::SPENDING_MONTHS - 1, 0))
            ->map(fn (int $ago) => now()->startOfMonth()->subMonthsNoOverflow($ago));
        $keys = $months->map->format('Y-m')->all();

        $completed = self::history($user)
            ->where('order_status', 'completed')
            ->filter(fn (object $order) => in_array(substr((string) $order->order_date, 0, 7), $keys, true));

        if ($completed->isEmpty()) {
            return null;
        }

        $totals = $completed->groupBy(fn (object $order) => substr((string) $order->order_date, 0, 7))
            ->map(fn (Collection $orders) => round((float) $orders->sum('total_amount'), 2));
        $highest = max(0.01, (float) $totals->max());

        $bars = $months->map(function ($month) use ($totals, $highest) {
            $total = (float) ($totals[$month->format('Y-m')] ?? 0);

            return [
                'label' => $month->translatedFormat('M'),
                'total' => $total,
                'percent' => (int) round($total / $highest * 100),
            ];
        })->values();

        return [
            'months' => $bars->all(),
            'thisMonth' => $bars->last()['total'],
            'lastMonth' => $bars[$bars->count() - 2]['total'],
            'saved' => round((float) $completed->where('discount_status', 'approved')->sum('discount_amount'), 2),
        ];
    }

    // A favourite that usually runs out: "order early". Needs a few sessions to say so.
    public static function selloutAlert(User $user): ?array
    {
        $favorites = self::history($user)
            ->flatMap(fn (object $order) => $order->lines)
            ->groupBy('dish_id')
            ->sortByDesc(fn (Collection $lines) => $lines->sum('quantity'))
            ->take(self::ALERT_FAVOURITES)
            ->keys()
            ->flip();

        if ($favorites->isEmpty()) {
            return null;
        }

        $from = now()->subDays(self::ALERT_DAYS - 1)->toDateString();
        $to = now()->toDateString();

        // Only finished sessions: today's running one has not had its chance to sell out.
        $rows = KitchenWidgets::recent(self::ALERT_DAYS)
            ->filter(fn (object $row) => $row->ended && $favorites->has($row->dish_id));

        if (! $rows->contains(fn (object $row) => $row->soldOut)) {
            return null;
        }

        $stock = self::liveStock($user);

        $dishes = KitchenWidgets::selloutStats($rows, KitchenWidgets::lastOrdered($from, $to))
            ->filter(fn (array $dish) => $dish['offered'] >= self::ALERT_MIN_SESSIONS && $dish['rate'] >= self::ALERT_SHARE && $dish['minutes'] !== null)
            ->sortBy([['rate', 'desc'], ['minutes', 'asc'], ['dish_name', 'asc']])
            ->take(3)
            ->values();

        if ($dishes->isEmpty()) {
            return null;
        }

        return [
            'dishes' => $dishes->map(fn (array $dish) => [
                'dish_name' => $dish['dish_name'],
                'soldOut' => $dish['soldOut'],
                'offered' => $dish['offered'],
                'after' => KitchenWidgets::duration($dish['minutes']),
                'left' => $stock === null ? null : ($stock[$dish['dish_id']] ?? null),
            ])->all(),
            'days' => self::ALERT_DAYS,
            'menuOpen' => $stock !== null,
        ];
    }

    // What is on the live menu that the person has not ordered in the months their history
    // covers. Not for someone with no history: everything would be "new".
    public static function newDishes(User $user): ?array
    {
        $tried = self::history($user)->flatMap(fn (object $order) => $order->lines)->pluck('dish_id')->flip();
        $live = $tried->isNotEmpty() && $user->can('view menu') ? OrderSession::live() : null;

        if (! $live) {
            return null;
        }

        $new = SessionDish::stockForDashboard((int) $live->session_id)
            ->filter(fn (object $dish) => $dish->offered && $dish->available > 0 && ! $tried->has($dish->dish_id));

        if ($new->isEmpty()) {
            return null;
        }

        return [
            'dishes' => $new->take(5)->map(fn (object $dish) => ['dish_name' => $dish->dish_name, 'left' => $dish->available])->values()->all(),
            'more' => max(0, $new->count() - 5),
        ];
    }

    // The discounts that can be picked today, for the kinds of order the person can place.
    public static function discounts(User $user): ?array
    {
        $kinds = array_keys(array_filter([
            __('orders') => $user->can('view menu') && Setting::discountsFor('orders'),
            __('pre-orders') => $user->can('view pre-order-menu') && Setting::discountsFor('pre_orders'),
        ]));

        if ($kinds === []) {
            return null;
        }

        $today = now()->toDateString();
        $soon = now()->addDays(CheckWidgets::ENDING_DAYS)->toDateString();
        $day = fn (mixed $value) => $value === null ? null : substr((string) $value, 0, 10);

        // The same window as Discount::offered(): both ends included, none = no limit.
        $discounts = Discount::switchedOn()->filter(fn (object $discount) => ($day($discount->valid_from) ?? $today) <= $today
            && ($day($discount->valid_until) ?? $today) >= $today);

        if ($discounts->isEmpty()) {
            return null;
        }

        return [
            'discounts' => $discounts->map(fn (object $discount) => [
                'name' => $discount->discount_name,
                'percent' => (float) $discount->percent,
                'needsId' => (bool) $discount->requires_proof,
                'until' => $day($discount->valid_until) ? Carbon::parse($day($discount->valid_until))->translatedFormat('M j') : null,
                'endsSoon' => $day($discount->valid_until) !== null && $day($discount->valid_until) <= $soon,
            ])->values()->all(),
            'kinds' => implode(' ' . __('and') . ' ', $kinds),
        ];
    }

    // Dish names only, no counts and nobody's name: see OrderItem::popularSince().
    public static function popular(User $user): ?array
    {
        $since = now()->subDays(self::POPULAR_DAYS - 1)->toDateString();
        $popular = OrderItem::popularSince($since, 5, self::POPULAR_MIN_BUYERS);

        if ($popular->isEmpty()) {
            return null;
        }

        $stock = self::liveStock($user);

        return [
            'dishes' => $popular->map(fn (object $dish) => [
                'dish_name' => $dish->dish_name,
                'left' => $stock === null ? null : ($stock[(int) $dish->dish_id] ?? null),
            ])->all(),
            'menuOpen' => $stock !== null,
        ];
    }

    /* -------- Internals -------- */

    protected static function history(User $user): Collection
    {
        $since = now()->startOfMonth()->subMonthsNoOverflow(self::HISTORY_MONTHS - 1)->toDateString();

        // Seven widgets are made of these rows.
        return Memo::get('my.history.' . $user->id . '.' . $since, fn () => Order::dashboardHistoryOfUser((int) $user->id, $since));
    }

    protected static function pending(User $user): Collection
    {
        return self::history($user)->where('order_status', 'pending')->values();
    }

    // Plates left of each dish on the live menu (dish id => stock); null when no session is live
    // or the person may not open the menu. A dish missing from the map is not on it.
    protected static function liveStock(User $user): ?array
    {
        return Memo::get('my.live_stock.' . $user->id, fn () => self::loadLiveStock($user));
    }

    private static function loadLiveStock(User $user): ?array
    {
        $live = $user->can('view menu') ? OrderSession::live() : null;

        if (! $live) {
            return null;
        }

        return SessionDish::stockForDashboard((int) $live->session_id)
            ->where('offered', true)
            ->pluck('available', 'dish_id')
            ->all();
    }

    protected static function isCash(object $order): bool
    {
        return $order->method_name === PaymentMethod::CASH_ON_PICKUP;
    }

    // What to tell the customer about a pending order. rank 0 is the most urgent. `status` is
    // the dot's colour (data-status).
    protected static function stateOf(object $order): array
    {
        $cash = self::isCash($order);

        return match (true) {
            $order->payment_status === 'rejected' => ['rank' => 0, 'status' => 'rejected', 'label' => __('Payment rejected'), 'hint' => __('Open it to fix the payment.')],
            $order->ready_at !== null => ['rank' => 1, 'status' => 'ready', 'label' => __('Ready for pick up'), 'hint' => $cash ? __('Pay in cash at the counter.') : __('Show your order number.')],
            $order->discount_status === 'rejected' => ['rank' => 2, 'status' => 'rejected', 'label' => __('Discount rejected'), 'hint' => __('The order costs the full price.')],
            $order->discount_status === 'pending' => ['rank' => 3, 'status' => 'pending', 'label' => __('Checking your ID'), 'hint' => __('The price is not final yet.')],
            ! $cash && $order->payment_status === 'pending' => ['rank' => 4, 'status' => 'pending', 'label' => __('Checking your payment'), 'hint' => ''],
            default => ['rank' => 5, 'status' => 'pending', 'label' => __('Not ready yet'), 'hint' => $cash ? __('Pay when you pick it up.') : ''],
        };
    }

    // My Orders is paginated: never an #order-ID alone (the card may be on another page).
    protected static function orderUrl(int $orderId): string
    {
        return route('my-orders.index', ['order' => $orderId], false) . '#order-' . $orderId;
    }
}
