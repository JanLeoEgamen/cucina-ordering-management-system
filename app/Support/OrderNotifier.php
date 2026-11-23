<?php

namespace App\Support;

use App\Jobs\DeliverNotification;
use App\Jobs\SendGoogleChatMessages;
use App\Jobs\SendNotificationEmails;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Who is told what when something happens to an order. Call ONE method after the
// event has been saved (after the transaction, never inside it):
//
//   OrderNotifier::placed($orderId);
//
// Built to cost the request next to nothing and to survive a rush:
//  - the request only pushes a small job (one row in the queue); a queue worker does
//    the rest (App\Jobs\DeliverNotification, queue config('notifications.queue')),
//    so a burst of orders is worked off at the worker's pace, retried when it fails
//    (3 tries, backoff 5 s / 30 s) and reported when it gives up;
//  - pushing is wrapped in rescue(): a notification that cannot even be queued is
//    reported to the log and can never turn a placed order into an error;
//  - one event is a handful of queries (the order, the people, ONE bulk insert),
//    however many people are told. The bell finds them by polling (ui/notifications.js).
//
// Who is told:
//  - the CUSTOMER of the order, in their words ("Your payment was verified");
//  - every WATCHER (System Configuration -> Notifications) gets a copy of every
//    event of every customer, in staff words ("Jane's payment was verified"),
//    except the watcher who caused the event (they know);
//  - STAFF_ONLY events (a customer sent a new proof, a dish sold out) go to the
//    watchers only.
class OrderNotifier
{
    public const PLACED = 'order_placed';

    public const PAYMENT_VERIFIED = 'payment_verified';

    public const PAYMENT_REJECTED = 'payment_rejected';

    public const COMPLETED = 'order_completed';

    public const CANCELLED = 'order_cancelled';

    public const PAYMENT_RESENT = 'payment_resent';

    public const SOLD_OUT = 'dish_sold_out';

    public const READY = 'order_ready';

    public const DISCOUNT_APPROVED = 'discount_approved';

    public const DISCOUNT_REJECTED = 'discount_rejected';

    public const DISCOUNT_RESENT = 'discount_resent';

    public const SESSION_OPENED = 'session_opened';

    // Events the customer does not need a notification about (they just did it,
    // or it is about the kitchen).
    protected const STAFF_ONLY = [self::PAYMENT_RESENT, self::SOLD_OUT, self::DISCOUNT_RESENT];

    /* -------- The events -------- */

    // A customer placed an order.
    public static function placed(int $orderId): void
    {
        self::later($orderId, self::PLACED);
    }

    // Staff verified the payment.
    public static function paymentVerified(int $orderId): void
    {
        self::later($orderId, self::PAYMENT_VERIFIED);
    }

    // Staff rejected the payment, with the reason the customer is shown.
    public static function paymentRejected(int $orderId, ?string $reason): void
    {
        self::later($orderId, self::PAYMENT_REJECTED, ['reason' => $reason]);
    }

    // Staff approved the order's discount.
    public static function discountApproved(int $orderId): void
    {
        self::later($orderId, self::DISCOUNT_APPROVED);
    }

    // Staff rejected the order's discount, with the reason the customer is shown, the total
    // before and after, and whether a bank / e-wallet payment was sent back with it.
    public static function discountRejected(int $orderId, string $reason, float $oldTotal, float $newTotal, bool $paymentSentBack): void
    {
        self::later($orderId, self::DISCOUNT_REJECTED, [
            'reason' => $reason,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'payment_sent_back' => $paymentSentBack,
        ]);
    }

    // The customer sent a new ID for a rejected discount (staff only: they just did it themselves).
    public static function discountResent(int $orderId): void
    {
        self::later($orderId, self::DISCOUNT_RESENT);
    }

    // Staff handed the order over.
    public static function completed(int $orderId): void
    {
        self::later($orderId, self::COMPLETED);
    }

    // Staff called the order off, or (System Configuration allows it) the customer did.
    public static function cancelled(int $orderId, bool $byCustomer = false): void
    {
        self::later($orderId, self::CANCELLED, ['by_customer' => $byCustomer]);
    }

