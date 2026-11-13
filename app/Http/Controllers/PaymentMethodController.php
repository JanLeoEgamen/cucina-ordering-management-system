<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class PaymentMethodController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view payment-methods', only: ['index']),
            new Middleware('permission:create payment-methods', only: ['store']),
            new Middleware('permission:edit payment-methods', only: ['edit', 'update']),
            new Middleware('permission:delete payment-methods', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(PaymentMethod::getAll())
                ->skipPaging()
                ->addColumn('is_active', fn (object $method) => $this->statusBadge((bool) $method->is_active))
                ->addColumn('action', function (object $method) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $edit = 'mx-0.5 rounded-full border border-gray-300 px-3 py-1.5 font-medium text-indigo-600 dark:border-gray-500 transition hover:bg-indigo-50 hover:text-indigo-900 focus:outline-none focus:ring-2 focus:ring-indigo-300 dark:text-indigo-400 dark:hover:bg-indigo-900/30 dark:hover:text-indigo-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view payment-methods')) {
                        // Read only: it loads the same record the edit drawer does.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $method->method_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-load-error="' . e(__('Unable to load this payment method.')) . '"'
                            . ' data-view-url="' . e(route('payment-methods.edit', $method->method_id)) . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('edit payment-methods') && ! $method->is_protected) {
                        $buttons .= '<button type="button" data-row-action="edit"'
                            . ' data-id="' . $method->method_id . '"'
                            . ' data-loading-label="' . e(__('Loading...')) . '"'
                            . ' data-edit-url="' . e(route('payment-methods.edit', $method->method_id)) . '"'
                            . ' data-update-url="' . e(route('payment-methods.update', $method->method_id)) . '"'
                            . ' class="' . $edit . '">' . e(__('Edit')) . '</button>';
                    }

                    if (auth()->user()->can('delete payment-methods') && ! $method->is_protected) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $method->method_id . '"'
                            . ' data-delete-url="' . e(route('payment-methods.destroy', $method->method_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['is_active', 'action'])
                ->toJson();
        }

        return view('payment-methods.index');
    }

    // Get one payment method, for the edit and view drawers.
    public function edit($id)
    {
        $method = PaymentMethod::findById((int) $id);

        if (! $method) {
            return $this->errorResponse('Payment method not found.', 404);
        }

        // The drawers need a URL they can put in an <img>; the column itself
        // keeps the relative path (rows holding an absolute URL are left alone).
        $method->qr_image = PaymentMethod::qrUrl($method->qr_image);

        // The view drawer shows the same badge the table renders, so its markup
        // is not written a second time in JavaScript.
        $method->status = $this->statusBadge((bool) $method->is_active);

        return response()->json($method);
    }

    // Create a payment method.
    public function store(Request $request)
    {
        $validated = $this->validatePaymentMethod($request);
        $validated['qr_image'] = $this->storeUploadedQr($request->file('qr_image'));

        PaymentMethod::createPaymentMethod($validated);

        return $this->successResponse('Payment method created successfully.');
    }

    // Update a payment method.
    public function update(Request $request, $id)
    {
        if (! PaymentMethod::existsById((int) $id)) {
            return $this->errorResponse('Payment method not found.', 404);
        }

        if (PaymentMethod::isProtected((int) $id)) {
            return $this->errorResponse('This payment method is protected and cannot be edited.', 422);
        }

        $validated = $this->validatePaymentMethod($request);

        $previousQr = PaymentMethod::findById((int) $id)->qr_image ?? null;

        // A new file replaces the old one; the X on the drawer asks removal.
        if ($request->hasFile('qr_image')) {
            $validated['qr_image'] = $this->storeUploadedQr($request->file('qr_image'));
        } elseif ($request->boolean('remove_image')) {
            $validated['qr_image'] = null;
        }

        $updated = PaymentMethod::updatePaymentMethod((int) $id, $validated);

        // Drop the old file only once the row no longer points at it.
        if ($updated > 0 && array_key_exists('qr_image', $validated)) {
            $this->deleteQr($previousQr);
        }

        return $this->successResponse('Payment method updated successfully.');
    }

    // Soft delete a payment method. The orders that were paid through it keep
    // its name: the row stays, only the checkout stops offering it.
    public function destroy($id)
    {
        if (! PaymentMethod::existsById((int) $id)) {
            return $this->errorResponse('Payment method not found.', 404);
        }

        if (PaymentMethod::isProtected((int) $id)) {
            return $this->errorResponse('This payment method is protected and cannot be deleted.', 422);
        }

        $qr = PaymentMethod::findById((int) $id)->qr_image ?? null;

        PaymentMethod::deletePaymentMethod((int) $id);
        $this->deleteQr($qr);

        return $this->successResponse('Payment method deleted successfully.');
    }

    // Validation and normalisation shared by create and update.
    protected function validatePaymentMethod(Request $request): array
    {
        $request->merge([
            'is_active' => $request->boolean('is_active'),
            'remove_image' => $request->boolean('remove_image'),
        ]);

        return $request->validate([
            'method_name' => [
                'required',
                'string',
                'max:255',
                // The route key is a raw id (null while creating), and the
                // primary key column has to be named explicitly.
                Rule::unique('payment_methods', 'method_name')->ignore($request->route('id'), 'method_id'),
            ],
            'account_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'qr_image' => ['nullable', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'remove_image' => ['boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    // Store an uploaded QR on the public disk and return its relative path.
    protected function storeUploadedQr(?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }

        return $file->store('payment-methods', 'public');
    }

    // Delete a QR from the public disk when it is no longer referenced.
    protected function deleteQr(?string $path): void
    {
        // Absolute URLs (fixtures, factory data) are not files on this disk.
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}