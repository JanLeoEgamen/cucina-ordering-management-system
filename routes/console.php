<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs the scheduler running (`php artisan schedule:work` locally, or the one-line
// cron `* * * * * php artisan schedule:run` on a server). Without it the table only
// grows; nothing breaks.
Schedule::command('notifications:prune')->dailyAt('03:00')->onOneServer();

// Failed jobs (a notification that gave up after 3 tries) are kept for a week for
// inspection (`php artisan queue:failed`), then dropped so the table does not grow.
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:10')->onOneServer();