    // The customer fixed a rejected payment: a new proof, or cash on pickup.
    public static function paymentResent(int $orderId, bool $cash): void
    {
        self::later($orderId, self::PAYMENT_RESENT, ['cash' => $cash]);
    }

    // Staff announced that orders are ready for pick up: one order (the popup) or every
    // waiting order of a session. ONE job whatever the number: the worker writes every
    // customer's row with one insert, and the watchers get one line for the lot.
    public static function ready(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        rescue(fn () => Bus::dispatch(new DeliverNotification('ready', [
            'order_ids' => array_map('intval', $orderIds),
        ], auth()->id())));
    }

    // Staff published an order session: everyone who can see the menu is told (the job
    // writes all the rows with one insert, however many customers there are).
    public static function sessionOpened(int $sessionId): void
    {
        rescue(fn () => Bus::dispatch(new DeliverNotification('session-opened', [
            'session_id' => $sessionId,
        ], auth()->id())));
    }

    // An order just took the last plates of these session dishes (watchers only).
    public static function soldOut(array $sessionDishIds, int $sessionId): void
    {
        if ($sessionDishIds === []) {
            return;
        }

        // Most orders sell nothing out: one indexed read here is far cheaper than a
        // queued job (and a worker run) for every order. The job checks again.
        $soldOut = DB::table('session_dishes')
            ->whereIn('session_dish_id', $sessionDishIds)
            ->where('available_stock', 0)
            ->whereNull('deleted_at')
            ->pluck('session_dish_id')
            ->all();

        if ($soldOut === []) {
            return;
        }

        // Bus::dispatch, not Job::dispatch(): that one only fires when its PendingDispatch is
        // destroyed, which is after rescue() has already returned.
        rescue(fn () => Bus::dispatch(new DeliverNotification('sold-out', [
            'session_dish_ids' => array_map('intval', $soldOut),
            'session_id' => $sessionId,
        ], auth()->id())));
    }

    /* -------- Delivery -------- */

    // Remember who caused the event now (the request is still the request) and hand
    // the rest to the queue.
    protected static function later(int $orderId, string $event, array $context = []): void
    {
        rescue(fn () => Bus::dispatch(new DeliverNotification('order', [
            'order_id' => $orderId,
            'event' => $event,
            'context' => $context,
        ], auth()->id())));
    }

    // Run by the queue worker (App\Jobs\DeliverNotification).
    public static function deliver(int $orderId, string $event, array $context, ?int $actorId): void
    {
        $order = Order::notificationSummary($orderId);

        if (! $order) {
            return;
        }

        $watchers = Setting::notificationWatchers();
        $customerId = (int) $order->user_id;
        $staffOnly = in_array($event, self::STAFF_ONLY, true);

        // The customer and the watchers in ONE query (soft deleted users drop out).
        $people = User::query()->whereIn('id', array_values(array_unique(array_merge([$customerId], $watchers))))->get();

        $rows = [];
        $mails = [];

        foreach ($people as $person) {
            $isWatcher = in_array((int) $person->id, $watchers, true);
            // The customer reads it in their own words, unless it is a staff-only
            // event and they happen to be a watcher too.
            $asCustomer = (int) $person->id === $customerId && ! ($staffOnly && $isWatcher);

            if ($asCustomer && $staffOnly) {
                continue;
            }

            if (! $asCustomer && (! $isWatcher || (int) $person->id === (int) $actorId)) {
                continue;
            }

            [$title, $body, $url] = $asCustomer
                ? self::customerText($event, $order, $context, $person)
                : self::staffText($event, $order, $context, $person);

            $rows[] = [
                'user_id' => $person->id,
                'type' => $event,
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'order_id' => $order->order_id,
            ];

            $mails[] = self::mailRow($person, $title, $body, $url, (int) $order->order_id, $event);
        }

        UserNotification::insertMany($rows);
        self::copies($mails);
    }

