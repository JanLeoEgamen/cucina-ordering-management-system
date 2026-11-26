<?php

namespace App\Support\Dashboard;

use App\Models\AuditLog;
use App\Models\Discount;
use App\Models\Dish;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The dashboard widgets that catch trouble before a customer does: the queue that delivers
// notifications, and things set up only half way.
class CheckWidgets
{
    // A job still waiting this long after it was due suggests no worker is running.
    public const STUCK_MINUTES = 5;

    // A discount ending within this many days is worth a look.
    public const ENDING_DAYS = 7;

    // How long the two widgets that read tables TableCache does not watch (jobs, audit_logs)
    // keep their answer, in seconds. Without it every dashboard load of an admin ran their 3
    // queries, however warm the rest of the page was.
    public const FRESH_FOR_SECONDS = 60;

    // Counts only: a failed job's error text can hold addresses, so it is never read here. An
    // empty queue does NOT prove a worker is running, and the view says so.
    public static function health(User $user): array
    {
        $queue = config('queue.connections.' . config('queue.default'));
        $driver = $queue['driver'] ?? null;

        if ($driver !== 'database') {
            return ['mode' => $driver === 'sync' ? 'sync' : 'untracked'];
        }

        // These tables change without TableCache hearing of it, so the answer is kept for a
        // short time instead. A missing table must not take the dashboard down.
        return Cache::remember('dashboard.health', self::FRESH_FOR_SECONDS, fn () => rescue(function () use ($queue) {
            $due = now()->subMinutes(self::STUCK_MINUTES)->getTimestamp();

            $waiting = DB::connection($queue['connection'] ?? null)->table($queue['table'] ?? 'jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $due)
                ->selectRaw('count(*) as stuck, min(available_at) as oldest')
                ->first();

            return [
                'mode' => 'database',
                'failed' => DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->count(),
                'stuck' => (int) $waiting->stuck,
                'oldestMinutes' => $waiting->oldest ? intdiv(now()->getTimestamp() - (int) $waiting->oldest, 60) : null,
            ];
        }, ['mode' => 'unknown']));
    }

    // Failed sign ins and lockouts of the last 24 hours. Counts only.
    public static function security(User $user): array
    {
        if (! config('audit.enabled')) {
            return ['enabled' => false];
        }

        $since = now()->subDay();
        $found = Cache::remember('dashboard.security', self::FRESH_FOR_SECONDS, function () use ($since) {
            $row = AuditLog::signInTroubleSince($since->toDateTimeString());

            return ['failed' => (int) $row->failed, 'lockouts' => (int) $row->lockouts, 'addresses' => (int) $row->addresses];
        });

        return ['enabled' => true] + $found + [
            'url' => route('audit-logs.index', ['event' => 'auth', 'from' => $since->toDateString()]),
        ];
    }

    // Each check is behind the permission of the page where it is fixed.
    public static function setup(User $user): array
    {
        $lines = [];
        $add = function (int $count, string $label, string $route) use (&$lines) {
            if ($count > 0) {
                $lines[] = ['count' => $count, 'label' => trans_choice($label, $count), 'url' => route($route)];
            }
        };

        if ($user->can('view dishes')) {
            $dishes = Dish::getAll()->filter(fn (object $dish) => (bool) $dish->is_active);

            $add($dishes->filter(fn (object $dish) => (float) $dish->price <= 0)->count(), 'dish priced at zero|dishes priced at zero', 'dishes.index');
            $add($dishes->filter(fn (object $dish) => blank($dish->image))->count(), 'dish without a picture|dishes without a picture', 'dishes.index');
        }

        if ($user->can('view users')) {
            $gaps = User::setupGaps();

            $add((int) $gaps->without_role, 'account with no role (it cannot open anything)|accounts with no role (they cannot open anything)', 'users.index');
            $add((int) $gaps->without_company, 'account with no company|accounts with no company', 'users.index');
        }

        if ($user->can('view payment-methods')) {
            $add(PaymentMethod::countWithoutDetails(), 'payment method with no account number or QR|payment methods with no account number or QR', 'payment-methods.index');
        }

        if ($user->can('view discounts')) {
            $today = now()->toDateString();
            $until = now()->addDays(self::ENDING_DAYS)->toDateString();

            $ending = Discount::withEndDate()->filter(function (object $discount) use ($today, $until) {
                $end = substr((string) $discount->valid_until, 0, 10);

                return $end >= $today && $end <= $until;
            })->count();

            $add($ending, 'discount ends within a week|discounts end within a week', 'discounts.index');
        }

        return ['lines' => $lines];
    }
}
