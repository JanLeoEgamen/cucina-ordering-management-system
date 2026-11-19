<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

// The audit trail. Like UserNotification it is a set of static query builder methods, and it
// is APPEND ONLY on purpose: there is record() to add a row, reads for the viewer, and
// prune() for the retention window. Nothing edits or deletes a single row.
class AuditLog
{
    protected const TABLE = 'audit_logs';

    public const EVENTS = ['auth', 'action', 'view', 'system'];

    public const OUTCOMES = ['success', 'failed', 'denied', 'error'];

    // Rows per page on the viewer: the choices offered under the table, and the one used until
    // somebody picks another (the same 10 / 25 / 50 / 100 family as the app's other tables).
    public const PAGE_SIZES = [10, 25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 25;

    // The most rows one CSV export writes (the page is for reading; a bigger dump is a database job).
    public const EXPORT_LIMIT = 10000;

    /* -------- Writes -------- */

    // Add one entry. $row may leave out anything but `event`, `action` and `description`;
    // every text is cut to its column so a long input can never make the insert fail.
    public static function record(array $row): void
    {
        DB::table(self::TABLE)->insert([
            'user_id' => $row['user_id'] ?? null,
            'user_name' => self::cut($row['user_name'] ?? null, 160),
            'user_email' => self::cut($row['user_email'] ?? null, 191),
            'event' => $row['event'],
            'action' => self::cut($row['action'], 80),
            'description' => self::cut($row['description'], 255),
            'subject_type' => self::cut($row['subject_type'] ?? null, 40),
            'subject_id' => $row['subject_id'] ?? null,
            'subject_label' => self::cut($row['subject_label'] ?? null, 160),
            'method' => $row['method'] ?? null,
            'path' => self::cut($row['path'] ?? null, 255),
            'outcome' => $row['outcome'] ?? 'success',
            'status' => $row['status'] ?? null,
            'message' => self::cut($row['message'] ?? null, 255),
            'changes' => self::json($row['changes'] ?? null),
            'input' => self::json($row['input'] ?? null),
            'ip' => $row['ip'] ?? null,
            'user_agent' => self::cut($row['user_agent'] ?? null, 255),
            'created_at' => now(),
        ]);
    }

    // Delete what is older than $days (the retention window); returns how many rows went.
    // The one place rows are ever removed.
    public static function prune(int $days): int
    {
        return DB::table(self::TABLE)
            ->where('created_at', '<', now()->subDays(max(1, $days)))
            ->delete();
    }

    /* -------- Reads -------- */

    // The viewer's query: newest first, narrowed by whatever filters were given
    // (q, event, outcome, user, from, to). Shared by the page and the CSV export.
    public static function filtered(array $filters): Builder
    {
        $query = DB::table(self::TABLE)->orderByDesc('id');

        if (! empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';

            $query->where(function (Builder $query) use ($like) {
                $query->where('description', 'like', $like)
                    ->orWhere('subject_label', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhere('action', 'like', $like)
                    ->orWhere('message', 'like', $like)
                    ->orWhere('ip', 'like', $like);
            });
        }

        foreach (['event', 'outcome'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['user'])) {
            $query->where('user_id', (int) $filters['user']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'] . ' 00:00:00');
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to'] . ' 23:59:59');
        }

        return $query;
    }

    // One page for the viewer. The COUNT(*) follows the filters, so a narrow filter is cheap.
    public static function page(array $filters, int $perPage = self::DEFAULT_PAGE_SIZE): LengthAwarePaginator
    {
        return self::filtered($filters)->paginate($perPage);
    }

    // One entry with its JSON decoded for the detail page, or null.
    public static function findById(int $id): ?object
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        if (! $row) {
            return null;
        }

        $row->changes = $row->changes ? json_decode($row->changes, true) : [];
        $row->input = $row->input ? json_decode($row->input, true) : [];

        return $row;
    }

    // The newest entries of a record, for a future "history" panel (uses the subject index).
    public static function forSubject(string $type, int $id, int $limit = 20): array
    {
        return DB::table(self::TABLE)
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /* -------- Helpers -------- */

    protected static function cut(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    // JSON for a json column, or null for nothing. Invalid UTF-8 in an input must not lose the row.
    protected static function json(?array $value): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? null : $encoded;
    }
}
