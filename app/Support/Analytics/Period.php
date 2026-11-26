<?php

namespace App\Support\Analytics;

use App\Support\Memo;
use App\Support\TableCache;
use Closure;
use Illuminate\Support\Carbon;

// The date range the Analytics page is looking at: a preset, or two days the person typed. Both
// ends are whole days and included. Anything that is not a sensible range reads as the default.
class Period
{
    public const PRESETS = [
        'today' => 'Today',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
        'month' => 'This month',
        'last-month' => 'Last month',
    ];

    public const DEFAULT = '30d';

    public const CUSTOM = 'custom';

    // The longest custom range: a leap year.
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $key,
        public readonly Carbon $from,
        public readonly Carbon $to,
        // The person asked for a range that could not be used.
        public readonly bool $refused = false,
        // A CSV is being made: the whole list is wanted, not the page's first rows.
        public readonly bool $exporting = false,
    ) {
    }

    // The most rows a list gives to a CSV.
    public const EXPORT_ROWS = 5000;

    public function forExport(): self
    {
        return new self($this->key, $this->from, $this->to, $this->refused, true);
    }

    // How many rows of a list to give: the page shows $shown, a CSV takes (nearly) all.
    public function limit(int $shown): int
    {
        return $this->exporting ? self::EXPORT_ROWS : $shown;
    }

    // $input is the query string: range=<preset>, or range=custom&from=Y-m-d&to=Y-m-d.
    public static function fromInput(array $input): self
    {
        $range = is_string($input['range'] ?? null) ? $input['range'] : self::DEFAULT;

        if ($range === self::CUSTOM) {
            return self::custom($input['from'] ?? null, $input['to'] ?? null) ?? self::preset(self::DEFAULT, true);
        }

        return isset(self::PRESETS[$range]) ? self::preset($range) : self::preset(self::DEFAULT);
    }

    public static function preset(string $key, bool $refused = false): self
    {
        $today = now()->startOfDay();

        [$from, $to] = match ($key) {
            'today' => [$today->copy(), $today->copy()],
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last-month' => [
                $today->copy()->startOfMonth()->subMonthNoOverflow(),
                $today->copy()->startOfMonth()->subDay(),
            ],
            default => [$today->copy()->subDays(29), $today->copy()],
        };

        return new self($key, $from, $to, $refused);
    }

    // null when the two days make no usable range. An end in the future is cut to today.
    private static function custom(mixed $from, mixed $to): ?self
    {
        $from = self::day($from);
        $to = self::day($to);

        if (! $from || ! $to) {
            return null;
        }

        $to = $to->min(now()->startOfDay());

        if ($from->gt($to) || $from->diffInDays($to) >= self::MAX_DAYS) {
            return null;
        }

        return new self(self::CUSTOM, $from, $to);
    }

    private static function day(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $part)) {
            return null;
        }

        return checkdate((int) $part[2], (int) $part[3], (int) $part[1]) ? Carbon::parse($value)->startOfDay() : null;
    }

    // The query string that asks for this range again.
    public function query(): array
    {
        return $this->key === self::CUSTOM
            ? ['range' => self::CUSTOM, 'from' => $this->fromDate(), 'to' => $this->toDate()]
            : ['range' => $this->key];
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    public function startAt(): string
    {
        return $this->fromDate() . ' 00:00:00';
    }

    public function endAt(): string
    {
        return $this->toDate() . ' 23:59:59';
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function includesToday(): bool
    {
        return $this->to->isSameDay(now());
    }

    // What this range is compared with. "Today" is compared with the same weekday last week, a
    // month with the month before, anything else with the same number of days just before it.
    // While the range runs up to today, the other one stops at the same time of day on its last
    // day, so part of a day is never compared with a whole one.
    public function previous(): array
    {
        [$from, $to] = match ($this->key) {
            'today' => [$this->from->copy()->subWeek(), $this->to->copy()->subWeek()],
            'month' => [$this->from->copy()->subMonthNoOverflow(), $this->to->copy()->subMonthNoOverflow()],
            'last-month' => [
                $this->from->copy()->subMonthNoOverflow(),
                $this->from->copy()->subDay(),
            ],
            default => [$this->from->copy()->subDays($this->days()), $this->from->copy()->subDay()],
        };

        $partial = $this->includesToday();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'startAt' => $from->toDateString() . ' 00:00:00',
            'endAt' => $to->toDateString() . ' ' . ($partial ? now()->format('H:i:s') : '23:59:59'),
            'partial' => $partial,
        ];
    }

    // "Oct 28 – Nov 26, 2026"
    public function label(): string
    {
        return self::span($this->fromDate(), $this->toDate());
    }

    public static function span(string $from, string $to): string
    {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);

        if ($from->isSameDay($to)) {
            return $to->translatedFormat('M j, Y');
        }

        return $from->translatedFormat($from->year === $to->year ? 'M j' : 'M j, Y') . ' – ' . $to->translatedFormat('M j, Y');
    }

    // A read of this range, cached with its tables. A typed (custom) range is never cached: every
    // pair of days would leave a cache file of its own. The days are part of the key, so nothing
    // cached depends on the clock.
    // Within one page (Memo) a read is made once, whatever the range: five widgets use the same
    // daily totals, and for a typed range that was the same query five times.
    public function remember(array $tables, string $name, Closure $load): mixed
    {
        $key = 'analytics.' . $name . '.' . $this->fromDate() . '.' . $this->toDate();

        return Memo::get($key . ($this->exporting ? '.csv' : ''), function () use ($tables, $key, $load) {
            // Nor is a CSV's: its lists are longer than the page's, under the same name.
            if ($this->key === self::CUSTOM || $this->exporting) {
                return $load();
            }

            return TableCache::remember($tables, $key, $load);
        });
    }
}
