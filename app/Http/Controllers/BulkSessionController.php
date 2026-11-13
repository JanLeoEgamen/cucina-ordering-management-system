<?php

namespace App\Http\Controllers;

use App\Models\BulkOrder;
use App\Models\BulkSession;
use App\Models\Dish;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

// Bulk sessions, the staff side: the windows in which customers may place big
// orders from the bulk menu. Same shape as OrderSessionController, minus the
// stock (a bulk session has none) and plus a minimum quantity per order.
class BulkSessionController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view bulk-sessions', only: ['index', 'show']),
            new Middleware('permission:create bulk-sessions', only: ['store']),
            new Middleware('permission:edit bulk-sessions', only: ['edit', 'update']),
            new Middleware('permission:delete bulk-sessions', only: ['destroy']),
            new Middleware('permission:end bulk-sessions', only: ['end']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(BulkSession::getAll())
                ->skipPaging()
                ->addColumn('status', fn (object $session) => $this->sessionBadge($session->status))
                ->addColumn('action', function (object $session) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $end = 'mx-0.5 rounded-full border border-amber-200 px-3 py-1.5 font-medium text-amber-600 dark:border-amber-800 transition hover:bg-amber-50 hover:text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300 dark:text-amber-400 dark:hover:bg-amber-900/30 dark:hover:text-amber-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view bulk-sessions')) {
                        $buttons .= '<a href="' . e(route('bulk-sessions.show', $session->bulk_session_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</a>';
                    }

                    if (auth()->user()->can('edit bulk-sessions')) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . (int) $session->bulk_session_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('bulk-sessions.edit', $session->bulk_session_id)) . '"'
                            . ' data-update-url="' . e(route('bulk-sessions.update', $session->bulk_session_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    // Ending is the creator's call, so the button is theirs too.
                    if ($this->canEnd($session)) {
                        $buttons .= '<button type="button" data-row-action="end"'
                            . ' data-id="' . (int) $session->bulk_session_id . '"'
                            . ' data-subject="' . e($session->order_date) . '"'
                            . ' data-end-url="' . e(route('bulk-sessions.end', $session->bulk_session_id)) . '"'
                            . ' class="' . $end . '">' . e(__('End')) . '</button>';
                    }

                    if (auth()->user()->can('delete bulk-sessions')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . (int) $session->bulk_session_id . '"'
                            . ' data-delete-url="' . e(route('bulk-sessions.destroy', $session->bulk_session_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                // The title is typed by staff: DataTables escapes every column that is
                // not listed as raw, so it is safe as it is.
                ->rawColumns(['status', 'action'])
                ->toJson();
        }

        return view('bulk-sessions.index', [
            // The dish list in the create and edit drawers offers every live dish.
            'dishes' => Dish::getAll(),
            // The times a new session starts with (System Configuration): the same
            // defaults as an order session.
            'sessionWindow' => Setting::sessionWindow(),
        ]);
    }

    // One bulk session on a page of its own: its facts and the dishes it offers.
    // (Its orders join this page with the order lifecycle.)
    public function show($id)
    {
        $session = BulkSession::findById((int) $id);

        if (! $session) {
            abort(404);
        }

        return view('bulk-sessions.show', [
            'session' => $session,
            'statusBadge' => $this->sessionBadge($session->status),
            'canEnd' => $this->canEnd($session),
            'ordersCount' => (int) (BulkSession::getAll()
                ->firstWhere('bulk_session_id', (int) $id)->orders_count ?? 0),
        ]);
    }

    // Get one bulk session with its dishes, for the edit drawer.
    public function edit($id)
    {
        $session = BulkSession::findById((int) $id);

        if (! $session) {
            return $this->errorResponse('Bulk session not found.', 404);
        }

        return response()->json($session);
    }

    // Create a bulk session with the dishes it offers.
    public function store(Request $request)
    {
        $validated = $this->validateSession($request);

        $this->refuseOverlap($validated);

        BulkSession::createSession(
            $validated + ['created_by' => auth()->id()],
            $validated['dish_ids']
        );

        return $this->successResponse('Bulk session created successfully.');
    }

    // Update a bulk session and replace the dishes it offers.
    public function update(Request $request, $id)
    {
        $session = BulkSession::findById((int) $id);

        if (! $session) {
            return $this->errorResponse('Bulk session not found.', 404);
        }

        $validated = $this->validateSession($request);

        // An ended session is history: it blocks nothing, so it needs no check.
        if ($session->status !== 'ended') {
            $this->refuseOverlap($validated, (int) $id);
        }

        BulkSession::updateSession((int) $id, $validated, $validated['dish_ids']);

        return $this->successResponse('Bulk session updated successfully.');
    }

    // End a bulk session by hand. Only the user who created it may do this.
    public function end($id)
    {
        $session = BulkSession::findById((int) $id);

        if (! $session) {
            return $this->errorResponse('Bulk session not found.', 404);
        }

        if ($session->status === 'ended') {
            return $this->errorResponse('This bulk session has already ended.', 422);
        }

        if (! $this->canEnd($session)) {
            return $this->errorResponse('Only the user who created this bulk session can end it.', 403);
        }

        BulkSession::endSession((int) $id);

        return $this->successResponse('Bulk session ended successfully.');
    }

    // Soft delete a bulk session. Refused while it holds orders: they are the
    // record of what was sold, and they point at the session's dishes.
    public function destroy($id)
    {
        if (! BulkSession::existsById((int) $id)) {
            return $this->errorResponse('Bulk session not found.', 404);
        }

        if (BulkOrder::existsForSession((int) $id)) {
            return $this->errorResponse('Cannot delete this bulk session because it has orders.', 422);
        }

        BulkSession::deleteSession((int) $id);

        return $this->successResponse('Bulk session deleted successfully.');
    }

    // Rules and normalisation shared by create and update.
    protected function validateSession(Request $request): array
    {
        $request->merge([
            'dish_ids' => array_map('intval', (array) $request->input('dish_ids', [])),
        ]);

        return $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'order_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'min_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'dish_ids' => ['required', 'array', 'min:1'],
            'dish_ids.*' => ['integer', 'exists:dishes,dish_id'],
        ], [
            'dish_ids.required' => 'Pick at least one dish.',
            'dish_ids.min' => 'Pick at least one dish.',
            'end_time.after' => 'The end time must be after the start time.',
            'min_quantity.min' => 'The minimum quantity must be at least 1.',
        ]);
    }

    // The bulk menu shows ONE live session, so a window may not overlap another
    // one that is still open. A validation error, so the drawer prints it like
    // any other field error (422).
    protected function refuseOverlap(array $validated, ?int $ignoreId = null): void
    {
        if (BulkSession::overlaps($validated['order_date'], $validated['start_time'], $validated['end_time'], $ignoreId)) {
            throw ValidationException::withMessages([
                'start_time' => 'Another bulk session is already open in this time window.',
            ]);
        }
    }

    // Only the user who created the session may end it, and only while it is
    // still open (scheduled or live).
    protected function canEnd(object $session): bool
    {
        return $session->status !== 'ended'
            && (int) $session->created_by === (int) auth()->id()
            && auth()->user()->can('end bulk-sessions');
    }
}
