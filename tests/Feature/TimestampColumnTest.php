<?php

namespace Tests\Feature;

use Tests\TestCase;

// A guard for a trap SQLite (the tests) cannot show: on MariaDB before 10.10 (XAMPP) and old
// MySQL, a NOT NULL timestamp() column with no default can be given ON UPDATE CURRENT_TIMESTAMP
// by the server, so the value is overwritten by any later update of the row. It happened to
// orders.order_date (fixed by 2026_11_26_100400). New columns must use dateTime(), or
// timestamp() that is nullable() or has a default.
class TimestampColumnTest extends TestCase
{
    // The two columns made before the rule, put right by the later migration.
    private const FIXED = [
        '2026_11_10_200300_create_orders_table.php' => 'order_date',
        '2026_11_13_200300_create_bulk_orders_table.php' => 'order_date',
    ];

    public function test_no_migration_adds_a_timestamp_column_the_server_may_rewrite(): void
    {
        $found = [];

        foreach (glob(database_path('migrations/*.php')) as $file) {
            // Code only (a comment may quote the old line to explain it), split into statements so
            // a chain written over several lines is judged whole.
            foreach (explode(';', php_strip_whitespace($file)) as $statement) {
                if (! preg_match('/\$table->timestamp\(\s*[\'"](\w+)[\'"]/', $statement, $column)) {
                    continue;
                }

                if (preg_match('/->(nullable|default|useCurrent)\(/', $statement)) {
                    continue;
                }

                if ((self::FIXED[basename($file)] ?? null) !== $column[1]) {
                    $found[] = basename($file) . ': ' . $column[1];
                }
            }
        }

        // Laravel's own tables are not changed by the app after they are written.
        $found = array_values(array_filter($found, fn (string $entry) => ! str_starts_with($entry, '0001_01_01_')));

        $this->assertSame([], $found, 'Use dateTime(), or make the timestamp() nullable or give it a default.');
    }
}
