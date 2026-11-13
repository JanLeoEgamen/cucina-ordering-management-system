<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view users', only: ['index']),
            new Middleware('permission:create users', only: ['store']),
            new Middleware('permission:edit users', only: ['edit', 'update']),
            new Middleware('permission:delete users', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(User::getAll())
                ->skipPaging()
                ->addColumn('roles', function (object $user) {
                    $names = $user->role_names ?? [];

                    if ($names === []) {
                        return '<span class="text-gray-400 dark:text-gray-500">' . e(__('No role')) . '</span>';
                    }

                    return '<span class="inline-flex flex-wrap items-center gap-1">'
                        . implode('', array_map(fn (string $name) => '<span class="inline-flex items-center rounded-md border border-gray-200 px-2 py-0.5 text-xs font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">' . e($name) . '</span>', $names))
                        . '</span>';
                })
                ->addColumn('action', function (object $user) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view users')) {
                        // Read only: it loads the same record the edit drawer does.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $user->id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this user.')) . '"'
                            . ' data-view-url="' . e(route('users.edit', $user->id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('edit users')) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $user->id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('users.edit', $user->id)) . '"'
                            . ' data-update-url="' . e(route('users.update', $user->id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete users')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $user->id . '"'
                            . ' data-delete-url="' . e(route('users.destroy', $user->id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['roles', 'action'])
                ->toJson();
        }

        return view('users.index', [
            'roleOptions' => Role::options(),
        ]);
    }

    // Rules shared by create and update.
    protected function validateUser(Request $request): array
    {
        $userId = $request->route('id');

        $request->merge([
            'roles' => array_map('intval', (array) $request->input('roles', [])),
        ]);

        // A blank password on the edit form means "keep the stored one".
        if (! $request->filled('password')) {
            $request->merge(['password' => null]);
        }

        return $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Required while creating, optional while editing.
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ], [
            'roles.required' => 'Pick at least one role.',
            'roles.min' => 'Pick at least one role.',
        ]);
    }

    // Get one user, for the edit and view drawers.
    public function edit($id)
    {
        $user = User::findById((int) $id);

        if (! $user) {
            return $this->errorResponse('User not found.', 404);
        }

        return response()->json($user);
    }

    // Create a user with their roles.
    public function store(Request $request)
    {
        $validated = $this->validateUser($request);

        User::createUser($validated, $validated['roles']);

        return $this->successResponse('User created successfully.');
    }

    // Update a user and replace their roles.
    public function update(Request $request, $id)
    {
        if (! User::existsById((int) $id)) {
            return $this->errorResponse('User not found.', 404);
        }

        $validated = $this->validateUser($request);

        User::updateUser((int) $id, $validated, $validated['roles']);

        return $this->successResponse('User updated successfully.');
    }

    // Soft delete a user. Refused for your own account.
    public function destroy(Request $request, $id)
    {
        if (! User::existsById((int) $id)) {
            return $this->errorResponse('User not found.', 404);
        }

        if ((int) $id === (int) $request->user()->id) {
            return $this->errorResponse('You cannot delete your own account.', 422);
        }

        User::deleteUser((int) $id);

        return $this->successResponse('User deleted successfully.');
    }
}