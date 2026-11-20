<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class CompanyController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view companies', only: ['index', 'edit']),
            new Middleware('permission:create companies', only: ['store']),
            new Middleware('permission:edit companies', only: ['update']),
            new Middleware('permission:delete companies', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(Company::getAll())
                ->skipPaging()
                ->addColumn('is_active', fn (object $company) => $this->statusBadge((bool) $company->is_active))
                ->addColumn('action', function (object $company) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view companies')) {
                        // Read only: it loads the same record the edit drawer does.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $company->company_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this company.')) . '"'
                            . ' data-view-url="' . e(route('companies.edit', $company->company_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    // A protected company only offers View: update() / destroy() refuse it anyway.
                    if (auth()->user()->can('edit companies') && ! $company->is_protected) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $company->company_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('companies.edit', $company->company_id)) . '"'
                            . ' data-update-url="' . e(route('companies.update', $company->company_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete companies') && ! $company->is_protected) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $company->company_id . '"'
                            . ' data-delete-url="' . e(route('companies.destroy', $company->company_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                // The name goes out as typed: resources/js/pages/companies.js escapes it once when it
                // prints it, so letting the feed escape it too would show "O&#039;Kon".
                ->rawColumns(['company_name', 'is_active', 'action'])
                ->toJson();
        }

        return view('companies.index');
    }

    // Get one company, for the edit and view drawers.
    public function edit($id)
    {
        $company = Company::findById((int) $id);

        if (! $company) {
            return $this->errorResponse('Company not found.', 404);
        }

        // The view drawer shows the same badge the table renders, so its markup
        // is not written a second time in JavaScript.
        $company->status = $this->statusBadge((bool) $company->is_active);

        return response()->json($company);
    }

    // Create a company.
    public function store(Request $request)
    {
        Company::createCompany($this->validateCompany($request));

        return $this->successResponse('Company created successfully.');
    }

    // Update a company.
    public function update(Request $request, $id)
    {
        if (! Company::existsById((int) $id)) {
            return $this->errorResponse('Company not found.', 404);
        }

        if (Company::isProtected((int) $id)) {
            return $this->errorResponse('This company is protected and cannot be edited.', 422);
        }

        Company::updateCompany((int) $id, $this->validateCompany($request));

        return $this->successResponse('Company updated successfully.');
    }

    // Soft delete a company. Refused while people still belong to it: they would be left with
    // a company that is not in any dropdown. Switch it off instead to stop offering it.
    public function destroy($id)
    {
        if (! Company::existsById((int) $id)) {
            return $this->errorResponse('Company not found.', 404);
        }

        if (Company::isProtected((int) $id)) {
            return $this->errorResponse('This company is protected and cannot be deleted.', 422);
        }

        if (Company::countUsers((int) $id) > 0) {
            return $this->errorResponse('Cannot delete this company because users belong to it. Switch it off to stop offering it.', 422);
        }

        Company::deleteCompany((int) $id);

        return $this->successResponse('Company deleted successfully.');
    }

    // Rules and normalisation shared by create and update.
    protected function validateCompany(Request $request): array
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        return $request->validate([
            'company_name' => [
                'required',
                'string',
                'max:255',
                // A deleted company does not hold its name: the name can be used again.
                Rule::unique('companies', 'company_name')
                    ->whereNull('deleted_at')
                    ->ignore($request->route('id'), 'company_id'),
            ],
            'is_active' => ['required', 'boolean'],
        ]);
    }
}
