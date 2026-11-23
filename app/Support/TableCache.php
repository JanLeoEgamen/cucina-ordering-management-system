<?php

namespace App\Support;

use Closure;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

// A read cached together with the tables it was read from. Every table has a version; ONE listener
// (watch(), booted by AppServiceProvider) notes every table any statement writes, from any process
// (web, console, queue), and the next cached read, the end of the transaction, the end of the request
// and the end of a queue job give those tables a new version. The version is part of the cache key,
// so a stale entry is simply never asked for again, and no controller has to remember to clear it.
//
// The listener itself does NO cache work: it runs inside the "statement executed" event, and with the
// `database` cache driver a cache write there would run in the middle of the caller's statement (on
// SQLite it overwrites the id insertGetId is about to read) or loop on the cache table itself.
//
// Rules for what goes in: the result must depend ONLY on the rows of the tables you name (no now(),
// no auth user, no request), and you must name EVERY table it reads, including the ones a foreign key
// cascades into (a cascade is not a statement the listener sees). The TTL is only a backstop for rows
// edited by hand in the database or written by another server with its own cache.
class TableCache
{
    // Seconds a cached read lives when nothing writes its tables.
    private const TTL = 600;

    // Tables nothing caches a read of: framework plumbing and logs that change all the time.
    private const IGNORED = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'audit_logs', 'user_notifications'];

    // Written but not versioned yet.
    private static array $dirty = [];

    // Versioned already, versioned once more when the transaction / request / job ends: a reader that
    // looked between the write and its commit may have cached the old rows under the first version.
    private static array $recent = [];

    public static function remember(array $tables, string $key, Closure $load, int $ttl = self::TTL): mixed
    {
        self::flush();

        $versions = implode('.', array_map(fn (string $table) => Cache::get(self::versionKey($table), '0'), $tables));

        return Cache::remember('table.' . $key . '.' . md5($versions), $ttl, $load);
    }

    // A table's rows changed: every cached read that names it is dropped.
    public static function touch(string $table): void
    {
        Cache::forever(self::versionKey($table), (string) Str::uuid());

        if (in_array($table, AuthCache::TABLES, true)) {
            AuthCache::bump();
        }
    }

    // Version the tables written since the last time. Called before every cached read, so a read
    // after a write in the same process can never be served from before it.
    public static function flush(): void
    {
        $tables = array_keys(self::$dirty);
        self::$dirty = [];

        foreach ($tables as $table) {
            // The cache must never break the code that wrote the row (the cache table may not exist
            // yet while migrate:fresh is still building the schema).
            rescue(fn () => self::touch($table), report: false);
            self::$recent[$table] = true;
        }
    }

    // The write is final (commit, end of request or job): version everything once more.
    public static function settle(): void
    {
        self::flush();

        $tables = array_keys(self::$recent);
        self::$recent = [];

        foreach ($tables as $table) {
            rescue(fn () => self::touch($table), report: false);
        }
    }

    // Called once at boot.
    public static function watch(): void
    {
        DB::listen(function ($query) {
            // A SELECT is by far the most common statement: leave before the regex.
            if (stripos($query->sql, 'select') === 0) {
                return;
            }

            if (preg_match('/^\s*(?:insert\s+(?:or\s+\w+\s+|ignore\s+)?into|update(?:\s+(?:or\s+\w+|ignore))?|delete\s+from|replace\s+into|truncate(?:\s+table)?)\s+[`"\[]?(\w+)/i', $query->sql, $match) !== 1) {
                return;
            }

            $table = strtolower($match[1]);

            if (! in_array($table, self::IGNORED, true)) {
                self::$dirty[$table] = true;
            }
        });

        Event::listen(TransactionCommitted::class, fn () => self::settle());
        Event::listen([JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], fn () => self::settle());
        app()->terminating(fn () => self::settle());

        // A migration changes the rows (migrate:fresh empties every table) without any statement the
        // listener understands, and a file cache survives it: start from nothing after every run.
        Event::listen(MigrationsEnded::class, function () {
            self::$dirty = self::$recent = [];
            rescue(fn () => Cache::flush(), report: false);
        });
    }

    private static function versionKey(string $table): string
    {
        return 'table.version.' . $table;
    }
}
