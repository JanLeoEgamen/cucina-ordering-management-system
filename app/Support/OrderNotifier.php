<?php

namespace App\Support;

use App\Jobs\DeliverNotification;
use App\Jobs\SendNotificationEmails;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserNotification;
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

    // Events the customer does not need a notification about (they just did it,
    // or it is about the kitchen).
    protected const STAFF_ONLY = [self::PAYMENT_RESENT, self::SOLD_OUT];

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

    // Staff handed the order over.
    public static function completed(int $orderId): void
    {
        self::later($orderId, self::COMPLETED);
    }

    // Staff called the order off.
    public static function cancelled(int $orderId): void
    {
        self::later($orderId, self::CANCELLED);
    }

    // The customer fixed a rejected payment: a new proof, or cash on pickup.
    public static function paymentResent(int $orderId, bool $cash): void
    {
        self::later($orderId, self::PAYMENT_RESENT, ['cash' => $cash]);
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

            $mails[] = self::mailRow($person, $title, $body, $url);
        }

        UserNotification::insertMany($rows);
        self::email($mails);
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

                $mails[] = self::mailRow($person, $title, $body, $url);
            }
        }

        UserNotification::insertMany($rows);
        self::email($mails);
    }

    /* -------- Email -------- */

    // What the email job needs for one person: the same words as the bell.
    protected static function mailRow(User $person, string $title, ?string $body, ?string $url): array
    {
        return [
            'user_id' => (int) $person->id,
            'email' => (string) $person->email,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];
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
        $url = $person->can('view my orders')
            ? route('my-orders.index', [], false) . '#order-' . $order->order_id
            : null;

        $text = match ($event) {
            self::PLACED => [
                __('Order :number placed', ['number' => $number]),
                $cash
                    ? __('Total :total · Cash on Pick up. Pay at the counter when you collect it.', ['total' => self::money($order->total_amount)])
                    : __('Total :total · :method. We will check your payment and let you know.', ['total' => self::money($order->total_amount), 'method' => $method]),
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
                __('Your order was cancelled.'),
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

        $text = match ($event) {
            self::PLACED => [
                __(':who placed order :number', ['who' => $who, 'number' => $number]),
                __('Total :total · :method', ['total' => $total, 'method' => $method]),
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
                __(":who's order (:total) was cancelled.", ['who' => $who, 'total' => $total]),
            ],
            self::PAYMENT_RESENT => ($context['cash'] ?? false)
                ? [__(':who will pay :number in cash', ['who' => $who, 'number' => $number]), __('Payment changed to Cash on Pick up after a rejection. Complete the order when it is collected.')]
                : [__(':who sent a new proof for :number', ['who' => $who, 'number' => $number]), __('The payment (:total · :method) is waiting to be checked again.', ['total' => $total, 'method' => $method])],
            default => [Str::headline($event) . ': ' . $number, null],
        };

        return [$text[0], $text[1], $url];
    }
}
