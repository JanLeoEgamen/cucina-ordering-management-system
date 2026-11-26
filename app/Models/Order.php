<?php

namespace App\Models;

use App\Support\Memo;
use App\Support\ServerTable;
use App\Support\Sql;
use App\Support\TableCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Orders placed inside an order session. Same shape as DishType: query builder
// reads and writes below, with the soft delete filter written in every method.
class Order extends Model
{
    use HasFactory, SoftDeletes;

    // Table name, used by the query builder methods and by Eloquent.
    protected const TABLE = 'orders';

    protected $table = self::TABLE;

    // Primary key of the table.
    protected $primaryKey = 'order_id';

    // Insertable and updatable columns.
    protected const COLUMNS = [
        'session_id',
        'user_id',
        'order_number',
        'order_date',
        'total_amount',
        'order_status',
        'payment_status',
        'is_active',
        // The discount the customer picked: a COPY of its name and percent (see Discount).
        'discount_id',
        'discount_name',
        'discount_percent',
        'subtotal_amount',
        'discount_amount',
        'discount_status',
        'discount_proof',
    ];

    protected $fillable = self::COLUMNS;

    // Casts apply to Eloquent reads; query builder rows come back as raw values.
    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'total_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /* -------- Reads -------- */

    // Every order still pending, oldest first, with what the staff dashboard widgets ask about:
    // customer, payment, discount, ready announcement, the session's window and the plates held.
    // ONE cached query that the attention, unclaimed and cash widgets share; whatever depends on
    // the clock (has the session ended?) is worked out by the caller, never in here.
    public static function openForDashboard(): Collection
    {
        return Memo::get('order.open_for_dashboard', fn () => TableCache::remember(['orders', 'users', 'order_sessions', 'payments', 'payment_methods', 'order_items'], 'dashboard.open_orders', function () {
            $plates = DB::table('order_items')
                ->selectRaw('coalesce(sum(quantity), 0)')
                ->whereColumn('order_items.order_id', self::TABLE . '.order_id')
                ->whereNull('order_items.deleted_at');

            return self::listQuery()
                ->whereNull('payments.deleted_at')
                ->where(self::TABLE . '.order_status', 'pending')
                ->orderBy(self::TABLE . '.order_id')
                ->select([
                    self::TABLE . '.order_id',
                    self::TABLE . '.session_id',
                    self::TABLE . '.order_number',
                    self::TABLE . '.order_date as placed_at',
                    self::TABLE . '.total_amount',
                    self::TABLE . '.discount_status',
                    self::TABLE . '.ready_at',
                    DB::raw(User::fullNameSql('users') . ' as customer_name'),
                    'payments.payment_status as payment_state',
                    'payment_methods.method_name',
                    'order_sessions.order_date as session_date',
                    'order_sessions.start_time as session_start',
                    'order_sessions.end_time as session_end',
                    'order_sessions.ended_at as session_ended_at',
                    'order_sessions.is_active as session_is_active',
                ])
                ->selectSub($plates, 'plates')
                ->get();
        }));
    }

    // The orders placed on any of the given days (Y-m-d): when, status and total. The days are
    // passed in, so the cached result never depends on the clock.
    public static function placedOnDays(array $days): Collection
    {
        return TableCache::remember(['orders'], 'dashboard.placed.' . implode('.', $days), fn () => DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where(function (Builder $any) use ($days) {
                foreach ($days as $day) {
                    $any->orWhereBetween('order_date', [$day . ' 00:00:00', $day . ' 23:59:59']);
                }
            })
            ->get(['order_date', 'order_status', 'total_amount']));
    }

    // One customer's own history for their dashboard widgets: every order placed since $since
    // (Y-m-d) plus any still pending, cancelled ones left out, newest first, each with its lines
    // as dish ids. ONE query for the active orders, pickup, favourites, usual order and spending.
    public static function dashboardHistoryOfUser(int $userId, string $since): Collection
    {
        $tables = ['orders', 'order_items', 'session_dishes', 'dishes', 'payments', 'payment_methods'];

        return TableCache::remember($tables, 'dashboard.history.' . $userId . '.' . $since, fn () => self::loadHistoryOfUser($userId, $since));
    }

