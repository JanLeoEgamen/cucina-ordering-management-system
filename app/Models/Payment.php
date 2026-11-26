<?php

namespace App\Models;

use App\Support\ServerTable;
use App\Support\Sql;
use App\Support\TableCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// The payment of an order. One row per order, so the order id is the primary
// key (see the payments migration). Same shape as the other models: query
// builder reads and writes, with the soft delete filter written in every method.
class Payment extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'payments';

    protected $table = self::TABLE;

    // The order id is the key, and it is not generated here.
    protected $primaryKey = 'order_id';

    public $incrementing = false;

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'order_id',
        'payment_method_id',
        'verified_by',
        'verified_at',
        'amount',
        'reference_number',
        'proof_of_payment',
        'payment_status',
        'rejection_reason',
        'is_active',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // The payment of an order, with its method, for the drawers.
    public static function ofOrder(int $orderId): ?object
    {
        return DB::table(self::TABLE)
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->where(self::TABLE . '.order_id', $orderId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->first([
                self::TABLE . '.order_id',
                self::TABLE . '.payment_method_id',
                self::TABLE . '.amount',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.payment_status',
                self::TABLE . '.verified_at',
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);
    }

    // Every live payment for the payments page: the order, the customer, the
    // method, who reviewed it, the proof as a URL and the lines of the order.
    // Newest first.
    public static function getAll(): Collection
    {
        return TableCache::remember(['orders', 'users', 'order_sessions', 'payments', 'payment_methods', 'order_items', 'session_dishes', 'dishes'], 'payment.all', fn () => self::loadAll());
    }

    // The queries behind getAll().
    protected static function loadAll(): Collection
    {
        return self::hydrate(
            self::listQuery()
                ->orderByDesc(self::TABLE . '.created_at')
                ->orderByDesc(self::TABLE . '.order_id')
                ->get()
        );
    }

    // For the analytics: money verified between two moments, added up per day, method and the
    // person who verified it (for cash that is whoever completed the order).
    public static function collectedBetween(string $startAt, string $endAt): Collection
    {
        return DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where(self::TABLE . '.payment_status', 'verified')
            ->whereBetween(self::TABLE . '.verified_at', [$startAt, $endAt])
            ->selectRaw('DATE(payments.verified_at) as day, payment_methods.method_name, payments.verified_by, '
                . User::fullNameSql('verifiers') . ' as verifier_name, COUNT(*) as payment_count, SUM(payments.amount) as amount')
            ->groupByRaw('DATE(payments.verified_at), payment_methods.method_name, payments.verified_by, verifiers.first_name, verifiers.last_name')
            ->get();
    }

    // Reference numbers typed on more than one order, where at least one of those orders was
    // placed in the range (the other may be any age: that is how an old proof is used again).
    // Spaces and letter case are ignored, and very short entries ("NA", "-") are not looked at.
    // Newest first, at most $limit of them.
    public static function repeatedReferences(string $startAt, string $endAt, int $limit): array
    {
        $normal = "UPPER(REPLACE(payments.reference_number, ' ', ''))";

        $live = fn () => DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereNotNull(self::TABLE . '.reference_number')
            ->whereRaw('LENGTH(' . $normal . ') >= 4');

        // ONE pass over the payments, grouped by the cleaned number: kept when it is on two or
        // more orders and at least one of them is in the range. Do not "narrow it first" with
        // `cleaned IN (subquery)`: no index serves a cleaned value, so the database compares
        // rows with rows (55 seconds at 30,000 orders on MariaDB, against a fraction of one).
        $references = $live()
            ->selectRaw($normal . ' as reference, MAX(orders.order_date) as newest')
            ->groupByRaw($normal)
            ->havingRaw('COUNT(*) > 1 AND SUM(CASE WHEN orders.order_date BETWEEN ? AND ? THEN 1 ELSE 0 END) > 0', [$startAt, $endAt])
            ->orderByDesc('newest')
            ->limit($limit + 1)
            ->get()
            ->pluck('reference')
            ->all();

        if ($references === []) {
            return ['groups' => collect(), 'more' => false];
        }

        $shown = array_slice($references, 0, $limit);

        $orders = $live()
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->whereIn(DB::raw($normal), $shown)
            ->orderByDesc('orders.order_date')
            ->selectRaw($normal . ' as reference, orders.order_id, orders.order_number, orders.order_date, orders.order_status, orders.user_id, '
                . User::fullNameSql('users') . ' as customer_name, payments.amount, payments.payment_status, payment_methods.method_name')
            ->get()
            ->groupBy('reference');

        return [
            'groups' => collect($shown)->mapWithKeys(fn (string $reference) => [$reference => $orders->get($reference, collect())]),
            'more' => count($references) > $limit,
        ];
    }

    // How long the bank / e-wallet payments decided in the range waited for their review, from
    // the payment's creation to the decision. Cancelled orders are left out: cancelling stamps
    // a pending payment as rejected, which is not a review. A resent proof keeps the first
    // creation time, so its wait reads long.
    public static function reviewTimes(string $startAt, string $endAt): Collection
    {
        return self::reviewBands(DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->join('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.order_status', '!=', 'cancelled')
            ->where('payment_methods.method_name', '!=', PaymentMethod::CASH_ON_PICKUP)
            ->whereBetween(self::TABLE . '.verified_at', [$startAt, $endAt])
            ->selectRaw(Sql::minutesBetween('payments.created_at', 'payments.verified_at') . ' as minutes'));
    }

    // A query of waits (one `minutes` column) added up in bands: 1 = up to 5 minutes, 2 = up to
    // 15, 3 = up to an hour, 4 = longer. Each with how many, their total and the longest.
    public static function reviewBands($waits): Collection
    {
        $banded = DB::query()->fromSub($waits, 'waits')
            ->selectRaw('CASE WHEN minutes <= 5 THEN 1 WHEN minutes <= 15 THEN 2 WHEN minutes <= 60 THEN 3 ELSE 4 END as band, minutes');

        return DB::query()->fromSub($banded, 'banded')
            ->selectRaw('band, COUNT(*) as decisions, SUM(minutes) as minutes, MAX(minutes) as longest')
            ->groupBy('band')
            ->get()
            ->keyBy('band');
    }

    // The money verified (collected) on any of the given days (Y-m-d), for the dashboard: when
    // it was verified and how much. The days are passed in, so the cache never depends on the clock.
    public static function verifiedOnDays(array $days): Collection
    {
        return TableCache::remember(['payments', 'orders'], 'dashboard.verified.' . implode('.', $days), fn () => DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where(self::TABLE . '.payment_status', 'verified')
            ->where(function ($any) use ($days) {
                foreach ($days as $day) {
                    $any->orWhereBetween(self::TABLE . '.verified_at', [$day . ' 00:00:00', $day . ' 23:59:59']);
                }
            })
            ->get([self::TABLE . '.verified_at', self::TABLE . '.amount']));
    }

    // The status of a live payment, or null when there is none.
    public static function statusOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('payment_status');
    }

    /* -------- Writes -------- */

    // Soft delete a payment; returns whether a live row was removed.
    public static function deletePayment(int $orderId): bool
    {
        $now = now();

        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => $now, 'updated_at' => $now]) === 1;
    }

    // Write the payment of an order with the method the customer picked. The
    // row is created with the order, so it is always there to be verified.
    // Cash on pickup sends no reference and no proof; a bank or e-wallet pays
    // send one, and the proof is kept for whoever verifies the payment.
    public static function createForOrder(int $orderId, int $methodId, float $amount, ?string $referenceNumber, ?string $proofOfPayment = null): void
    {
        $now = now();

        DB::table(self::TABLE)->updateOrInsert(
            ['order_id' => $orderId],
            [
                'payment_method_id' => $methodId,
                'amount' => $amount,
                'reference_number' => $referenceNumber,
                'proof_of_payment' => $proofOfPayment,
                'payment_status' => 'pending',
                'is_active' => 1,
                'deleted_at' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    // A stored path becomes a public URL the drawers can use; absolute URLs
    // (fixtures, factory data) are left alone.
    public static function proofUrl(?string $path): ?string
    {
        if (! $path || str_starts_with($path, 'http')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    // The customer fixes a rejected payment: a new proof (same or another
    // method) and the payment is pending again, for staff to check. The old
    // reviewer, moment and rejection reason are cleared, the amount stays the
    // order's total. Returns the number of changed rows.
    public static function resubmit(int $orderId, int $methodId, ?string $referenceNumber, ?string $proofOfPayment): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_method_id' => $methodId,
                'reference_number' => $referenceNumber,
                'proof_of_payment' => $proofOfPayment,
                'payment_status' => 'pending',
                'verified_by' => null,
                'verified_at' => null,
                'rejection_reason' => null,
                'updated_at' => now(),
            ]);
    }

    // The order's total changed (a discount was rejected): the payment is for the new amount.
    public static function setAmount(int $orderId, float $amount): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['amount' => $amount, 'updated_at' => now()]);
    }

    // Verify, reject or put back to pending. The reviewer and the moment are
    // stamped (and cleared again for pending, when $verifiedBy is null); the
    // reason belongs to a rejection only, so any other status clears it.
    // Returns the number of changed rows.
    public static function setStatus(int $orderId, string $status, ?int $verifiedBy, ?string $reason = null): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'payment_status' => $status,
                'verified_by' => $verifiedBy,
                'verified_at' => $verifiedBy ? now() : null,
                'rejection_reason' => $status === 'rejected' ? $reason : null,
                'updated_at' => now(),
            ]);
    }
    /* -------- Internals -------- */

    // The joins and columns of the list.
    // ONE PAGE of the staff Payments table (DataTables in serverSide mode); see Order::staffPage().
    public static function staffPage(array $table, array $filters): array
    {
        $total = DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->count();

        $narrowed = fn () => self::staffNarrowed(self::listQuery(), $table['words'], $filters);

        $filtered = ($table['words'] === [] && ! array_filter($filters)) ? $total : $narrowed()->count();

        // What the column index of the table sorts by (see payments.js); anything else = newest first.
        $sorts = [
            1 => ['orders.order_number'],
            2 => [User::fullNameSql('users')],
            3 => ['payment_methods.method_name'],
            4 => [self::TABLE . '.reference_number'],
            5 => [self::TABLE . '.amount'],
            7 => [self::TABLE . '.payment_status'],
            8 => [self::TABLE . '.created_at'],
        ];

        $query = $narrowed();

        foreach ($sorts[$table['column']] ?? [self::TABLE . '.created_at'] as $expression) {
            $query->orderByRaw($expression . ' ' . $table['dir']);
        }

        $rows = $query
            ->orderByDesc(self::TABLE . '.order_id')
            ->offset($table['start'])
            ->limit($table['length'])
            ->get();

        return ['rows' => self::hydrate($rows)->all(), 'total' => $total, 'filtered' => $filtered];
    }

    protected static function staffNarrowed($query, array $words, array $filters)
    {
        $query
            // The Payments page's one status filter is the PAYMENT's status.
            ->when($filters['status'] !== '', fn ($q) => $q->where(self::TABLE . '.payment_status', $filters['status']))
            ->when($filters['method'] > 0, fn ($q) => $q->where(self::TABLE . '.payment_method_id', $filters['method']))
            ->when($filters['session'] > 0, fn ($q) => $q->where('orders.session_id', $filters['session']))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate(self::TABLE . '.created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate(self::TABLE . '.created_at', '<=', $filters['to']));

        ServerTable::search($query, $words, [
            'orders.order_number',
            User::fullNameSql('users'),
            'payment_methods.method_name',
            self::TABLE . '.reference_number',
            self::TABLE . '.payment_status',
            'CAST(' . self::TABLE . '.amount AS CHAR)',
        ]);

        return $query;
    }

    protected static function listQuery()
    {
        return DB::table(self::TABLE)
            ->join('orders', 'orders.order_id', '=', self::TABLE . '.order_id')
            ->join('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('order_sessions', 'order_sessions.session_id', '=', 'orders.session_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', self::TABLE . '.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', self::TABLE . '.verified_by')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('orders.deleted_at')
            ->select([
                self::TABLE . '.order_id',
                self::TABLE . '.amount',
                self::TABLE . '.reference_number',
                self::TABLE . '.proof_of_payment',
                self::TABLE . '.payment_status',
                self::TABLE . '.rejection_reason',
                self::TABLE . '.verified_at',
                self::TABLE . '.created_at',
                'orders.order_number',
                'orders.order_status',
                'orders.discount_status',
                'orders.session_id',
                self::TABLE . '.payment_method_id',
                'order_sessions.start_time',
                'order_sessions.end_time',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'order_sessions.order_date as session_date',
                'payment_methods.method_name',
                DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            ]);
    }

    // Add the lines of each order (one query for all) and the proof URL.
    protected static function hydrate(Collection $rows): Collection
    {
        $items = OrderItem::itemsByOrder($rows->pluck('order_id')->all());

        return $rows->map(function (object $payment) use ($items) {
            $payment->items = $items[$payment->order_id] ?? [];
            $payment->proof_url = self::proofUrl($payment->proof_of_payment);

            return $payment;
        })->values();
    }
}