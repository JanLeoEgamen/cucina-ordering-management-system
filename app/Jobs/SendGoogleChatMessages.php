<?php

namespace App\Jobs;

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
class SendGoogleChatMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // One call to find the space and one to post, per person, each with a short timeout.
    public int $timeout = 120;

    /**
     * @param  array<int, array{user_id: int, title: string, body: ?string, url: ?string}>  $messages
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

        foreach ($this->messages as $message) {
            $googleId = $googleIds[$message['user_id']] ?? null;

            if (! $googleId) {
                continue;
            }

            GoogleChat::message((string) $googleId, $message['title'], $message['body'] ?? null, $message['url'] ?? null);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Google Chat messages could not be sent', ['error' => $exception->getMessage()]);
    }
}
