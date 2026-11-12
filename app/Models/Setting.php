<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

// System configuration: one row per key in `settings`. Like the other models it is a set
// of static query builder methods. A key that was never saved falls back to DEFAULTS, so
// the app works before anyone opens the configuration page.
class Setting
{
    protected const TABLE = 'settings';

    // The keys the app knows, with the value used until one is saved.
    public const SESSION_START_TIME = 'order_session.default_start_time';

    public const SESSION_END_TIME = 'order_session.default_end_time';

    public const DEFAULTS = [
        self::SESSION_START_TIME => '09:00',
        self::SESSION_END_TIME => '17:00',
    ];

    /* -------- Reads -------- */

    // One setting: the saved value, else the key's default (null for an unknown key).
    public static function get(string $key): ?string
    {
        $value = DB::table(self::TABLE)->where('key', $key)->value('value');

        return $value ?? (self::DEFAULTS[$key] ?? null);
    }

    // The default window of a new order session, as H:i strings.
    public static function sessionWindow(): array
    {
        return [
            'start_time' => self::get(self::SESSION_START_TIME),
            'end_time' => self::get(self::SESSION_END_TIME),
        ];
    }

    /* -------- Writes -------- */

    // Save one setting (insert, or replace the value of the existing key).
    public static function set(string $key, ?string $value): void
    {
        $now = now();

        // Not updateOrInsert: it would rewrite created_at on every save.
        $changed = DB::table(self::TABLE)->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);

        if ($changed === 0 && ! DB::table(self::TABLE)->where('key', $key)->exists()) {
            DB::table(self::TABLE)->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    // Save the default order session window.
    public static function setSessionWindow(string $start, string $end): void
    {
        DB::transaction(function () use ($start, $end) {
            self::set(self::SESSION_START_TIME, $start);
            self::set(self::SESSION_END_TIME, $end);
        });
    }
}
