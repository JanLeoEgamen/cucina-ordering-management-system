<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Support\GoogleChat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// The Google Chat copy of ONE notification event: one job for everybody told about it,
// pushed by OrderNotifier after the in-app rows were written (same shape as
// SendNotificationEmails).
//
// NOT retried (tries = 1): a retry would message again everyone who already got it. Each
// person is tried on its own; people without a Google account on file, or who have not added
// the Chat app, are skipped (GoogleChat::message() answers false), never an error.
//
// What the card shows beyond the bell's words (the dishes, the total, the payment method) is
// read here, in two queries for the whole event, from the order ids the notifier passed.
class SendGoogleChatMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // One call to find the space and one to post, per person, each with a short timeout.
    public int $timeout = 120;

    /**
     * @param  array<int, array{user_id: int, title: string, body: ?string, url: ?string, order_id?: ?int, type?: ?string}>  $messages
     */
    public function __construct(public array $messages)
    {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(): void
    {
        // Their Google ids in ONE query; an account that never signed in with Google has none.
        $googleIds = DB::table('users')
            ->whereIn('id', array_column($this->messages, 'user_id'))
            ->whereNull('deleted_at')
            ->whereNotNull('google_id')
            ->pluck('google_id', 'id');

        $orders = $this->orders();
        $app = $this->appName();

        foreach ($this->messages as $message) {
            $googleId = $googleIds[$message['user_id']] ?? null;

            if (! $googleId) {
                continue;
            }

            GoogleChat::message((string) $googleId, [
                'title' => $message['title'],
                'body' => $message['body'] ?? null,
                'url' => $message['url'] ?? null,
                'type' => $message['type'] ?? null,
                'app' => $app,
                'order' => $orders[$message['order_id'] ?? 0] ?? null,
            ]);
        }
    }

    // The orders the messages are about, ready for the card: number, total, payment method and
    // the dishes. Two queries for all of them. If they cannot be read the messages still go out,
    // without the details.
    protected function orders(): array
    {
        $ids = array_values(array_unique(array_filter(array_column($this->messages, 'order_id'))));

        if ($ids === []) {
            return [];
        }

        try {
            $items = OrderItem::itemsByOrder($ids);

            return Order::notificationSummaries($ids)->map(fn (object $order) => [
                'number' => $order->order_number,
                'total' => (float) $order->total_amount,
                'method' => $order->method_name,
                'items' => $items[$order->order_id] ?? [],
            ])->all();
        } catch (Throwable $exception) {
            Log::warning('Google Chat: the order details could not be read', ['error' => $exception->getMessage()]);

            return [];
        }
    }

    protected function appName(): string
    {
        try {
            return Setting::appName();
        } catch (Throwable) {
            return '';
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Google Chat messages could not be sent', ['error' => $exception->getMessage()]);
    }
}
