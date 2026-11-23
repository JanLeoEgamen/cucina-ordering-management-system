<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

// "Why is a write slow on THIS server?" Times, one by one, what a write request has to do, so the
// slow one shows: a database commit, a cache write, a file write. Run it ON the server:
//
//   php artisan perf:check            (add --n=50 for steadier numbers)
//
// It creates a scratch table of its own (zz_perf_check_xxxx), writes a few rows to it and drops it
// at the end, even when something fails. It touches no table of the app.
class PerfCheck extends Command
{
    protected $signature = 'perf:check {--n=20 : how many of each operation to time}';

    protected $description = 'Time a database commit, a cache write and a file write on this server (writes only to its own scratch table)';

    public function handle(): int
    {
        $n = max(1, (int) $this->option('n'));

        $this->line('PHP ' . PHP_VERSION . '  |  database ' . config('database.default') . ' @ ' . (config('database.connections.' . config('database.default') . '.host') ?: 'file')
            . '  |  cache ' . config('cache.default') . '  |  session ' . config('session.driver') . '  |  queue ' . config('queue.default'));
        $this->line('APP_DEBUG=' . (config('app.debug') ? 'true  <- turn it off in production' : 'false')
            . '  |  config cached: ' . (file_exists($this->laravel->getCachedConfigPath()) ? 'yes' : 'NO (run php artisan optimize)')
            . '  |  routes cached: ' . (file_exists($this->laravel->getCachedRoutesPath()) ? 'yes' : 'NO')
            . '  |  OPcache extension: ' . (extension_loaded('Zend OPcache') ? 'yes' : 'NO'));
        $this->newLine();

        $rows = [];

        $rows[] = ['database: one SELECT (round trip)', $this->each($n, fn () => DB::select('select 1'))];

        $table = 'zz_perf_check_' . Str::lower(Str::random(6));

        try {
            Schema::create($table, function ($t) {
                $t->id();
                $t->unsignedInteger('v');
            });

            // Each statement outside a transaction is its own commit: this is what a write without a
            // transaction pays PER STATEMENT (and what the disk sync of the database costs).
            $rows[] = ['database: INSERT, its own commit', $this->each($n, fn () => DB::table($table)->insert(['v' => 1]))];

            // The same rows in ONE transaction: one commit for all of them.
            $rows[] = ['database: INSERT, inside one transaction (per row)', $this->total(fn () => DB::transaction(function () use ($n, $table) {
                for ($i = 0; $i < $n; $i++) {
                    DB::table($table)->insert(['v' => 2]);
                }
            })) / $n];
        } catch (Throwable $error) {
            $this->warn('Could not time the database writes: ' . $error->getMessage());
        } finally {
            Schema::dropIfExists($table);
        }

        $key = 'perf_check.' . Str::random(6) . '.';
        $rows[] = ['cache (' . config('cache.default') . '): write', $this->each($n, fn () => Cache::forever($key . random_int(1, 9999), 'x'))];
        $rows[] = ['cache (' . config('cache.default') . '): read', $this->each($n, fn () => Cache::get($key . '1'))];

        $dir = storage_path('framework/cache');
        $file = $dir . '/perf_check_' . Str::random(6);
        $rows[] = ['file: small write with lock (sessions / cache files)', $this->each($n, fn () => file_put_contents($file, str_repeat('x', 512), LOCK_EX))];
        @unlink($file);

        $this->table(['what', 'ms each (average of ' . $n . ')'], array_map(fn (array $row) => [$row[0], number_format($row[1], 1)], $rows));

        $commit = isset($rows[1]) && $rows[1][0] === 'database: INSERT, its own commit' ? round($rows[1][1], 1) : null;

        if ($commit !== null) {
            $this->line($commit > 15
                ? "A commit takes {$commit} ms here: every statement outside a transaction pays it, and a request that writes 6 times waits 6x. The disk behind the database is the slow part (ask the host, or check for IO limits on the account)."
                : "A commit takes {$commit} ms: the database disk is not the problem.");
        }

        $this->line('Also check in cPanel: MultiPHP Manager -> this domain -> PHP-FPM, and whether ModSecurity is on for it.');

        return self::SUCCESS;
    }

    // Average milliseconds of $n runs.
    private function each(int $n, callable $work): float
    {
        return $this->total(function () use ($n, $work) {
            for ($i = 0; $i < $n; $i++) {
                $work();
            }
        }) / $n;
    }

    private function total(callable $work): float
    {
        $start = hrtime(true);
        $work();

        return (hrtime(true) - $start) / 1e6;
    }
}