    // Run by the queue worker for ready(): each customer is told about their own order(s);
    // each watcher (except whoever pressed the button) gets one line for the announcement.
    public static function deliverReady(array $orderIds, ?int $actorId): void
    {
        $orders = Order::notificationSummaries($orderIds);

        if ($orders->isEmpty()) {
            return;
        }

        $watchers = Setting::notificationWatchers();

        // Every customer and watcher in ONE query (soft deleted users drop out).
        $people = User::query()
            ->whereIn('id', array_values(array_unique(array_merge($orders->pluck('user_id')->map(fn ($id) => (int) $id)->all(), $watchers))))
            ->get()
            ->keyBy('id');

        $rows = [];
        $mails = [];

        foreach ($orders as $order) {
            $person = $people->get((int) $order->user_id);

            if (! $person) {
                continue;
            }

            [$title, $body, $url] = self::customerText(self::READY, $order, [], $person);

            $rows[] = ['user_id' => $person->id, 'type' => self::READY, 'title' => $title, 'body' => $body, 'url' => $url, 'order_id' => $order->order_id];
            $mails[] = self::mailRow($person, $title, $body, $url, (int) $order->order_id, self::READY);
        }

        // The customer of a single order was just told in their own words: no second copy as a watcher.
        $single = $orders->count() === 1 ? $orders->first() : null;

        foreach ($watchers as $watcherId) {
            $person = $people->get((int) $watcherId);

            if (! $person || (int) $person->id === (int) $actorId || (int) $person->id === (int) $single?->user_id) {
                continue;
            }

            [$title, $body, $url] = $single
                ? self::staffText(self::READY, $single, [], $person)
                : [
                    __(':count orders are ready for pick up', ['count' => $orders->count()]),
                    __('Their customers were told to come and collect them.'),
                    $person->can('view orders') ? route('orders.index', [], false) : null,
                ];

            $rows[] = ['user_id' => $person->id, 'type' => self::READY, 'title' => $title, 'body' => $body, 'url' => $url, 'order_id' => $single?->order_id];
            $mails[] = self::mailRow($person, $title, $body, $url, $single ? (int) $single->order_id : null, self::READY);
        }

        UserNotification::insertMany($rows);
        self::copies($mails);
    }

