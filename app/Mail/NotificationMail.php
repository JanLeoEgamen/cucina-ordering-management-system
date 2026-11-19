<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// The email copy of one in-app notification: the same title and text the bell shows,
// plus a button to the same page. It is NOT queued by itself: App\Jobs\SendNotificationEmails
// (already running on the worker) sends it, so one event is one job, not one per person.
class NotificationMail extends Mailable
{
    public function __construct(
        public string $heading,
        public ?string $text,
        // An absolute address (an email is opened outside the app), or null.
        public ?string $link,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->heading);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.notification');
    }
}
