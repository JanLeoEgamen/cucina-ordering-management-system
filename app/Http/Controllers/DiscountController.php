<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

// The Discounts page: what a customer can pick at checkout (name + percent + proof rule + dates).
// Who is offered them, and for which kind of order, is System Configuration -> Discounts.
class DiscountController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view discounts', only: ['index', 'edit']),
            new Middleware('permission:create discounts', only: ['store']),
            new Middleware('permission:edit discounts', only: ['update']),
            new Middleware('permission:delete discounts', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(Discount::getAll())
                ->skipPaging()
                // Active / Inactive, plus Scheduled and Expired for a discount outside its dates.
                ->addColumn('status', fn (object $discount) => $this->discountBadge($discount))
                ->addColumn('proof', fn (object $discount) => e($discount->requires_proof ? __('Required') : __('Not needed')))
                ->addColumn('window', fn (object $discount) => e($this->windowText($discount)))
                ->addColumn('used', fn (object $discount) => (int) $discount->orders_count + (int) $discount->pre_orders_count)
                ->addColumn('action', function (object $discount) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    if (auth()->user()->can('view discounts')) {
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $discount->discount_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this discount.')) . '"'
                            . ' data-view-url="' . e(route('discounts.edit', $discount->discount_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('edit discounts')) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $discount->discount_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('discounts.edit', $discount->discount_id)) . '"'
                            . ' data-update-url="' . e(route('discounts.update', $discount->discount_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete discounts')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $discount->discount_id . '"'
                            . ' data-delete-url="' . e(route('discounts.destroy', $discount->discount_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                // The name goes out as typed: resources/js/pages/discounts.js escapes it once.
                ->rawColumns(['discount_name', 'status', 'proof', 'window', 'action'])
                ->toJson();
        }

        return view('discounts.index');
    }

    // Get one discount, for the edit and view drawers.
    public function edit($id)
    {
        $discount = Discount::findById((int) $id);

        if (! $discount) {
            return $this->errorResponse('Discount not found.', 404);
        }

        // The drawers fill <input type="date"> and print the badge as the table does.
        $discount->valid_from = $discount->valid_from ? substr((string) $discount->valid_from, 0, 10) : null;
        $discount->valid_until = $discount->valid_until ? substr((string) $discount->valid_until, 0, 10) : null;
        $discount->status = $this->discountBadge($discount);
        $discount->window = $this->windowText($discount);
        $discount->proof = $discount->requires_proof ? __('Required') : __('Not needed');

        return response()->json($discount);
    }

    // Create a discount.
    public function store(Request $request)
    {
        Discount::createDiscount($this->validateDiscount($request));

        return $this->successResponse('Discount created successfully.');
    }

    // Update a discount. Orders that already used it keep their own copy of the name and percent.
    public function update(Request $request, $id)
    {
        if (! Discount::existsById((int) $id)) {
            return $this->errorResponse('Discount not found.', 404);
        }

        Discount::updateDiscount((int) $id, $this->validateDiscount($request));

        return $this->successResponse('Discount updated successfully.');
    }

    // Soft delete a discount. Always allowed: orders keep their own copy, so nothing breaks.
    public function destroy($id)
    {
        if (! Discount::existsById((int) $id)) {
            return $this->errorResponse('Discount not found.', 404);
        }

        Discount::deleteDiscount((int) $id);

        return $this->successResponse('Discount deleted successfully.');
    }

    // The status pill of a discount: its switch, or the window it is outside of.
    protected function discountBadge(object $discount): string
    {
        $state = Discount::stateOf($discount);

        return $this->badge($state, __(ucfirst($state)));
    }

    // "Nov 1, 2026 – Nov 30, 2026", "From Nov 1, 2026", "Until Nov 30, 2026" or "Always".
    protected function windowText(object $discount): string
    {
        $format = fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('M j, Y');

        return match (true) {
            $discount->valid_from && $discount->valid_until => $format($discount->valid_from) . ' – ' . $format($discount->valid_until),
            (bool) $discount->valid_from => __('From :date', ['date' => $format($discount->valid_from)]),
            (bool) $discount->valid_until => __('Until :date', ['date' => $format($discount->valid_until)]),
            default => __('Always'),
        };
    }

    // Rules and normalisation shared by create and update.
    protected function validateDiscount(Request $request): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'requires_proof' => $request->boolean('requires_proof'),
            // An empty date input posts "": that means no limit, not an invalid date.
            'valid_from' => $request->input('valid_from') ?: null,
            'valid_until' => $request->input('valid_until') ?: null,
        ]);

        return $request->validate([
            'discount_name' => [
                'required',
                'string',
                'max:255',
                // A deleted discount does not hold its name: the name can be used again.
                Rule::unique('discounts', 'discount_name')
                    ->whereNull('deleted_at')
                    ->ignore($request->route('id'), 'discount_id'),
            ],
            // 100 is allowed (a free order); more is not a discount.
            'percent' => ['required', 'numeric', 'between:0.01,100', 'decimal:0,2'],
            'requires_proof' => ['required', 'boolean'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'is_active' => ['required', 'boolean'],
        ], [
            'percent.between' => 'The percent must be between 0.01 and 100.',
            'percent.decimal' => 'The percent can have at most 2 decimal places.',
            'valid_until.after_or_equal' => 'The last day cannot be before the first day.',
        ]);
    }
}