    // The query behind dashboardHistoryOfUser().
    protected static function loadHistoryOfUser(int $userId, string $since): Collection
    {
        return DB::table(self::TABLE)
            ->leftJoin('order_items', fn ($join) => $join->on('order_items.order_id', '=', self::TABLE . '.order_id')
                ->whereNull('order_items.deleted_at'))
            ->leftJoin('session_dishes', 'session_dishes.session_dish_id', '=', 'order_items.session_dish_id')
            ->leftJoin('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.order_status', '!=', 'cancelled')
            ->where(fn (Builder $recent) => $recent->where(self::TABLE . '.order_date', '>=', $since . ' 00:00:00')
                ->orWhere(self::TABLE . '.order_status', 'pending'))
            ->orderByDesc(self::TABLE . '.order_id')
            ->orderBy('order_items.order_item_id')
            ->get([
                self::TABLE . '.order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.ready_at',
                self::TABLE . '.total_amount',
                self::TABLE . '.discount_status',
                self::TABLE . '.discount_amount',
                'payment_methods.method_name',
                'dishes.dish_id',
                'dishes.dish_name',
                'order_items.quantity',
            ])
            ->groupBy('order_id')
            ->map(function (Collection $rows) {
                $order = clone $rows->first();

                // An order with no line at all keeps an empty list.
                $order->lines = $rows->whereNotNull('dish_id')
                    ->map(fn (object $row) => [
                        'dish_id' => (int) $row->dish_id,
                        'dish_name' => $row->dish_name,
                        'quantity' => (int) $row->quantity,
                    ])
                    ->values()
                    ->all();

                unset($order->dish_id, $order->dish_name, $order->quantity);

                return $order;
            })
            ->values();
    }

    /* -------- Analytics: totals added up by the database -------- */

    // Orders placed between two moments, added up per day, status and payment method. The size of
    // the result follows the days in the range, not the number of orders.
    public static function dailyTotals(string $startAt, string $endAt): Collection
    {
        return DB::table(self::TABLE)
            ->leftJoin('payments', fn ($join) => $join->on('payments.order_id', '=', self::TABLE . '.order_id')
                ->whereNull('payments.deleted_at'))
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereBetween(self::TABLE . '.order_date', [$startAt, $endAt])
            ->selectRaw('DATE(orders.order_date) as day, orders.order_status, payment_methods.method_name, COUNT(*) as order_count, SUM(orders.total_amount) as amount')
            ->groupByRaw('DATE(orders.order_date), orders.order_status, payment_methods.method_name')
            ->get();
    }

    // How many orders held 1 plate, 2 plates and so on (cancelled ones left out).
    public static function plateCounts(string $startAt, string $endAt): Collection
    {
        $perOrder = DB::table(self::TABLE)
            ->join('order_items', fn ($join) => $join->on('order_items.order_id', '=', self::TABLE . '.order_id')
                ->whereNull('order_items.deleted_at'))
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.order_status', '!=', 'cancelled')
            ->whereBetween(self::TABLE . '.order_date', [$startAt, $endAt])
            ->groupBy(self::TABLE . '.order_id')
            ->selectRaw('SUM(order_items.quantity) as plates');

        return DB::query()->fromSub($perOrder, 'baskets')
            ->selectRaw('plates, COUNT(*) as order_count')
            ->groupBy('plates')
            ->get();
    }

    // The cancelled orders placed in the range, added up by who called them off: the customer,
    // staff, or not recorded (cancelled before orders kept that).
    public static function cancelledTotals(string $startAt, string $endAt): Collection
    {
        $plates = DB::table('order_items')
            ->selectRaw('coalesce(sum(quantity), 0)')
            ->whereColumn('order_items.order_id', self::TABLE . '.order_id')
            ->whereNull('order_items.deleted_at');

        $cancelled = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('order_status', 'cancelled')
            ->whereBetween('order_date', [$startAt, $endAt])
            ->selectRaw("CASE WHEN cancelled_by IS NULL THEN 'unknown' WHEN cancelled_by = user_id THEN 'customer' ELSE 'staff' END as who, total_amount")
            ->selectSub($plates, 'plates');

        return DB::query()->fromSub($cancelled, 'cancelled')
            ->selectRaw('who, COUNT(*) as order_count, SUM(total_amount) as amount, SUM(plates) as plates')
            ->groupBy('who')
            ->get();
    }

