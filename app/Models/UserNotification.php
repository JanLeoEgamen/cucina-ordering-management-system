<?php

namespace App\Models;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// A person's in-app notifications. Like Setting it is a set of static query builder
// methods. Every read is scoped by user_id and answered by an index (see the
// migration), and the text is stored ready to print, so nothing here joins.
class UserNotification
{
    protected const TABLE = 'user_notifications';

    // How long the bell's numbers are trusted at most, if a write ever forgot to clear them.
    protected const STATE_TTL = 60;

    /* -------- Reads -------- */

    // What the bell polls (about once a second per open tab): [unread, latest id]. Kept in the cache
    // and cleared by every write below, so a poll with nothing new costs no query at all (against a
    // remote database each one is a network trip). The TTL only bounds a race between a poll and a write.
    public static function state(int $userId): array
    {
        return Cache::remember(self::stateKey($userId), self::STATE_TTL, fn () => [
            'unread' => self::unreadCount($userId),
            'latest_id' => self::latestId($userId),
        ]);
    }

    protected static function stateKey(int $userId): string
    {
        return 'notifications.state.' . $userId;
    }

    // How many are unread (the badge). Answered from the (user_id, read_at, id) index.
    public static function unreadCount(int $userId): int
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    // The newest id of a person, or 0. The poll starts from it, so the first
    // request after a page load never has to send the list.
    public static function latestId(int $userId): int
    {
        return (int) DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->max('id');
    }

    // What arrived after $afterId, oldest first, capped: the poll only asks for
    // the new ones, so most answers are empty.
    public static function since(int $userId, int $afterId, int $limit = 10): array
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at'])
            ->all();
    }

    // The newest few of a person, newest first: what the bell's panel lists. Read from the
    // same (user_id, id) index as the page, with a LIMIT, so it never scans.
    public static function recent(int $userId, int $limit = 6): array
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at'])
            ->all();
    }

    // One page of a person's notifications, newest first, with the total for the page
    // numbers. The COUNT(*) is one person's rows, an index range on (user_id, id), and a
    // person's rows stay few because notifications:prune keeps the table short.
    public static function page(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate($perPage, ['id', 'type', 'title', 'body', 'url', 'read_at', 'created_at']);
    }

    // One notification of that person (never someone else's), or null.
    public static function findFor(int $userId, int $id): ?object
    {
        return DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    /* -------- Writes -------- */

    // Add many at once: one INSERT for everyone told about one event.
    // Each row: user_id, type, title, body, url, order_id.
    public static function insertMany(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();

        $userIds = array_unique(array_map(fn (array $row) => (int) $row['user_id'], $rows));

        DB::table(self::TABLE)->insert(array_map(fn (array $row) => [
            'user_id' => (int) $row['user_id'],
            'type' => $row['type'],
            'title' => mb_substr((string) $row['title'], 0, 160),
            'body' => isset($row['body']) ? mb_substr((string) $row['body'], 0, 500) : null,
            'url' => $row['url'] ?? null,
            'order_id' => $row['order_id'] ?? null,
            'read_at' => null,
            'created_at' => $now,
        ], $rows));

        foreach ($userIds as $userId) {
            Cache::forget(self::stateKey($userId));
        }
    }

    // Mark one as read; true when it was unread (a second click changes nothing).
    public static function markRead(int $userId, int $id): bool
    {
        $changed = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) === 1;

        Cache::forget(self::stateKey($userId));

        return $changed;
    }

    // Mark every unread one of the person as read, with a single UPDATE.
    public static function markAllRead(int $userId): int
    {
        $changed = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Cache::forget(self::stateKey($userId));

        return $changed;
    }

    // Delete read notifications older than $readDays and any older than $anyDays
    // (read or not), in batches so a big table never holds a long lock.
    public static function prune(int $readDays, int $anyDays): int
    {
        $deleted = 0;

        do {
            $ids = DB::table(self::TABLE)
                ->where(function ($query) use ($readDays, $anyDays) {
                    $query->where(function ($read) use ($readDays) {
                        $read->whereNotNull('read_at')->where('created_at', '<', now()->subDays($readDays));
                    })->orWhere('created_at', '<', now()->subDays($anyDays));
                })
                ->limit(1000)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += DB::table(self::TABLE)->whereIn('id', $ids)->delete();
            }
        } while ($ids->count() === 1000);

        return $deleted;
    }
}
