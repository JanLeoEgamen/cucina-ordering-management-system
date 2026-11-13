<?php

namespace App\Jobs;

use App\Support\OrderNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

// One notification event, written by a queue worker so the request that caused it (a
// customer placing an order) never waits for it, and a burst of events is worked off
// at the worker's pace instead of piling onto the web server.
//
// Retry-safe: everything that can fail (reads, the ONE bulk insert) comes before
// anything is saved, and the insert is a single statement, so a retry can never write
// half of it or write it twice.
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // An order is worth little by the time this has waited minutes: give up quickly.
    public int $maxExceptions = 2;

    public int $timeout = 30;

    /**
     * @param  string  $kind  'order' (one order's event) or 'sold-out'
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $kind,
        public array $data,
        public ?int $actorId = null,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    /** @return int[] seconds to wait before attempt 2, 3 */
    public function backoff(): array
    {
        return [5, 30];
    }

    public function handle(): void
    {
        match ($this->kind) {
            'sold-out' => OrderNotifier::deliverSoldOut($this->data['session_dish_ids'], $this->data['session_id'], $this->actorId),
            default => OrderNotifier::deliver($this->data['order_id'], $this->data['event'], $this->data['context'] ?? [], $this->actorId),
        };
    }

    // All attempts used: the order is fine, only its notification is lost. Say so.
    public function failed(Throwable $exception): void
    {
        Log::error('Notification could not be delivered', [
            'kind' => $this->kind,
            'data' => $this->data,
            'error' => $exception->getMessage(),
        ]);
    }
}
