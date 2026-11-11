<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class RoleController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view roles', only: ['index']),
            new Middleware('permission:create roles', only: ['store']),
            new Middleware('permission:edit roles', only: ['edit', 'update']),
            new Middleware('permission:delete roles', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(Role::getAll())
                ->skipPaging()
                ->addColumn('action', function (object $role) {
                    $view = 'rounded-lg px-2 py-1 font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'rounded-lg px-2 py-1 font-medium text-indigo-600 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'ml-2 rounded-lg px-2 py-1 font-medium text-red-600 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view roles')) {
                        // Read only: it loads the same record the edit drawer does.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $role->id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this role.')) . '"'
                            . ' data-view-url="' . e(route('roles.edit', $role->id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('edit roles') && ! $role->is_protected) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $role->id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('roles.edit', $role->id)) . '"'
                            . ' data-update-url="' . e(route('roles.update', $role->id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete roles') && ! $role->is_protected) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $role->id . '"'
                            . ' data-delete-url="' . e(route('roles.destroy', $role->id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['action'])
                ->toJson();
        }

        return view('roles.index', [
            'permissionOptions' => Role::permissionOptions(),
        ]);
    }

    // Rules shared by create and update.
    protected function validateRole(Request $request): array
    {
        // The picker posts nothing when every box is unticked, and a role with
        // no permission is allowed: it simply grants nothing.
        $request->merge([
            'permissions' => array_map('intval', (array) $request->input('permissions', [])),
        ]);

        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')->ignore($request->route('id')),
            ],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);
    }

    // Get one role, for the edit and view drawers.
    public function edit($id)
    {
        $role = Role::findOneById((int) $id);

        if (! $role) {
            return $this->errorResponse('Role not found.', 404);
        }

        return response()->json($role);
    }

    // Create a role with its permissions.
    public function store(Request $request)
    {
        $validated = $this->validateRole($request);

        Role::createRole($validated, $validated['permissions']);

        return $this->successResponse('Role created successfully.');
    }

    // Rename a role and replace its permissions. Protected roles are read only.
    public function update(Request $request, $id)
    {
        if (! Role::existsById((int) $id)) {
            return $this->errorResponse('Role not found.', 404);
        }

        if (Role::isProtected((int) $id)) {
            return $this->errorResponse('This role is protected and cannot be edited.', 422);
        }

        $validated = $this->validateRole($request);

        Role::updateRole((int) $id, $validated, $validated['permissions']);

        return $this->successResponse('Role updated successfully.');
    }

    // Delete a role. Refused for a protected role, and while a user still holds it.
    public function destroy($id)
    {
        if (! Role::existsById((int) $id)) {
            return $this->errorResponse('Role not found.', 404);
        }

        if (Role::isProtected((int) $id)) {
            return $this->errorResponse('This role is protected and cannot be deleted.', 422);
        }

        if (Role::isAssignedToUsers((int) $id)) {
            return $this->errorResponse('Cannot delete this role because it is still assigned to users.', 422);
        }

        Role::deleteRole((int) $id);

        return $this->successResponse('Role deleted successfully.');
    }
}