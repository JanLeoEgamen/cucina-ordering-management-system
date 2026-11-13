<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Yajra\DataTables\Facades\DataTables;

class OrderSessionController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view order-sessions', only: ['index', 'show']),
            new Middleware('permission:create order-sessions', only: ['store']),
            new Middleware('permission:edit order-sessions', only: ['edit', 'update']),
            new Middleware('permission:delete order-sessions', only: ['destroy']),
            new Middleware('permission:end order-sessions', only: ['end']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(OrderSession::getAll())
                ->skipPaging()
                ->addColumn('status', fn (object $session) => $this->sessionBadge($session->status))
                ->addColumn('action', function (object $session) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $end = 'mx-0.5 rounded-full border border-amber-200 px-3 py-1.5 font-medium text-amber-600 dark:border-amber-800 transition hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300 dark:text-amber-400 dark:hover:bg-amber-900/30 dark:hover:text-amber-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view order-sessions')) {
                        // Read only, on a page of its own: the session, its stock
                        // and its orders (see show()).
                        $buttons .= '<a href="' . e(route('order-sessions.show', $session->session_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</a>';
                    }

                    if (auth()->user()->can('edit order-sessions')) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $session->session_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('order-sessions.edit', $session->session_id)) . '"'
                            . ' data-update-url="' . e(route('order-sessions.update', $session->session_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    // Ending is the creator's call, so the button is theirs too.
                    if ($this->canEnd($session)) {
                        $buttons .= '<button type="button" data-row-action="end"'
                            . ' data-id="' . $session->session_id . '"'
                            . ' data-subject="' . e($session->order_date) . '"'
                            . ' data-end-url="' . e(route('order-sessions.end', $session->session_id)) . '"'
                            . ' class="' . $end . '">' . e(__('End')) . '</button>';
                    }

                    if (auth()->user()->can('delete order-sessions')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $session->session_id . '"'
                            . ' data-delete-url="' . e(route('order-sessions.destroy', $session->session_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['status', 'action'])
                ->toJson();
        }

        return view('order-sessions.index', [
            // The dish list in the create and edit drawers offers every live dish.
            'dishes' => Dish::getAll(),
            // The times a new session starts with (System Configuration).
            'sessionWindow' => Setting::sessionWindow(),
        ]);
    }

    // One session on a page of its own: its facts, the stock it offers and, in
    // a table (or cards), every order placed in it. The same URL answers the
    // orders table's AJAX request with its feed.
    public function show(Request $request, $id)
    {
        $session = OrderSession::findById((int) $id);

        if (! $session) {
            return $request->ajax()
                ? $this->errorResponse('Order session not found.', 404)
                : abort(404);
        }

        // Each row is decorated for the order popup (badges, proof URL, action URLs): the
        // same decoration the Orders page uses, so both pages offer the same actions.
        $orders = collect(Order::ofSession((int) $id))->map(fn (object $order) => $this->orderRow($order));

        if ($request->ajax()) {
            return DataTables::collection($orders)
                ->skipPaging()
                ->rawColumns(['status_badge', 'payment_badge'])
                ->toJson();
        }

        // A cancelled order is not sales.
        $sold = $orders->reject(fn (object $order) => $order->order_status === 'cancelled');

        return view('order-sessions.show', [
            'session' => $session,
            'statusBadge' => $this->sessionBadge($session->status),
            'canEnd' => $this->canEnd($session),
            'ordersCount' => $orders->count(),
            'itemsSold' => (int) $sold->sum('items_count'),
            'salesTotal' => (float) $sold->sum('total_amount'),
        ]);
    }

    // Get one session with its dishes, for the edit drawer.
    public function edit($id)
    {
        $session = OrderSession::findById((int) $id);

        if (! $session) {
            return $this->errorResponse('Order session not found.', 404);
        }

        return response()->json($session);
    }

    // Create a session with the dishes it offers and their stock.
    public function store(Request $request)
    {
        $validated = $this->validateSession($request);

        OrderSession::createSession(
            $validated + ['created_by' => auth()->id()],
            $this->postedStocks($request, $validated['dish_ids'])
        );

        return $this->successResponse('Order session created successfully.');
    }

    // Update a session and replace the dishes it offers.
    public function update(Request $request, $id)
    {
        if (! OrderSession::existsById((int) $id)) {
            return $this->errorResponse('Order session not found.', 404);
        }

        $validated = $this->validateSession($request);

        OrderSession::updateSession(
            (int) $id,
            $validated,
            $this->postedStocks($request, $validated['dish_ids']),
            $this->postedLoadedStocks($request, $validated['dish_ids'])
        );

        return $this->successResponse('Order session updated successfully.');
    }

    // End a session by hand. Only the user who created it may do this.
    public function end($id)
    {
        $session = OrderSession::findById((int) $id);

        if (! $session) {
            return $this->errorResponse('Order session not found.', 404);
        }

        if ($session->status === 'ended') {
            return $this->errorResponse('This order session has already ended.', 422);
        }

        if (! $this->canEnd($session)) {
            return $this->errorResponse('Only the user who created this order session can end it.', 403);
        }

        OrderSession::endSession((int) $id);

        return $this->successResponse('Order session ended successfully.');
    }

    // Soft delete a session. Refused while it holds orders: they are the record
    // of what was sold, and they point at the session's dishes.
    public function destroy($id)
    {
        if (! OrderSession::existsById((int) $id)) {
            return $this->errorResponse('Order session not found.', 404);
        }

        if (Order::existsForSession((int) $id)) {
            return $this->errorResponse('Cannot delete this order session because it has orders.', 422);
        }

        OrderSession::deleteSession((int) $id);

        return $this->successResponse('Order session deleted successfully.');
    }

    // Rules and normalisation shared by create and update.
    protected function validateSession(Request $request): array
    {
        $request->merge([
            'dish_ids' => array_map('intval', (array) $request->input('dish_ids', [])),
        ]);

        return $request->validate([
            'order_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'dish_ids' => ['required', 'array', 'min:1'],
            'dish_ids.*' => ['integer', 'exists:dishes,dish_id'],
            'stocks' => ['required', 'array'],
            'stocks.*' => ['nullable', 'integer', 'min:0'],
            // What each stock box showed when the edit form was opened.
            'loaded_stocks' => ['nullable', 'array'],
            'loaded_stocks.*' => ['nullable', 'integer', 'min:0'],
        ], [
            'dish_ids.required' => 'Pick at least one dish.',
            'dish_ids.min' => 'Pick at least one dish.',
            'end_time.after' => 'The end time must be after the start time.',
        ]);
    }

    // The stock picked for each dish of the session: [dish_id => available_stock].
    protected function postedStocks(Request $request, array $dishIds): array
    {
        $stocks = [];

        foreach ($dishIds as $dishId) {
            $stocks[(int) $dishId] = max(0, (int) $request->input('stocks.' . (int) $dishId, 0));
        }

        return $stocks;
    }

    // The stock each box showed when the edit form was opened: [dish_id => stock].
    // A dish with no loaded value (just added, or a create form) is left out, and
    // its typed stock is then taken as it is.
    protected function postedLoadedStocks(Request $request, array $dishIds): array
    {
        $loaded = [];

        foreach ($dishIds as $dishId) {
            $value = $request->input('loaded_stocks.' . (int) $dishId);

            if ($value !== null && $value !== '') {
                $loaded[(int) $dishId] = max(0, (int) $value);
            }
        }

        return $loaded;
    }

    // Only the user who created the session may end it, and only while it is
    // still open (scheduled or live).
    protected function canEnd(object $session): bool
    {
        return $session->status !== 'ended'
            && (int) $session->created_by === (int) auth()->id()
            && auth()->user()->can('end order-sessions');
    }
}
