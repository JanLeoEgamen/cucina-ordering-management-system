<?php

namespace App\Http\Controllers;

use App\Exceptions\DishGoneException;
use App\Models\Dish;
use App\Models\DishType;
use App\Support\DishImport;
use App\Support\OrderNotifier;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Yajra\DataTables\Facades\DataTables;

class DishController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view dishes', only: ['index']),
            new Middleware('permission:create dishes', only: ['store', 'importTemplate', 'import']),
            // Reading a spreadsheet is the heaviest thing this page does: a few files a minute are plenty.
            new Middleware('throttle:20,1', only: ['import']), // a check and a confirm are two requests
            new Middleware('permission:edit dishes', only: ['edit', 'update']),
            new Middleware('permission:delete dishes', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(Dish::getAll())
                ->skipPaging()
                ->addColumn('is_active', fn (object $dish) => $this->statusBadge((bool) $dish->is_active))
                ->addColumn('action', function (object $dish) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view dishes')) {
                        // Read only: it loads the same record the edit drawer does.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $dish->dish_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this dish.')) . '"'
                            . ' data-view-url="' . e(route('dishes.edit', $dish->dish_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('edit dishes')) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $dish->dish_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('dishes.edit', $dish->dish_id)) . '"'
                            . ' data-update-url="' . e(route('dishes.update', $dish->dish_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete dishes')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $dish->dish_id . '"'
                            . ' data-delete-url="' . e(route('dishes.destroy', $dish->dish_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['is_active', 'action'])
                ->toJson();
        }

        return view('dishes.index', [
            // The drawer's picker offers the available types only.
            'dishTypes' => DishType::activeOptions(),
            // The filter lists every live type, so a dish tagged with a type
            // that was turned off can still be found.
            'dishTypeFilters' => DishType::getAll(),
        ]);
    }

    // Get one dish, for the edit drawer.
    public function edit($id)
    {
        $dish = Dish::findById((int) $id);

        if (! $dish) {
            return $this->errorResponse('Dish not found.', 404);
        }

        // Return the stored image as a public URL so the edit drawer can preview
        // it. Rows that already hold an absolute URL (fixtures, factories) are
        // left alone, exactly like the table feed does it.
        if ($dish->image && ! str_starts_with($dish->image, 'http')) {
            $dish->image = Storage::disk('public')->url($dish->image);
        }

        // Types travel with the record: the edit drawer ticks the boxes and the
        // view drawer prints the chips.
        $dish->dish_types = Dish::typesOfDish((int) $id);

        // The view drawer shows the same badge the table renders, so its markup
        // is not written a second time in JavaScript.
        $dish->status = $this->statusBadge((bool) $dish->is_active);

        return response()->json($dish);
    }

    // Create a dish.
    public function store(Request $request)
    {
        $validated = $this->validateDish($request);
        $validated['image'] = $this->storeUploadedImage($request->file('image'));

        $dishId = Dish::createDish($validated);

        // After the save; the people picked in System Configuration (New dish alerts) are told.
        OrderNotifier::dishAdded($dishId);

        return $this->successResponse('Dish created successfully.');
    }

    // The Excel template of the import: the instructions, the Dish Types table (the available types are
    // already in it; new ones are typed below) and the Dishes table whose type dropdown reads from it.
    // `?current=1` fills the Dishes table with the menu as it is now (with an ID column), to be edited and
    // sent back with "Add new and update existing"; that reads the whole menu, so it also needs `view dishes`.
    public function importTemplate(Request $request)
    {
        $current = $request->boolean('current');

        abort_if($current && ! $request->user()->can('view dishes'), 403);

        $book = DishImport::template(DishType::activeOptions(), $current ? Dish::forImport() : null);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $current ? 'dish-menu.xlsx' : 'dish-import-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    // Import dishes (and their dish types) from an Excel file. All or nothing: any problem in any row
    // stores nothing and answers 422 with one message per row, so the file can be fixed and sent again.
    // `mode` = add (default: dishes already on the menu are skipped), update (they are updated too, which
    // also needs `edit dishes`) or replace (the file becomes the whole menu: dishes it leaves out are
    // deleted, which also needs `delete dishes`). `preview` = only check the file and say what it WOULD do;
    // nothing is stored.
    public function import(Request $request)
    {
        // The extension is checked here; whether it really is a workbook is what reading it tells.
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'max:2048'],
            'mode' => ['nullable', 'in:' . DishImport::MODE_ADD . ',' . DishImport::MODE_UPDATE . ',' . DishImport::MODE_REPLACE],
            'preview' => ['nullable', 'boolean'],
        ], [
            'file.required' => 'Choose the Excel file to import.',
            'file.extensions' => 'The file must be an Excel workbook (.xlsx). Download the template to start.',
            'file.max' => 'The file may not be larger than 2 MB.',
        ]);

        $mode = in_array($request->input('mode'), [DishImport::MODE_UPDATE, DishImport::MODE_REPLACE], true)
            ? $request->input('mode')
            : DishImport::MODE_ADD;

        // Changing a dish that exists is editing it, so it needs the edit permission on top of create;
        // replacing the menu also deletes the dishes the file leaves out, so it needs the delete permission too.
        abort_if($mode !== DishImport::MODE_ADD && ! $request->user()->can('edit dishes'), 403);
        abort_if($mode === DishImport::MODE_REPLACE && ! $request->user()->can('delete dishes'), 403);

        // Adding dish types is its own permission: a person with only `create dishes` may import dishes
        // that use the existing types, but not make the file invent new ones.
        $result = DishImport::read($request->file('file')->getRealPath(), (bool) $request->user()->can('create dish-types'), $mode);

        if ($result['errors']) {
            throw ValidationException::withMessages(['file' => $result['errors']]);
        }

        if ($request->boolean('preview')) {
            return $this->successResponse(DishImport::summary($result, false));
        }

        try {
            DishImport::store($result);
        } catch (DishGoneException) {
            throw ValidationException::withMessages(['file' => ['A dish in the file was deleted while it was being imported. Nothing was imported: download the template again.']]);
        }

        return $this->successResponse(DishImport::summary($result, true));
    }

    // Update a dish.
    public function update(Request $request, $id)
    {
        if (! Dish::existsById((int) $id)) {
            return $this->errorResponse('Dish not found.', 404);
        }

        $validated = $this->validateDish($request);

        $previousImage = Dish::findById((int) $id)->image ?? null;

        // A new file replaces the old one; the X on the drawer asks removal.
        if ($request->hasFile('image')) {
            $validated['image'] = $this->storeUploadedImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $validated['image'] = null;
        }

        $updated = Dish::updateDish((int) $id, $validated);

        // Drop the old file only once the row no longer points at it.
        if ($updated > 0 && array_key_exists('image', $validated)) {
            $this->deleteImage($previousImage);
        }

        return $this->successResponse('Dish updated successfully.');
    }

    // Soft delete a dish.
    public function destroy($id)
    {
        if (! Dish::existsById((int) $id)) {
            return $this->errorResponse('Dish not found.', 404);
        }

        $image = Dish::findById((int) $id)->image ?? null;

        Dish::deleteDish((int) $id);
        $this->deleteImage($image);

        return $this->successResponse('Dish deleted successfully.');
    }

    // Validation and normalisation shared by create and update.
    protected function validateDish(Request $request): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            // Does the plate normally come with rice? (Ala carte can only take rice off such a dish.)
            'includes_rice' => $request->boolean('includes_rice'),
            'remove_image' => $request->boolean('remove_image'),
        ]);

        return $request->validate([
            'dish_name' => ['required', 'string', 'max:255'],
            'dish_types' => ['required', 'array', 'min:1'],
            'dish_types.*' => ['integer', 'exists:dish_types,dish_type_id'],
            'description' => ['nullable', 'string'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'remove_image' => ['boolean'],
            'price' => ['required', 'numeric', 'min:0'],
            'calories' => ['nullable', 'integer', 'min:0'],
            'main_ingredients' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'includes_rice' => ['required', 'boolean'],
        ], [
            'dish_types.required' => 'Pick at least one dish type.',
            'dish_types.min' => 'Pick at least one dish type.',
        ]);
    }

    // Store an uploaded image on the public disk and return its relative path.
    protected function storeUploadedImage(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('dishes', 'public');
    }

    // Delete an image from the public disk when it is no longer referenced.
    protected function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
