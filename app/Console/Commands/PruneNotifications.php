<?php

namespace App\Console\Commands;

use App\Models\UserNotification;
use Illuminate\Console\Command;

// Keeps the notifications table small, which is what keeps the unread count and the
// poll fast: read notifications go after 30 days, anything after 90 (even unread:
// nobody is going to read a notification that old).
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--read-days=30 : Delete read notifications older than this} {--days=90 : Delete any notification older than this}';

    protected $description = 'Delete old notifications (read ones after 30 days, any after 90)';

    public function handle(): int
    {
        $deleted = UserNotification::prune(max(1, (int) $this->option('read-days')), max(1, (int) $this->option('days')));

        $this->info($deleted . ' notification(s) deleted.');

        return self::SUCCESS;
    }
}
