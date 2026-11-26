<?php

namespace App\Support;

use Closure;

// A result kept for the length of ONE read-only piece of work, so widgets that need the same rows
// ask for them once: a dashboard has seven widgets about the person's own orders, a Sales tab five
// about the same daily totals. Even a cached read costs a few file reads and unserialising its rows,
// and an uncached one (a typed date range) is the query again.
//
// It remembers NOTHING outside during(), and everything is forgotten when during() returns, so a
// write can never be followed by a stale read. Use during() only around code that does not write.
// What get() returns is shared: do not change it.
class Memo
{
    private static ?array $values = null;

    public static function during(Closure $work): mixed
    {
        // Already inside one: its values go on being shared.
        if (self::$values !== null) {
            return $work();
        }

        self::$values = [];

        try {
            return $work();
        } finally {
            self::$values = null;
        }
    }

    public static function get(string $key, Closure $load): mixed
    {
        if (self::$values === null) {
            return $load();
        }

        // array_key_exists: a null result is remembered too.
        if (! array_key_exists($key, self::$values)) {
            self::$values[$key] = $load();
        }

        return self::$values[$key];
    }
}
