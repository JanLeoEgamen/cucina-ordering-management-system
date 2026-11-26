<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

// The few date expressions SQLite (the tests, local) and MySQL / MariaDB spell differently, for the
// sums the database does. Column names are written by the code, never taken from a request.
class Sql
{
    private static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    // The hour of a datetime, 0 to 23.
    public static function hour(string $column): string
    {
        return self::sqlite() ? "CAST(strftime('%H', {$column}) AS INTEGER)" : "HOUR({$column})";
    }

    // Minutes (with a fraction) from one datetime to a later one. Both count whole seconds: SQLite's
    // julianday() works in fractions of a day, and its 5 minutes can come out as 5.0000003, which
    // moved a wait of exactly a band's limit into the next band.
    public static function minutesBetween(string $from, string $to): string
    {
        return self::sqlite()
            ? "((CAST(strftime('%s', {$to}) AS INTEGER) - CAST(strftime('%s', {$from}) AS INTEGER)) / 60.0)"
            : "(TIMESTAMPDIFF(SECOND, {$from}, {$to}) / 60)";
    }

    public static function plusDays(string $column, int $days): string
    {
        return self::sqlite() ? "datetime({$column}, '+{$days} days')" : "DATE_ADD({$column}, INTERVAL {$days} DAY)";
    }
}