    // The reasons staff typed most often for the cancelled orders of the range.
    public static function cancelReasons(string $startAt, string $endAt, int $limit): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('order_status', 'cancelled')
            ->whereNotNull('cancel_reason')
            ->whereBetween('order_date', [$startAt, $endAt])
            ->selectRaw('cancel_reason, COUNT(*) as order_count')
            ->groupBy('cancel_reason')
            ->orderByDesc('order_count')
            ->orderBy('cancel_reason')
            ->limit($limit)
            ->get();
    }

    // Per order session held between two days: orders (cancelled left out) and completed sales.
    public static function totalsBySession(string $fromDate, string $toDate): Collection
    {
        return DB::table(self::TABLE)
            ->join('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->whereNull(self::TABLE . '.deleted_at')
            ->whereNull('order_sessions.deleted_at')
            ->whereDate('order_sessions.order_date', '>=', $fromDate)
            ->whereDate('order_sessions.order_date', '<=', $toDate)
            ->where(self::TABLE . '.order_status', '!=', 'cancelled')
            ->selectRaw("orders.session_id, COUNT(*) as order_count, SUM(CASE WHEN orders.order_status = 'completed' THEN orders.total_amount ELSE 0 END) as sales")
            ->groupBy(self::TABLE . '.session_id')
            ->get()
            ->keyBy('session_id');
    }

    // Orders (cancelled left out) per day and hour of the day they were placed.
    public static function hourlyCounts(string $startAt, string $endAt): Collection
    {
        $hour = Sql::hour('orders.order_date');

        return self::placedKept($startAt, $endAt)
            ->selectRaw('DATE(orders.order_date) as day, ' . $hour . ' as hour_of_day, COUNT(*) as order_count')
            ->groupByRaw('DATE(orders.order_date), ' . $hour)
            ->get();
    }

    // The customers who ordered in the range, added up by how many orders each placed in it
    // (1, 2-3, 4-9, 10+) and whether their first order ever falls in the range (new).
    public static function customerFrequency(string $startAt, string $endAt): Collection
    {
        $inRange = self::placedKept($startAt, $endAt)->selectRaw('orders.user_id, COUNT(*) as range_orders')->groupBy(self::TABLE . '.user_id');

        $first = DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('order_status', '!=', 'cancelled')
            ->selectRaw('user_id, MIN(order_date) as first_at')
            ->groupBy('user_id');

        $each = DB::query()
            ->fromSub($inRange, 'in_range')
            ->joinSub($first, 'ever', 'ever.user_id', '=', 'in_range.user_id')
            ->selectRaw('CASE WHEN in_range.range_orders = 1 THEN 1 WHEN in_range.range_orders <= 3 THEN 2 WHEN in_range.range_orders <= 9 THEN 4 ELSE 10 END as bucket, CASE WHEN ever.first_at >= ? THEN 1 ELSE 0 END as is_new, in_range.range_orders', [$startAt]);

        return DB::query()->fromSub($each, 'each_customer')
            ->selectRaw('bucket, is_new, COUNT(*) as customers, SUM(range_orders) as order_count')
            ->groupBy('bucket', 'is_new')
            ->get();
    }

    // For each cohort [key => [startAt, endAt]]: who ordered in it, and how many of them ordered
    // again within $days of their first order in it. One statement for all the cohorts.
    public static function repeatCohorts(array $cohorts, int $days): Collection
    {
        $query = null;

        foreach ($cohorts as $key => [$startAt, $endAt]) {
            $first = self::placedKept($startAt, $endAt)->selectRaw('orders.user_id, MIN(orders.order_date) as first_at')->groupBy(self::TABLE . '.user_id');

            $cohort = DB::query()->fromSub($first, 'cohort')->selectRaw(
                '? as cohort_key, COUNT(*) as customers, SUM(CASE WHEN EXISTS (SELECT 1 FROM orders AS again WHERE again.user_id = cohort.user_id'
                . " AND again.deleted_at IS NULL AND again.order_status <> 'cancelled' AND again.order_date > cohort.first_at"
                . ' AND again.order_date <= ' . Sql::plusDays('cohort.first_at', $days) . ') THEN 1 ELSE 0 END) as returned',
                [$key]
            );

            $query = $query === null ? $cohort : $query->unionAll($cohort);
        }

        return $query === null ? collect() : $query->get()->keyBy('cohort_key');
    }

    // Customers whose LAST order falls between $since and $before: they used to order and
    // stopped. Those who ordered most come first. Deleted accounts are left out.
    public static function lapsedCustomers(string $since, string $before, int $limit): array
    {
        $lapsed = fn () => DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->whereNull('users.deleted_at')
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.order_status', '!=', 'cancelled')
            ->groupBy('users.id', 'users.first_name', 'users.last_name')
            ->havingRaw('MAX(orders.order_date) < ? AND MAX(orders.order_date) >= ?', [$before, $since])
            ->selectRaw(User::fullNameSql('users') . ' as customer_name, COUNT(*) as order_count, MAX(orders.order_date) as last_at');

        return [
            'total' => DB::query()->fromSub($lapsed(), 'lapsed')->count(),
            'rows' => $lapsed()->orderByDesc('order_count')->orderByDesc('last_at')->limit($limit)->get(),
        ];
    }

    // The customers with the most completed sales in the range, with their company.
    public static function topCustomers(string $startAt, string $endAt, int $limit): Collection
    {
        return self::placedKept($startAt, $endAt)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('companies', 'companies.company_id', '=', 'users.company_id')
            ->where(self::TABLE . '.order_status', 'completed')
            ->groupBy('users.id', 'users.first_name', 'users.last_name', 'companies.company_name')
            ->selectRaw(User::fullNameSql('users') . ' as customer_name, companies.company_name, COUNT(*) as order_count, SUM(orders.total_amount) as sales')
            ->orderByDesc('sales')
            ->orderByDesc('order_count')
            ->limit($limit)
            ->get();
    }

    // Orders, completed sales and distinct customers per company: the company each person has
    // NOW (an order does not keep the one they had).
    public static function totalsByCompany(string $startAt, string $endAt): Collection
    {
        return self::placedKept($startAt, $endAt)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('companies', 'companies.company_id', '=', 'users.company_id')
            ->groupBy('companies.company_id', 'companies.company_name')
            ->selectRaw("companies.company_name, COUNT(*) as order_count, SUM(CASE WHEN orders.order_status = 'completed' THEN orders.total_amount ELSE 0 END) as sales, COUNT(DISTINCT orders.user_id) as customers")
            ->get();
    }

    // The range's orders that carry a discount, added up by the discount's name, its review state
    // and the order's status. Totals only: no customer is read.
    public static function discountTotals(string $startAt, string $endAt): Collection
    {
        return DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->whereBetween('order_date', [$startAt, $endAt])
            ->where('discount_status', '!=', 'none')
            ->selectRaw('discount_name, discount_status, order_status, COUNT(*) as order_count, SUM(discount_amount) as amount')
            ->groupBy('discount_name', 'discount_status', 'order_status')
            ->get();
    }

    // How long the discounts decided in the range waited, from the order to the decision, in
    // bands of minutes (see Payment::reviewBands()).
    public static function discountReviewTimes(string $startAt, string $endAt): Collection
    {
        return Payment::reviewBands(DB::table(self::TABLE)
            ->whereNull('deleted_at')
            ->where('order_status', '!=', 'cancelled')
            ->whereIn('discount_status', ['approved', 'rejected'])
            ->whereBetween('discount_decided_at', [$startAt, $endAt])
            ->selectRaw(Sql::minutesBetween('order_date', 'discount_decided_at') . ' as minutes'));
    }

    // The orders placed in the range that were not cancelled.
    protected static function placedKept(string $startAt, string $endAt): Builder
    {
        return DB::table(self::TABLE)
            ->whereNull(self::TABLE . '.deleted_at')
            ->where(self::TABLE . '.order_status', '!=', 'cancelled')
            ->whereBetween(self::TABLE . '.order_date', [$startAt, $endAt]);
    }

    // The orders of one session, with the customer, the payment and the lines,
    // for the orders list of the session page.
    public static function ofSession(int $sessionId): array
    {
        return self::list($sessionId);
    }

    // Every live order of every session, newest first, for the Orders page.
    public static function getAll(): array
    {
        return self::list();
    }

    // The orders of one session, or of all of them, with the customer, the session
    // they were placed in, the payment and the lines.
    protected static function list(?int $sessionId = null): array
    {
        return TableCache::remember(['orders', 'users', 'order_sessions', 'payments', 'payment_methods', 'order_items', 'session_dishes', 'dishes'], 'order.list.' . ($sessionId ?? 'all'), fn () => self::loadList($sessionId));
    }

    // The queries behind list().
    protected static function loadList(?int $sessionId): array
    {
        $orders = self::listQuery()
            ->when($sessionId !== null, fn ($query) => $query->where(self::TABLE . '.session_id', $sessionId))
            ->orderByDesc(self::TABLE . '.order_id')
            ->get(self::listColumns());

        return self::withItems($orders)->all();
    }

    // The orders with their customer, session and payment (one row each: a payment belongs to one order).
    protected static function listQuery(): Builder
    {
        return DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->leftJoin('users as verifiers', 'verifiers.id', '=', 'payments.verified_by')
            ->leftJoin('users as cancellers', 'cancellers.id', '=', self::TABLE . '.cancelled_by')
            ->whereNull(self::TABLE . '.deleted_at');
    }

    protected static function listColumns(): array
    {
        return [
            self::TABLE . '.order_id',
            self::TABLE . '.session_id',
            'order_sessions.order_date as session_date',
            'order_sessions.start_time as session_start',
            'order_sessions.end_time as session_end',
            self::TABLE . '.order_number',
            self::TABLE . '.order_date',
            self::TABLE . '.total_amount',
            self::TABLE . '.order_status',
            self::TABLE . '.payment_status',
            self::TABLE . '.ready_at',
            ...self::DISCOUNT_COLUMNS,
            DB::raw(User::fullNameSql('users') . ' as customer_name'),
            'payments.payment_method_id',
            'payments.reference_number',
            'payments.proof_of_payment',
            'payments.payment_status as payment_state',
            'payments.verified_at',
            'payment_methods.method_name',
            DB::raw(User::fullNameSql('verifiers') . ' as verified_by_name'),
            // Staff only (the customer's popup is built from userListQuery, which has none of these).
            self::TABLE . '.cancelled_at',
            self::TABLE . '.cancel_reason',
            DB::raw(User::fullNameSql('cancellers') . ' as cancelled_by_name'),
        ];
    }

    // ONE PAGE of the staff Orders table (DataTables in serverSide mode): the page's rows after the
    // filters, the search words and the sort, plus how many orders there are in all and after the
    // filters. $table is ServerTable::params(), $filters ServerTable::filters(). Never loads more than
    // one page, so thousands of orders cost the same as ten.
    public static function staffPage(array $table, array $filters): array
    {
        $total = DB::table(self::TABLE)->whereNull('deleted_at')->count();

        $narrowed = fn () => self::staffNarrowed(self::listQuery(), $table['words'], $filters);

        $filtered = ($table['words'] === [] && ! array_filter($filters)) ? $total : $narrowed()->count();

        // What the column index of the table sorts by (see orders.js); anything else = newest first.
        $sorts = [
            1 => [self::TABLE . '.order_number'],
            2 => [User::fullNameSql('users')],
            3 => ['order_sessions.order_date', 'order_sessions.start_time'],
            5 => [self::TABLE . '.total_amount'],
            7 => [self::TABLE . '.order_status'],
            8 => [self::TABLE . '.order_date'],
        ];

        $query = $narrowed();

        foreach ($sorts[$table['column']] ?? [self::TABLE . '.order_date'] as $expression) {
            $query->orderByRaw($expression . ' ' . $table['dir']);
        }

        $rows = $query
            ->orderByDesc(self::TABLE . '.order_id')
            ->offset($table['start'])
            ->limit($table['length'])
            ->get(self::listColumns());

        return ['rows' => self::withItems($rows)->all(), 'total' => $total, 'filtered' => $filtered];
    }

    // The search words and the page's filters applied to the staff orders query.
    protected static function staffNarrowed(Builder $query, array $words, array $filters): Builder
    {
        $query
            ->when($filters['status'] !== '', fn ($q) => $q->where(self::TABLE . '.order_status', $filters['status']))
            ->when($filters['payment'] !== '', fn ($q) => $q->where(self::TABLE . '.payment_status', $filters['payment']))
            ->when($filters['method'] > 0, fn ($q) => $q->where('payments.payment_method_id', $filters['method']))
            ->when($filters['session'] > 0, fn ($q) => $q->where(self::TABLE . '.session_id', $filters['session']))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate(self::TABLE . '.order_date', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate(self::TABLE . '.order_date', '<=', $filters['to']));

        ServerTable::search($query, $words, [
            self::TABLE . '.order_number',
            User::fullNameSql('users'),
            'order_sessions.order_date',
            'payment_methods.method_name',
            'payments.reference_number',
            self::TABLE . '.order_status',
            self::TABLE . '.payment_status',
            'CAST(' . self::TABLE . '.total_amount AS CHAR)',
            // A dish in the order's lines.
            fn ($any, $like) => $any->orWhereExists(fn ($lines) => $lines->selectRaw('1')
                ->from('order_items')
                ->join('session_dishes', 'session_dishes.session_dish_id', '=', 'order_items.session_dish_id')
                ->join('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
                ->whereColumn('order_items.order_id', self::TABLE . '.order_id')
                ->whereNull('order_items.deleted_at')
                ->whereRaw("LOWER(dishes.dish_name) LIKE ? ESCAPE '!'", [$like])),
        ]);

        return $query;
    }

    // What the method and session dropdowns of the Orders and Payments tables offer, now that the
    // browser no longer holds every row to build them from: every payment method, and the newest
    // sessions. Cached with its tables (no clock, no user in it).
    public static function staffFilterOptions(): array
    {
        return TableCache::remember(['payment_methods', 'order_sessions'], 'staff.filter_options', fn () => [
            'methods' => DB::table('payment_methods')->whereNull('deleted_at')->orderBy('method_name')->get(['method_id', 'method_name'])
                ->map(fn (object $method) => ['id' => (int) $method->method_id, 'name' => $method->method_name])->all(),
            'sessions' => DB::table('order_sessions')->whereNull('deleted_at')->orderByDesc('order_date')->orderByDesc('start_time')->limit(200)
                ->get(['session_id', 'order_date', 'start_time', 'end_time'])
                ->map(fn (object $session) => [
                    'id' => (int) $session->session_id,
                    'date' => substr((string) $session->order_date, 0, 10),
                    'start' => substr((string) $session->start_time, 0, 5),
                    'end' => substr((string) $session->end_time, 0, 5),
                ])->all(),
        ]);
    }

    // What the lists read about an order's discount. discount_proof is the PRIVATE file path:
    // Controller::orderRow() swaps it for the guarded proof URL before a row is sent out, and the
    // customer's popup never gets it.
    protected const DISCOUNT_COLUMNS = [
        self::TABLE . '.subtotal_amount',
        self::TABLE . '.discount_name',
        self::TABLE . '.discount_percent',
        self::TABLE . '.discount_amount',
        self::TABLE . '.discount_status',
        self::TABLE . '.discount_reject_reason',
        self::TABLE . '.discount_proof',
    ];

    // Orders on one page of a customer's own list (My Orders).
    public const USER_PAGE_SIZE = 10;

    // The customer's own list as a query: newest first, with the session they were placed
    // in and their payment. Shared by ofUser() and pageOfUser().
    protected static function userListQuery(int $userId): Builder
    {
        return DB::table(self::TABLE)
            ->join('order_sessions', 'order_sessions.session_id', '=', self::TABLE . '.session_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.user_id', $userId)
            ->whereNull(self::TABLE . '.deleted_at')
            ->orderByDesc(self::TABLE . '.order_id')
            ->select([
                self::TABLE . '.order_id',
                self::TABLE . '.order_number',
                self::TABLE . '.order_date',
                self::TABLE . '.total_amount',
                self::TABLE . '.order_status',
                self::TABLE . '.payment_status',
                self::TABLE . '.ready_at',
                ...self::DISCOUNT_COLUMNS,
                'order_sessions.order_date as session_date',
                'order_sessions.start_time',
                'order_sessions.end_time',
                'payments.reference_number',
                'payments.proof_of_payment',
                'payments.rejection_reason',
                'payments.payment_status as payment_state',
                'payment_methods.method_name',
            ]);
    }

    // One query for the lines of every order of the list, put on each order as `items`.
    protected static function withItems(Collection $orders): Collection
    {
        $items = OrderItem::itemsByOrder($orders->pluck('order_id')->all());

        return $orders->map(function (object $order) use ($items) {
            $order->items = $items[$order->order_id] ?? [];

            return $order;
        })->values();
    }

    // The orders one customer placed, newest first, with the session they were
    // placed in, their lines and their payment - the whole list.
    public static function ofUser(int $userId): array
    {
        return self::withItems(self::userListQuery($userId)->get())->all();
    }

    // The same list, one page at a time, for the customer's own page: only the orders
    // of the page get their lines read. $filters narrow it (see applyUserFilters()).
    public static function pageOfUser(int $userId, array $filters = [], int $perPage = self::USER_PAGE_SIZE): LengthAwarePaginator
    {
        $status = (string) ($filters['status'] ?? '');
        $payment = (string) ($filters['payment'] ?? '');
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage();

        // Only the plain views are cached (no search text, no dates, a known status, an early page):
        // anything a customer can type freely would make a cache file per keystroke.
        $cacheable = trim((string) ($filters['q'] ?? '')) === '' && empty($filters['from']) && empty($filters['to'])
            && ($status === '' || in_array($status, self::USER_ORDER_STATUSES, true))
            && ($payment === '' || in_array($payment, self::USER_PAYMENT_STATUSES, true))
            && $page <= 10;

        if (! $cacheable) {
            return self::loadPageOfUser($userId, $filters, $perPage);
        }

        return TableCache::remember(
            ['orders', 'order_sessions', 'payments', 'payment_methods', 'order_items', 'session_dishes', 'dishes'],
            'order.user.' . $userId . '.' . $status . '.' . $payment . '.' . $perPage . '.' . $page,
            fn () => self::loadPageOfUser($userId, $filters, $perPage)
        );
    }

    // The queries behind pageOfUser().
    protected static function loadPageOfUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = self::userListQuery($userId);
        self::applyUserFilters($query, $filters);

        $page = $query->paginate($perPage);

        return $page->setCollection(self::withItems($page->getCollection()));
    }

    // What the My Orders filter bar can ask for. Anything else in a filter is ignored, so a
    // hand-edited URL can only ever narrow the customer's OWN list (the user_id clause stays).
    //   q        text in the order number, a dish name, the payment method or the reference number
    //   status   an order status       payment   a payment status (the one on the order)
    //   from/to  the day the order was placed (Y-m-d, both ends included)
    public const USER_ORDER_STATUSES = ['pending', 'completed', 'cancelled'];
    public const USER_PAYMENT_STATUSES = ['pending', 'verified', 'rejected'];

    protected static function applyUserFilters(Builder $query, array $filters): void
    {
        $text = trim((string) ($filters['q'] ?? ''));

        if ($text !== '') {
            // % and _ typed by the customer are plain characters, not wildcards. "!" is the escape
            // character because a backslash inside a string literal means different things in
            // SQLite and MySQL.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';

            $query->where(function (Builder $match) use ($like) {
                $match->whereRaw(self::TABLE . ".order_number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("payments.reference_number LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("payment_methods.method_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereExists(function (Builder $dish) use ($like) {
                        $dish->selectRaw('1')
                            ->from('order_items')
                            ->join('session_dishes', 'session_dishes.session_dish_id', '=', 'order_items.session_dish_id')
                            ->join('dishes', 'dishes.dish_id', '=', 'session_dishes.dish_id')
                            ->whereColumn('order_items.order_id', self::TABLE . '.order_id')
                            ->whereNull('order_items.deleted_at')
                            ->whereRaw("dishes.dish_name LIKE ? ESCAPE '!'", [$like]);
                    });
            });
        }

        $status = (string) ($filters['status'] ?? '');

        if (in_array($status, self::USER_ORDER_STATUSES, true)) {
            $query->where(self::TABLE . '.order_status', $status);
        }

        $payment = (string) ($filters['payment'] ?? '');

        if (in_array($payment, self::USER_PAYMENT_STATUSES, true)) {
            $query->where(self::TABLE . '.payment_status', $payment);
        }

        // A date that is not a real Y-m-d day is dropped rather than failing the page.
        $from = self::dayOrNull($filters['from'] ?? null);
        $to = self::dayOrNull($filters['to'] ?? null);

        if ($from !== null) {
            $query->where(self::TABLE . '.order_date', '>=', $from . ' 00:00:00');
        }

        if ($to !== null) {
            $query->where(self::TABLE . '.order_date', '<=', $to . ' 23:59:59');
        }
    }

    // "2026-11-19" as given, or null when it is empty or not a real day.
    protected static function dayOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        $date = \DateTime::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    // The page of the customer's list that holds this order (1 = the newest), or null when it
    // is not theirs / does not exist. A notification links to "?order=ID" and this finds
    // the page, because an anchor alone cannot reach a card that is on another page.
    public static function pageNumberOf(int $userId, int $orderId, int $perPage = self::USER_PAGE_SIZE): ?int
    {
        $mine = DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $mine) {
            return null;
        }

        // Newest first by id: the orders above this one are the newer ones.
        $newer = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('order_id', '>', $orderId)
            ->count();

        return intdiv($newer, $perPage) + 1;
    }
    // Get one order, for the drawer that shows it.
    public static function findById(int $orderId): ?object
    {
        $order = DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->first();

        if (! $order) {
            return null;
        }

        $order->items = OrderItem::ofOrder($orderId);
        $order->payment = Payment::ofOrder($orderId);

        return $order;
    }

    // The status of a live order, or null when there is none.
    public static function statusOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('order_status');
    }

    // What a notification about an order needs, in one query: its number and
    // total, the customer, and the payment method.
    public static function notificationSummary(int $orderId): ?object
    {
        return DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->where(self::TABLE . '.order_id', $orderId)
            ->first([
                self::TABLE . '.order_id',
                self::TABLE . '.session_id',
                self::TABLE . '.user_id',
                self::TABLE . '.order_number',
                self::TABLE . '.total_amount',
                self::TABLE . '.discount_name',
                self::TABLE . '.discount_status',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payment_methods.method_name',
            ]);
    }

    // The same summary for several orders at once (one query), keyed by order id: what the
    // "ready for pick up" notification of a whole session needs.
    public static function notificationSummaries(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return DB::table(self::TABLE)
            ->join('users', 'users.id', '=', self::TABLE . '.user_id')
            ->leftJoin('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
            ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
            ->whereIn(self::TABLE . '.order_id', $orderIds)
            ->get([
                self::TABLE . '.order_id',
                self::TABLE . '.session_id',
                self::TABLE . '.user_id',
                self::TABLE . '.order_number',
                self::TABLE . '.total_amount',
                DB::raw(User::fullNameSql('users') . ' as customer_name'),
                'payment_methods.method_name',
            ])
            ->keyBy('order_id');
    }

    // When the order was announced as ready for pick up (null = not yet, or no such order).
    public static function readyAtOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('ready_at');
    }

    // Where the order's discount stands (none / pending / approved / rejected), or null when
    // there is no such order. A pending discount stops the payment from being verified and the
    // order from being completed or announced: staff decide the discount first.
    public static function discountStatusOf(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->value('discount_status');
    }

    // The order with its discount fields, read WITH a row lock: the first thing a discount decision
    // does inside its transaction (two reviewers pressing Approve / Reject together queue up and the
    // second sees what the first left). SQLite has no row locks and ignores the hint.
    public static function lockedDiscount(int $orderId): ?object
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['order_id', 'user_id', 'order_status', 'total_amount', 'subtotal_amount', 'discount_amount', 'discount_name', 'discount_percent', 'discount_status', 'discount_proof']);
    }

    // The customer sent a new ID for a REJECTED discount: it waits for staff again. The discount (a copy
    // of the percent the order was placed with) is applied back to the subtotal, the old decision is
    // cleared and the new picture replaces the old path. (The payment's amount is moved by the caller.)
    public static function reopenDiscount(int $orderId, string $proofPath, float $discountAmount, float $newTotal): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'discount_status' => 'pending',
                'discount_amount' => $discountAmount,
                'total_amount' => $newTotal,
                'discount_proof' => $proofPath,
                'discount_reject_reason' => null,
                'discount_decided_by' => null,
                'discount_decided_at' => null,
                'updated_at' => now(),
            ]);
    }

    // Approve the discount: the total stays as it was placed.
    public static function approveDiscount(int $orderId, int $reviewerId): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'discount_status' => 'approved',
                'discount_decided_by' => $reviewerId,
                'discount_decided_at' => now(),
                'discount_reject_reason' => null,
                'updated_at' => now(),
            ]);
    }

    // Reject the discount: no discount any more, so the order costs its full subtotal again.
    // (The payment's amount is moved with it by the caller.)
    public static function rejectDiscount(int $orderId, int $reviewerId, string $reason, float $newTotal): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update([
                'discount_status' => 'rejected',
                'discount_amount' => 0,
                'total_amount' => $newTotal,
                'discount_decided_by' => $reviewerId,
                'discount_decided_at' => now(),
                'discount_reject_reason' => $reason,
                'updated_at' => now(),
            ]);
    }

    // The status of a live order, read WITH a row lock. Call it first inside a
    // transaction: two staff members acting on the same order at the same time
    // then queue up, and the second one sees the status the first one left
    // (so a double Cancel cannot give the stock back twice). SQLite has no row
    // locks and ignores the hint; it serialises writers anyway.
    public static function lockedStatus(int $orderId): ?string
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->value('order_status');
    }

    // Check whether a live order exists.
    public static function existsById(int $orderId): bool
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // Check whether a session already has orders (then it cannot be deleted).
    public static function existsForSession(int $sessionId): bool
    {
        return DB::table(self::TABLE)
            ->where('session_id', $sessionId)
            ->whereNull('deleted_at')
            ->exists();
    }

    // The next order number: the day it was placed and a counter, e.g.
    // ORD-20261110-0004.
    public static function nextOrderNumber(): string
    {
        $prefix = 'ORD-' . now()->format('Ymd') . '-';

        $last = DB::table(self::TABLE)
            ->where('order_number', 'like', $prefix . '%')
            ->orderByDesc('order_number')
            ->value('order_number');

        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /* -------- Writes -------- */

    // Create an order and return its id.
    public static function createOrder(array $attributes): int
    {
        $now = now();

        return (int) DB::table(self::TABLE)->insertGetId(self::values($attributes) + [
            'order_date' => $attributes['order_date'] ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    // Set the order status; returns the number of changed rows. Completing and cancelling also
    // stamp when it happened (the analytics read it); a cancel records who did it and, when
    // staff typed one, why. $actorId and $reason only matter to a cancel.
    public static function updateStatus(int $orderId, string $status, ?int $actorId = null, ?string $reason = null): int
    {
        $now = now();

        $closing = match ($status) {
            'completed' => ['completed_at' => $now],
            'cancelled' => ['cancelled_at' => $now, 'cancelled_by' => $actorId, 'cancel_reason' => $reason],
            default => [],
        };

        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update($closing + [
                'order_status' => $status,
                // A cancelled order is closed, so it leaves the live lists.
                'is_active' => $status === 'cancelled' ? 0 : 1,
                'updated_at' => $now,
            ]);
    }

    // Set the payment status of the order (it mirrors the payments row).
    public static function updatePaymentStatus(int $orderId, string $status): int
    {
        return DB::table(self::TABLE)
            ->where('order_id', $orderId)
            ->whereNull('deleted_at')
            ->update(['payment_status' => $status, 'updated_at' => now()]);
    }

    // Announce orders as ready for pick up; returns the ids that were announced just now.
    // An order qualifies when it is pending, was not announced before and its payment is
    // settled the way completing needs it (verified, or cash that is still to be paid at the
    // counter) - the same rule as Controller::orderRow()'s complete_url. The rest are skipped,
    // so a whole session can be passed in. The qualifying rows are locked first, so two
    // people pressing the button together announce (and notify) each order once.
    public static function announceReady(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));

        if ($orderIds === []) {
            return [];
        }

        return DB::transaction(function () use ($orderIds) {
            $ids = DB::table(self::TABLE)
                ->join('payments', 'payments.order_id', '=', self::TABLE . '.order_id')
                ->leftJoin('payment_methods', 'payment_methods.method_id', '=', 'payments.payment_method_id')
                ->whereIn(self::TABLE . '.order_id', $orderIds)
                ->where(self::TABLE . '.order_status', 'pending')
                ->whereNull(self::TABLE . '.ready_at')
                ->whereNull(self::TABLE . '.deleted_at')
                ->whereNull('payments.deleted_at')
                // A discount still waiting for staff: the price is not final, so nothing is announced.
                ->where(self::TABLE . '.discount_status', '!=', 'pending')
                ->where(function (Builder $settled) {
                    $settled->where('payments.payment_status', 'verified')
                        ->orWhere(fn (Builder $cash) => $cash
                            ->where('payments.payment_status', 'pending')
                            ->where('payment_methods.method_name', PaymentMethod::CASH_ON_PICKUP));
                })
                ->lockForUpdate()
                ->pluck(self::TABLE . '.order_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($ids !== []) {
                DB::table(self::TABLE)
                    ->whereIn('order_id', $ids)
                    ->update(['ready_at' => now(), 'updated_at' => now()]);
            }

            return $ids;
        });
    }

    /* -------- Internals -------- */

    // Keep only the writable columns, and store is_active as 0 or 1.
    protected static function values(array $attributes): array
    {
        $values = Arr::only($attributes, self::COLUMNS);

        if (array_key_exists('is_active', $values)) {
            $values['is_active'] = (int) (bool) $values['is_active'];
        }

        return $values;
    }
}
