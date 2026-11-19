<?php

namespace App\Jobs;

use App\Mail\NotificationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// The email copy of ONE notification event: one job for everybody told about it (not
// one job per person), pushed by OrderNotifier after the in-app rows were written.
//
// NOT retried (tries = 1): a retry would send again to everyone who already got it. Each
// address is tried on its own, so one bad address or one refused send costs only that
// email, and is logged; the in-app notification is already saved either way.
class SendNotificationEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // A slow mail server must not keep the worker for ever.
    public int $timeout = 120;

    /**
     * @param  array<int, array{user_id: int, email: string, title: string, body: ?string, url: ?string}>  $mails
     */
    public function __construct(public array $mails)
    {
        $this->onQueue(config('notifications.queue'));
    }

    public function handle(): void
    {
        foreach ($this->mails as $mail) {
            try {
                Mail::to($mail['email'])->send(new NotificationMail(
                    $mail['title'],
                    $mail['body'] ?? null,
                    // The stored link is relative to the site; an email needs the whole address.
                    ! empty($mail['url']) ? url($mail['url']) : null,
                ));
            } catch (Throwable $exception) {
                // The user id, not the address: logs are read by more people than mail is.
                Log::warning('Notification email could not be sent', [
                    'user_id' => $mail['user_id'] ?? null,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Notification emails could not be sent', ['error' => $exception->getMessage()]);
    }
}
