<?php

namespace App\Http\Controllers;

use App\Models\Dish;
use App\Models\DishType;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class DishTypeController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view dish-types', only: ['index']),
            new Middleware('permission:create dish-types', only: ['store']),
            new Middleware('permission:edit dish-types', only: ['edit', 'update']),
            new Middleware('permission:delete dish-types', only: ['destroy']),
        ];
    }

    // The list behind the "Manage Types" drawer on the Dish Masterlist page.
    // There is no page of its own: an AJAX request gets the feed, anything else
    // (an old bookmark) is sent to the masterlist.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(DishType::getAll())
                ->skipPaging()
                ->addColumn('is_active', fn (object $dishType) => $this->statusBadge((bool) $dishType->is_active))
                ->addColumn('action', function (object $dishType) {
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    // `data-type-action` (not `data-row-action`) keeps these clear of the dishes
                    // table's click handler in resources/js/pages/dishes.js.
                    if (auth()->user()->can('edit dish-types')) {
                        $buttons .= '<button type="button" data-type-action="edit"'
                            . ' data-id="' . $dishType->dish_type_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('dish-types.edit', $dishType->dish_type_id)) . '"'
                            . ' data-update-url="' . e(route('dish-types.update', $dishType->dish_type_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete dish-types')) {
                        $buttons .= '<button type="button" data-type-action="delete"'
                            . ' data-id="' . $dishType->dish_type_id . '"'
                            . ' data-delete-url="' . e(route('dish-types.destroy', $dishType->dish_type_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['is_active', 'action'])
                ->toJson();
        }

        return redirect()->route('dishes.index');
    }

    // Rules and normalisation shared by create and update.
    protected function validateDishType(Request $request): array
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'type_name' => [
                'required',
                'string',
                'max:255',
                // The route key is a raw id (null while creating), and the
                // primary key column has to be named explicitly.
                Rule::unique('dish_types', 'type_name')->ignore($request->route('id'), 'dish_type_id'),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    // Get one dish type, for the edit drawer.
    public function edit($id)
    {
        $dishType = DishType::findById((int) $id);

        if (! $dishType) {
            return $this->errorResponse('Dish type not found.', 404);
        }

        // The view drawer shows the same badge the table renders, so its markup
        // is not written a second time in JavaScript.
        $dishType->status = $this->statusBadge((bool) $dishType->is_active);

        return response()->json($dishType);
    }

    // Create a dish type.
    public function store(Request $request)
    {
        DishType::createDishType($this->validateDishType($request));

        return $this->successResponse('Dish type created successfully.');
    }

    // Update a dish type.
    public function update(Request $request, $id)
    {
        if (! DishType::existsById((int) $id)) {
            return $this->errorResponse('Dish type not found.', 404);
        }

        DishType::updateDishType((int) $id, $this->validateDishType($request));

        return $this->successResponse('Dish type updated successfully.');
    }

    // Soft delete a dish type. Refused while it still has live dishes.
    public function destroy($id)
    {
        if (! DishType::existsById((int) $id)) {
            return $this->errorResponse('Dish type not found.', 404);
        }

        if (Dish::existsForDishType((int) $id)) {
            return $this->errorResponse('Cannot delete this dish type because it has associated dishes.', 422);
        }

        DishType::deleteDishType((int) $id);

        return $this->successResponse('Dish type deleted successfully.');
    }
}
