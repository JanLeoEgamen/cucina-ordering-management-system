<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;

// Every payment of every order, for whoever checks the money: open one in a popup
// and verify or reject it there (a rejection needs a reason), undo that decision,
// or remove it from the list. The payment is created with the order (OrderController), and
// its order id is its key, so {id} in the routes is an order id.
class PaymentController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view payments', only: ['index']),
            new Middleware('permission:verify payments', only: ['verify']),
            new Middleware('permission:reject payments', only: ['reject']),
            new Middleware('permission:undo payments', only: ['undo']),
            new Middleware('permission:delete payments', only: ['destroy']),
        ];
    }

    // Display the page, and the table feed for its AJAX request.
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(Payment::getAll())
                ->skipPaging()
                ->addColumn('status', fn (object $payment) => $this->paymentStatusBadge($payment->payment_status))
                // The popup shows a button for each URL that is not null.
                ->addColumn('verify_url', fn (object $payment) => $this->decisionUrl($payment, 'verify'))
                ->addColumn('reject_url', fn (object $payment) => $this->decisionUrl($payment, 'reject'))
                ->addColumn('undo_url', fn (object $payment) => $this->decisionUrl($payment, 'undo'))
                ->addColumn('action', function (object $payment) {
                    $view = 'mx-0.5 rounded-full border border-gray-200 px-3 py-1.5 font-medium text-slate-600 dark:border-gray-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:text-slate-400 dark:hover:bg-slate-700/40 dark:hover:text-slate-200';
                    $delete = 'mx-0.5 rounded-full border border-red-200 px-3 py-1.5 font-medium text-red-600 dark:border-red-900 transition hover:bg-red-50 hover:text-red-900 focus:outline-none focus:ring-2 focus:ring-red-300 dark:text-red-400 dark:hover:bg-red-900/30 dark:hover:text-red-200';

                    $buttons = '';

                    // A button only shows when the matching permission lets the click through.
                    if (auth()->user()->can('view payments')) {
                        // Read only, in a popup - which is also where a pending
                        // payment is verified or rejected. The popup is filled from
                        // the row, so no URL.
                        $buttons .= '<button type="button" data-row-action="view"'
                            . ' data-id="' . $payment->order_id . '"'
                            . ' class="' . $view . '">' . e(__('View')) . '</button>';
                    }

                    if (auth()->user()->can('delete payments')) {
                        $buttons .= '<button type="button" data-row-action="delete"'
                            . ' data-id="' . $payment->order_id . '"'
                            . ' data-delete-url="' . e(route('payments.destroy', $payment->order_id)) . '"'
                            . ' class="' . $delete . '">' . e(__('Delete')) . '</button>';
                    }

                    return $buttons;
                })
                ->rawColumns(['status', 'action'])
                ->toJson();
        }

        return view('payments.index');
    }

    // The URL of a decision when the payment is in the right state for it (verify
    // and reject: pending; undo: already decided) and the user may take it.
    protected function decisionUrl(object $payment, string $action): ?string
    {
        $pending = $payment->payment_status === 'pending';
        $fits = $action === 'undo' ? ! $pending : $pending;

        // Cash on Pick up is verified when its order is completed, so there is
        // nothing to verify or reject.
        // A cancelled order has no payment left to decide, and a decision of a
        // completed or cancelled order stays.
        $allowed = match ($action) {
            'undo' => $payment->order_status === 'pending',
            default => $payment->method_name !== PaymentMethod::CASH_ON_PICKUP && $payment->order_status !== 'cancelled',
        };

        return $fits && $allowed && auth()->user()->can($action . ' payments')
            ? route('payments.' . $action, $payment->order_id)
            : null;
    }

    // Verify a pending payment.
    public function verify($id)
    {
        if ($refused = $this->refuseCash((int) $id)) {
            return $refused;
        }

        return $this->decide((int) $id, 'verified', 'Payment verified successfully.');
    }

    // Reject a pending payment. The customer is told why, so a reason is required.
    public function reject(Request $request, $id)
    {
        // A payment that is not there (or not pending) is answered before the
        // form is judged, so the user is not asked for a reason for nothing.
        $current = Payment::statusOf((int) $id);

        if ($current === null) {
            return $this->errorResponse('Payment not found.', 404);
        }

        if ($current !== 'pending') {
            return $this->errorResponse('Only a pending payment can be rejected.', 422);
        }

        if ($refused = $this->refuseCash((int) $id)) {
            return $refused;
        }

        if (Order::statusOf((int) $id) === 'cancelled') {
            return $this->errorResponse('The order is cancelled.', 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Give a reason for rejecting this payment.',
        ]);

        return $this->decide((int) $id, 'rejected', 'Payment rejected successfully.', trim($validated['reason']));
    }

    // Take a decision back: a verified or rejected payment goes back to pending,
    // without reviewer and without reason, and the order mirrors it again.
    public function undo($id)
    {
        $current = Payment::statusOf((int) $id);

        if ($current === null) {
            return $this->errorResponse('Payment not found.', 404);
        }

        if ($current === 'pending') {
            return $this->errorResponse('Only a verified or rejected payment can be undone.', 422);
        }

        if (Order::statusOf((int) $id) !== 'pending') {
            return $this->errorResponse('The order is completed or cancelled, so its payment decision cannot be undone.', 422);
        }

        DB::transaction(function () use ($id) {
            Payment::setStatus((int) $id, 'pending', null);
            Order::updatePaymentStatus((int) $id, 'pending');
        });

        return $this->successResponse('Payment decision undone successfully.');
    }

    // A Cash on Pick up payment is settled by completing its order, not here.
    protected function refuseCash(int $orderId)
    {
        $payment = Payment::ofOrder($orderId);

        if ($payment && PaymentMethod::isCashOnPickup($payment)) {
            return $this->errorResponse('A Cash on Pick up payment is verified when the order is completed.', 422);
        }

        return null;
    }

    // Verify and reject are the same move: only a pending payment can be decided,
    // the payment and the order it mirrors change together, and the reviewer and
    // the moment are stamped.
    protected function decide(int $orderId, string $status, string $message, ?string $reason = null)
    {
        $current = Payment::statusOf($orderId);

        if ($current === null) {
            return $this->errorResponse('Payment not found.', 404);
        }

        if ($current !== 'pending') {
            return $this->errorResponse('Only a pending payment can be ' . $status . '.', 422);
        }

        if (Order::statusOf($orderId) === 'cancelled') {
            return $this->errorResponse('The order is cancelled.', 422);
        }

        DB::transaction(function () use ($orderId, $status, $reason) {
            Payment::setStatus($orderId, $status, auth()->id(), $reason);
            Order::updatePaymentStatus($orderId, $status);
        });

        return $this->successResponse($message);
    }
    // Soft delete a payment. A verified one is the record of money received, so
    // it is kept.
    public function destroy($id)
    {
        $status = Payment::statusOf((int) $id);

        if ($status === null) {
            return $this->errorResponse('Payment not found.', 404);
        }

        if ($status === 'verified') {
            return $this->errorResponse('A verified payment cannot be deleted.', 422);
        }

        Payment::deletePayment((int) $id);

        return $this->successResponse('Payment deleted successfully.');
    }
}
