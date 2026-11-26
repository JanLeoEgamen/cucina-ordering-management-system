<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller implements HasMiddleware
{
    private const BEYOND_YOUR_ACCESS = 'You cannot change this account: it has access that you do not have.';

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
        $guard = AccessGuard::for($request->user());

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
                ->addColumn('action', function (object $user) use ($guard) {
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

                    // No Edit or Delete on an account with more access than yours.
                    $manageable = $guard->coversUser((int) $user->id);

                    if (auth()->user()->can('edit users') && $manageable) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $user->id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('users.edit', $user->id)) . '"'
                            . ' data-update-url="' . e(route('users.update', $user->id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    // No Delete button on a Super Admin row: destroy() would refuse it anyway.
                    if (auth()->user()->can('delete users') && $manageable && ! in_array(Role::SUPER_ADMIN, $user->role_names ?? [], true)) {
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

        $roleOptions = Role::options();

        return view('users.index', [
            // The table filter lists every role; the drawers only those you may give.
            'roleOptions' => $roleOptions,
            'grantableRoles' => $guard->grantableRoles($roleOptions),
            'companyOptions' => Company::allOptions(),
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
            // The user being edited keeps their own company valid even if it was switched off since.
            'company_id' => Company::rules($userId ? User::findById((int) $userId)->company_id ?? null : null),
            // Required for every company except Guest (the drawer says so; the server decides).
            'employee_id' => Company::employeeIdRules($request->input('company_id')),
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            // Required while creating, optional while editing.
            'password' => [$userId ? 'nullable' : 'required', 'string', 'min:8', 'confirmed'],
            'roles' => ['required', 'array', 'min:1', $this->rolesRule($request)],
            'roles.*' => ['integer', 'exists:roles,id'],
        ], [
            'roles.required' => 'Pick at least one role.',
            'roles.min' => 'Pick at least one role.',
        ]);
    }

    // Which roles may be posted: your own never change, and for anyone else only roles
    // whose access you have yourself (AccessGuard).
    protected function rolesRule(Request $request): Closure
    {
        return function (string $attribute, mixed $roleIds, Closure $fail) use ($request) {
            $userId = (int) $request->route('id');

            if ($userId === (int) $request->user()->id) {
                $current = User::roleIdsOfUser($userId);

                if (array_diff($roleIds, $current) !== [] || array_diff($current, $roleIds) !== []) {
                    $fail(__('You cannot change your own roles. Ask another administrator.'));
                }

                return;
            }

            $guard = AccessGuard::for($request->user());

            foreach ($roleIds as $roleId) {
                if (! $guard->coversRole($roleId)) {
                    $fail(__('You can only give a role whose access you have yourself.'));

                    return;
                }
            }
        };
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
        $validated['employee_id'] = Company::employeeIdFor((int) $validated['company_id'], $validated['employee_id'] ?? null);

        User::createUser($validated, $validated['roles']);

        return $this->successResponse('User created successfully.');
    }

    // Update a user and replace their roles.
    public function update(Request $request, $id)
    {
        if (! User::existsById((int) $id)) {
            return $this->errorResponse('User not found.', 404);
        }

        // Before validating: this also protects the password and the email.
        if (! AccessGuard::for($request->user())->coversUser((int) $id)) {
            return $this->errorResponse(self::BEYOND_YOUR_ACCESS, 403);
        }

        $validated = $this->validateUser($request);
        $validated['employee_id'] = Company::employeeIdFor((int) $validated['company_id'], $validated['employee_id'] ?? null);

        User::updateUser((int) $id, $validated, $validated['roles']);

        return $this->successResponse('User updated successfully.');
    }

    // Soft delete a user. Refused for your own account and for any Super Admin.
    public function destroy(Request $request, $id)
    {
        if (! User::existsById((int) $id)) {
            return $this->errorResponse('User not found.', 404);
        }

        if ((int) $id === (int) $request->user()->id) {
            return $this->errorResponse('You cannot delete your own account.', 422);
        }

        // A Super Admin is never deleted, by anyone: it is the account that can fix everything else.
        if (User::isSuperAdmin((int) $id)) {
            return $this->errorResponse('A Super Admin account cannot be deleted.', 422);
        }

        if (! AccessGuard::for($request->user())->coversUser((int) $id)) {
            return $this->errorResponse(self::BEYOND_YOUR_ACCESS, 403);
        }

        User::deleteUser((int) $id);

        return $this->successResponse('User deleted successfully.');
    }
}