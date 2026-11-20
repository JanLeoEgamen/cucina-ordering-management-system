<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\GoogleChat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// "Google Chat does not work": run this on the server. It walks the path for one person (key
// file, token, direct message space, a real test message) and names the step that fails,
// with Google's own reason. Sends ONE message, to that person only (--no-send skips it).
class GoogleChatTest extends Command
{
    protected $signature = 'google-chat:test {email : The account to message} {--no-send : Check the path but do not send the test message}';

    protected $description = 'Check the Google Chat setup for one user and send them a test message';

    public function handle(): int
    {
        $user = DB::table('users')->where('email', $this->argument('email'))->whereNull('deleted_at')->first(['id', 'google_id']);

        $this->line('Switch in System Configuration: ' . (Setting::googleChatNotifications() ? 'ON' : 'OFF (turn it on, or nothing is sent)'));
        $this->line('Markup: ' . config('services.google_chat.markup') . '   Queue: ' . config('queue.default') . ' (a worker must be running: queue:work --queue=notifications,default)');

        if (! $user) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        if (! $user->google_id) {
            $this->error('This account has no google_id: they have not signed in with "Continue with Google" yet, so they cannot be messaged.');

            return self::FAILURE;
        }

        $this->line('google_id: present (' . strlen($user->google_id) . ' digits)');

        $failed = false;

        foreach (GoogleChat::diagnose((string) $user->google_id, ! $this->option('no-send')) as [$label, $ok, $detail]) {
            $ok ? $this->info('[ok]   ' . $label . ': ' . $detail) : $this->error('[FAIL] ' . $label . ': ' . $detail);
            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