    // Run by the queue worker for sessionOpened(): everyone with "view menu" (the Customer role
    // and staff who may order) except whoever published it.
    public static function deliverSessionOpened(int $sessionId, ?int $actorId): void
    {
        $session = DB::table('order_sessions')->where('session_id', $sessionId)->whereNull('deleted_at')->first();

        if (! $session) {
            return;
        }

        $when = Carbon::parse($session->order_date)->format('D, M j') . ' · '
            . Carbon::parse($session->start_time)->format('g:i A') . ' - ' . Carbon::parse($session->end_time)->format('g:i A');
        $title = __('A new order session is open');
        $body = __('Order from the menu on :when.', ['when' => $when]);

        // What can be ordered: dishes with plates left, first few by name (the text is stored
        // in 500 characters, and the same words go to email and Google Chat).
        $dishes = DB::table('session_dishes')
            ->join('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
            ->where('session_dishes.session_id', $sessionId)
            ->where('session_dishes.available_stock', '>', 0)
            ->whereNull('session_dishes.deleted_at')
            ->whereNull('dishes.deleted_at')
            ->orderBy('dishes.dish_name')
            ->get(['dishes.dish_name', 'dishes.price']);

        // The Google Chat message shows the dishes as a list (one per line) under the short sentence.
        $chatBody = $body;
        $points = $dishes->take(12)->map(fn (object $dish) => '• ' . $dish->dish_name . ' — ' . self::money($dish->price))->all();

        if ($dishes->count() > 12) {
            $points[] = '+' . ($dishes->count() - 12) . ' ' . __('more');
        }

        if ($dishes->isNotEmpty()) {
            $shown = $dishes->take(6)->map(fn (object $dish) => $dish->dish_name . ' ' . self::money($dish->price))->implode(', ');
            $more = $dishes->count() > 6 ? ' ' . __('and :count more', ['count' => $dishes->count() - 6]) : '';
            $body .= ' ' . __('Available: :dishes', ['dishes' => $shown]) . $more . '.';
        }

        $rows = [];
        $mails = [];

        foreach (User::permission('view menu')->where('id', '!=', (int) $actorId)->get() as $person) {
            $url = route('menu.index', [], false);

            $rows[] = ['user_id' => $person->id, 'type' => self::SESSION_OPENED, 'title' => $title, 'body' => $body, 'url' => $url, 'order_id' => null];
            $mails[] = self::mailRow($person, $title, $body, $url, null, self::SESSION_OPENED)
                + ['chat_body' => $chatBody, 'points' => $points, 'points_title' => $points === [] ? null : __('Available now'), 'button' => __('Open menu')];
        }

        UserNotification::insertMany($rows);
        self::copies($mails);
    }

    public static function deliverSoldOut(array $sessionDishIds, int $sessionId, ?int $actorId): void
    {
        $watchers = Setting::notificationWatchers();

        if ($watchers === []) {
            return;
        }

        // Only the dishes that really are at zero now; and one announcement per dish
        // for a couple of minutes (two orders can finish together and both see zero).
        $names = DB::table('session_dishes')
            ->join('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
            ->whereIn('session_dishes.session_dish_id', $sessionDishIds)
            ->where('session_dishes.available_stock', 0)
            ->whereNull('session_dishes.deleted_at')
            ->get(['session_dishes.session_dish_id', 'dishes.dish_name'])
            ->filter(fn (object $dish) => Cache::add('sold-out-notified:' . $dish->session_dish_id, 1, 120));

        if ($names->isEmpty()) {
            return;
        }

        try {
            self::deliverSoldOutRows($names, $watchers, $sessionId);
        } catch (\Throwable $e) {
            // The retry must be allowed to announce it again (a failed insert wrote nothing).
            foreach ($names as $dish) {
                Cache::forget('sold-out-notified:' . $dish->session_dish_id);
            }

            throw $e;
        }
    }

    protected static function deliverSoldOutRows($names, array $watchers, int $sessionId): void
    {

        $rows = [];
        $mails = [];

        foreach (User::query()->whereIn('id', $watchers)->get() as $person) {
            $url = $person->can('view order-sessions') ? route('order-sessions.show', $sessionId, false) : null;

            foreach ($names as $dish) {
                $title = __(':dish is sold out', ['dish' => $dish->dish_name]);
                $body = __('The last plates were just ordered.');

                $rows[] = [
                    'user_id' => $person->id,
                    'type' => self::SOLD_OUT,
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'order_id' => null,
                ];

                $mails[] = self::mailRow($person, $title, $body, $url, null, self::SOLD_OUT);
            }
        }

        UserNotification::insertMany($rows);
        self::copies($mails);
    }

    /* -------- Email -------- */

    // What the email job needs for one person: the same words as the bell.
    protected static function mailRow(User $person, string $title, ?string $body, ?string $url, ?int $orderId = null, ?string $type = null): array
    {
        return [
            'user_id' => (int) $person->id,
            'email' => (string) $person->email,
            'title' => $title,
            'body' => $body,
            'url' => $url,
            // Not for the email: the Google Chat card shows the dishes of this order and picks its icon from the type.
            'order_id' => $orderId,
            'type' => $type,
        ];
    }

    // The copies of what the bell shows, on the other channels: email, then Google Chat. Each is
    // its own job inside its own rescue(), so one channel failing cannot stop the other.
    protected static function copies(array $mails): void
    {
        self::email($mails);
        self::chat($mails);
    }

    // The Google Chat copy (System Configuration -> Notifications, off by default): ONE job for
    // everybody told, like email. Same rules as email(): AFTER the in-app rows were saved and
    // inside rescue(), because a throw here would make DeliverNotification retry and write the
    // bell rows twice.
    protected static function chat(array $mails): void
    {
        if ($mails === []) {
            return;
        }

        rescue(function () use ($mails) {
            if (Setting::googleChatNotifications() && GoogleChat::configured()) {
                Bus::dispatch(new SendGoogleChatMessages(array_map(fn (array $mail) => [
                    'user_id' => $mail['user_id'],
                    'title' => $mail['title'],
                    'body' => $mail['body'],
                    'url' => $mail['url'],
                    'order_id' => $mail['order_id'] ?? null,
                    'type' => $mail['type'] ?? null,
                    'chat_body' => $mail['chat_body'] ?? null,
                    'points' => $mail['points'] ?? [],
                    'points_title' => $mail['points_title'] ?? null,
                    'button' => $mail['button'] ?? null,
                ], $mails)));
            }
        });
    }

    // The email copy (System Configuration -> Notifications, off by default). It runs
    // AFTER the in-app rows were saved, and inside rescue(): whatever goes wrong here
    // (the setting cannot be read, the job cannot be pushed) must not fail the
    // notification job, because its retry would write the bell rows a second time.
    protected static function email(array $mails): void
    {
        $mails = array_values(array_filter($mails, fn (array $mail) => $mail['email'] !== ''));

        if ($mails === []) {
            return;
        }

        rescue(function () use ($mails) {
            if (Setting::emailNotifications()) {
                Bus::dispatch(new SendNotificationEmails($mails));
            }
        });
    }

    /* -------- The words -------- */

    protected static function money($amount): string
    {
        return '₱' . number_format((float) $amount, 2);
    }

    // What the customer reads, and where it opens (their own orders page).
    protected static function customerText(string $event, object $order, array $context, User $person): array
    {
        $number = $order->order_number;
        $method = $order->method_name ?: __('your payment method');
        $cash = $order->method_name === PaymentMethod::CASH_ON_PICKUP;
        // "?order=ID": My Orders is paginated, and that finds the page the order is on (the
        // #anchor then scrolls to its card).
        $url = $person->can('view my orders')
            ? route('my-orders.index', ['order' => $order->order_id], false) . '#order-' . $order->order_id
            : null;

        $discount = (string) ($order->discount_name ?? '');

        $text = match ($event) {
            self::PLACED => [
                __('Order :number placed', ['number' => $number]),
                trim(($cash
                    ? __('Total :total · Cash on Pick up. Pay at the counter when you collect it.', ['total' => self::money($order->total_amount)])
                    : __('Total :total · :method. We will check your payment and let you know.', ['total' => self::money($order->total_amount), 'method' => $method]))
                    // The price is final only once staff looked at the ID.
                    . (($order->discount_status ?? 'none') === 'pending' ? ' ' . __('Staff will check your :discount discount first.', ['discount' => $discount]) : '')),
            ],
            self::DISCOUNT_APPROVED => [
                __('Discount approved'),
                __('Your :discount discount on order :number was approved. Total :total.', ['discount' => $discount, 'number' => $number, 'total' => self::money($order->total_amount)]),
            ],
            self::DISCOUNT_REJECTED => [
                __('Discount not approved'),
                trim(__('Your :discount discount on order :number was not approved.', ['discount' => $discount, 'number' => $number])
                    . ($context['reason'] ?? null ? ' ' . $context['reason'] . '.' : '')
                    . ' ' . __('The total is now :new (it was :old).', ['new' => self::money($context['new_total'] ?? $order->total_amount), 'old' => self::money($context['old_total'] ?? $order->total_amount)])
                    . ' ' . (($context['payment_sent_back'] ?? false)
                        ? __('Open My Orders to send a new proof for the full amount or pay with cash on pickup.')
                        : ($cash ? __('Pay the full amount in cash when you collect it.') : ''))),
            ],
            self::PAYMENT_VERIFIED => [
                __('Payment verified'),
                __('Your payment for order :number was verified.', ['number' => $number]),
            ],
            self::PAYMENT_REJECTED => [
                __('Payment rejected'),
                trim(__('Your payment for order :number was rejected.', ['number' => $number])
                    . ($context['reason'] ?? null ? ' ' . $context['reason'] : '')
                    . ' ' . __('Open My Orders to send a new proof or pay with cash on pickup.')),
            ],
            self::COMPLETED => [
                __('Order :number completed', ['number' => $number]),
                __('Your order was handed over. Thank you!'),
            ],
            self::CANCELLED => [
                __('Order :number cancelled', ['number' => $number]),
                ($context['by_customer'] ?? false)
                    ? __('You cancelled your order. Its plates went back on the menu.')
                    : __('Your order was cancelled.'),
            ],
            self::READY => [
                __('Order :number is ready for pick up', ['number' => $number]),
                $cash
                    ? __('Come to the counter and pay :total in cash when you collect it.', ['total' => self::money($order->total_amount)])
                    : __('Show your order number at the counter to collect it.'),
            ],
            default => [__('Order :number updated', ['number' => $number]), null],
        };

        return [$text[0], $text[1], $url];
    }

    // What a watcher reads: whose order it is, and where staff look at it.
    protected static function staffText(string $event, object $order, array $context, User $person): array
    {
        $number = $order->order_number;
        $who = $order->customer_name;
        $method = $order->method_name ?: '—';
        $total = self::money($order->total_amount);

        $paymentEvent = in_array($event, [self::PAYMENT_VERIFIED, self::PAYMENT_REJECTED, self::PAYMENT_RESENT], true);

        // Payment events open the Payments page, the rest the Orders page, when the
        // watcher may see them; otherwise the notification simply has no link.
        $url = match (true) {
            $paymentEvent && $person->can('view payments') => route('payments.index', [], false),
            $person->can('view orders') => route('orders.index', [], false),
            default => null,
        };

        $discount = (string) ($order->discount_name ?? '');

        $text = match ($event) {
            self::PLACED => [
                __(':who placed order :number', ['who' => $who, 'number' => $number]),
                __('Total :total · :method', ['total' => $total, 'method' => $method])
                    . (($order->discount_status ?? 'none') === 'pending' ? ' · ' . __(':discount discount to check', ['discount' => $discount]) : ''),
            ],
            self::DISCOUNT_APPROVED => [
                __('Discount approved: :number', ['number' => $number]),
                __(":who's :discount discount was approved. Total :total.", ['who' => $who, 'discount' => $discount, 'total' => $total]),
            ],
            self::DISCOUNT_RESENT => [
                __(':who sent a new ID for :number', ['who' => $who, 'number' => $number]),
                __('The :discount discount (total :total) is waiting to be checked again.', ['discount' => $discount, 'total' => $total]),
            ],
            self::DISCOUNT_REJECTED => [
                __('Discount rejected: :number', ['number' => $number]),
                trim(__(":who's :discount discount was rejected. Total :total.", ['who' => $who, 'discount' => $discount, 'total' => $total])
                    . ($context['reason'] ?? null ? ' ' . $context['reason'] : '')),
            ],
            self::PAYMENT_VERIFIED => [
                __('Payment verified: :number', ['number' => $number]),
                __(":who's payment (:total · :method) was verified.", ['who' => $who, 'total' => $total, 'method' => $method]),
            ],
            self::PAYMENT_REJECTED => [
                __('Payment rejected: :number', ['number' => $number]),
                trim(__(":who's payment (:total · :method) was rejected.", ['who' => $who, 'total' => $total, 'method' => $method])
                    . ($context['reason'] ?? null ? ' ' . $context['reason'] : '')),
            ],
            self::COMPLETED => [
                __('Order :number completed', ['number' => $number]),
                __(":who's order (:total) was handed over.", ['who' => $who, 'total' => $total]),
            ],
            self::CANCELLED => [
                __('Order :number cancelled', ['number' => $number]),
                ($context['by_customer'] ?? false)
                    ? __(':who cancelled their order (:total).', ['who' => $who, 'total' => $total])
                    : __(":who's order (:total) was cancelled.", ['who' => $who, 'total' => $total]),
            ],
            self::READY => [
                __('Order :number is ready for pick up', ['number' => $number]),
                __(":who's order (:total) was announced as ready.", ['who' => $who, 'total' => $total]),
            ],
            self::PAYMENT_RESENT => ($context['cash'] ?? false)
                ? [__(':who will pay :number in cash', ['who' => $who, 'number' => $number]), __('Payment changed to Cash on Pick up after a rejection. Complete the order when it is collected.')]
                : [__(':who sent a new proof for :number', ['who' => $who, 'number' => $number]), __('The payment (:total · :method) is waiting to be checked again.', ['total' => $total, 'method' => $method])],
            default => [Str::headline($event) . ': ' . $number, null],
        };

        return [$text[0], $text[1], $url];
    }
}
