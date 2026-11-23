<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// A read cached together with the tables it was read from. Every table has a version; ONE listener
// (watch(), booted by AppServiceProvider) gives a table a new version whenever any statement writes
// it, from any process (web, console, queue), so a cached read is dropped by the very write that
// would have made it stale, with no controller having to remember to clear it. The version is part
// of the cache key, so a stale entry is simply never asked for again.
//
// Rules for what goes in: the result must depend ONLY on the rows of the tables you name (no now(),
// no auth user, no request), and you must name EVERY table it reads, including the ones a foreign key
// cascades into (a cascade is not a statement the listener sees). The TTL is only a backstop for rows
// edited by hand in the database or written by another server with its own cache.
class TableCache
{
    // Seconds a cached read lives when nothing writes its tables.
    private const TTL = 600;

    public static function remember(array $tables, string $key, Closure $load, int $ttl = self::TTL): mixed
    {
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

    // Called once at boot: every write statement touches the table it writes.
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

            // Now, and again at commit: a request that reads between the write and the commit must
            // not cache the old rows for good.
            self::touch($table);
            DB::afterCommit(fn () => self::touch($table));
        });
    }

    private static function versionKey(string $table): string
    {
        return 'table.version.' . $table;
    }
}
