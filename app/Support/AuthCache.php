<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// The signed-in user (with their roles and direct permissions) kept in the cache, so a request
// that only needs to know WHO is asking and WHAT they may do runs no query for it. Against a
// database on another machine that is three round trips (user, permissions, roles) saved on every
// request, and the bell / stock polls become free.
//
// Never stale by design: instead of trusting every controller to remember to clear it, the ONE
// write listener (TableCache::watch) calls bump() whenever any statement writes one of TABLES,
// from any process (web, console, queue). The epoch is part of every cache key, so a bump orphans
// every cached user at once (a role edit touches many people). The TTL only bounds what the
// listener cannot see: a row edited by hand in the database, or another server's cache.
class AuthCache
{
    // Tables whose rows decide who a user is or what they may do.
    public const TABLES = ['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];

    private const EPOCH = 'auth.epoch';

    // Seconds a cached user may live; 0 turns the cache off (several app servers without a shared cache).
    public static function ttl(): int
    {
        return max(0, (int) config('auth.cache_ttl', 300));
    }

    public static function key(int|string $userId): string
    {
        // A write earlier in this process must be versioned before the epoch is read.
        TableCache::flush();

        return 'auth.user.' . Cache::get(self::EPOCH, '0') . '.' . $userId;
    }

    public static function bump(): void
    {
        Cache::forever(self::EPOCH, (string) Str::uuid());
    }
}
