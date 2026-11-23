<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\OrderNotifier;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Staff decide the discount an order was placed with: look at the ID the customer sent, then
// approve it or reject it (with a reason). {id} is the ORDER id.
//
//  - Approve: the total stays as placed.
//  - Reject: the discount goes, the order costs its full subtotal again and its payment is for
//    that amount. A bank / e-wallet customer paid the smaller amount, so that payment is sent
//    back (rejected, with the reason) and they send a new proof or switch to cash: the same way
//    out as any rejected payment (OrderController::resubmitProof / switchToCash). Cash on Pick up
//    just owes the new total at the counter.
// Until it is decided the order cannot be verified, completed or announced as ready.
class OrderDiscountController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission. The proof route has none on purpose: it
    // is judged inside (the order's own customer, or staff who handle orders), and answers 404
    // to everybody else so an id cannot be probed.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:review discounts', only: ['approve', 'reject']),
        ];
    }

    // Approve the discount of an order.
    public function approve($id)
    {
        $refused = $this->refusal((int) $id);

        if ($refused) {
            return $refused;
        }

        $result = DB::transaction(function () use ($id) {
            $locked = $this->lockPending((int) $id);

            if (is_string($locked)) {
                return $locked;
            }

            Order::approveDiscount((int) $id, (int) auth()->id());

            return null;
        });

        if ($result) {
            return $this->errorResponse($result, 422);
        }

        // Tell the customer (and the watchers): a queued job, see OrderNotifier.
        OrderNotifier::discountApproved((int) $id);

        return $this->successResponse('Discount approved successfully.');
    }

    // Reject the discount of an order. The customer is told why, so a reason is required.
    public function reject(Request $request, $id)
    {
        // An order that is not there (or has nothing to decide) is answered before the form is
        // judged, so the user is not asked for a reason for nothing.
        $refused = $this->refusal((int) $id);

        if ($refused) {
            return $refused;
        }

        $reason = trim($request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Give a reason for rejecting this discount.',
        ])['reason']);

        $outcome = DB::transaction(function () use ($id, $reason) {
            $locked = $this->lockPending((int) $id);

            if (is_string($locked)) {
                return $locked;
            }

            $oldTotal = (float) $locked->total_amount;
            // Orders from before discounts have no subtotal, but they have no discount to reject either.
            $newTotal = (float) ($locked->subtotal_amount ?? $locked->total_amount);

            Order::rejectDiscount((int) $id, (int) auth()->id(), $reason, $newTotal);
            Payment::setAmount((int) $id, $newTotal);

            $payment = Payment::ofOrder((int) $id);
            $paymentSentBack = false;

            // Paid first (bank / e-wallet) and not yet decided: that proof was for the smaller amount,
            // so it goes back to the customer like any rejected payment. Cash owes the new total at the
            // counter; a payment that was already rejected stays so (the new proof will be for the new total).
            if ($payment && ! PaymentMethod::isCashOnPickup($payment) && $payment->payment_status === 'pending') {
                Payment::setStatus((int) $id, 'rejected', (int) auth()->id(),
                    'Your discount was not approved, so the total is now ' . $this->money($newTotal) . ' (it was ' . $this->money($oldTotal) . '). Send a new proof for the full amount, or pay with cash on pick up.');
                Order::updatePaymentStatus((int) $id, 'rejected');
                $paymentSentBack = true;
            }

            return ['old' => $oldTotal, 'new' => $newTotal, 'payment_sent_back' => $paymentSentBack];
        });

        if (is_string($outcome)) {
            return $this->errorResponse($outcome, 422);
        }

        OrderNotifier::discountRejected((int) $id, $reason, $outcome['old'], $outcome['new'], $outcome['payment_sent_back']);

        return $this->successResponse('Discount rejected successfully.');
    }

    // The ID the customer uploaded, served from the private disk. Only the customer who sent it
    // and staff who work with orders may see it.
    public function proof($id)
    {
        $order = DB::table('orders')
            ->where('order_id', (int) $id)
            ->whereNull('deleted_at')
            ->first(['user_id', 'discount_proof']);

        $user = auth()->user();
        $allowed = $order && ((int) $order->user_id === (int) $user->id || $user->can('review discounts') || $user->can('view orders'));

        if (! $allowed || ! $order->discount_proof || ! Storage::disk('local')->exists($order->discount_proof)) {
            abort(404);
        }

        // Only raster pictures are ever accepted, and nosniff keeps a browser from guessing otherwise.
        return Storage::disk('local')->response($order->discount_proof, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // The checks that need no lock: is there an order, is it open, is there a discount to decide?
    protected function refusal(int $orderId)
    {
        $order = Order::findById($orderId);

        if (! $order) {
            return $this->errorResponse('Order not found.', 404);
        }

        if ($order->order_status !== 'pending') {
            return $this->errorResponse('Only the discount of a pending order can be decided.', 422);
        }

        if ($order->discount_status !== 'pending') {
            return $this->errorResponse('This order has no discount waiting for a decision.', 422);
        }

        return null;
    }

    // Inside the transaction: lock the order and judge again, so two reviewers (or a Cancel) that got
    // in first are seen instead of overwritten. Returns the locked row, or the reason it cannot go on.
    protected function lockPending(int $orderId): object|string
    {
        if (Order::lockedStatus($orderId) !== 'pending') {
            return 'Only the discount of a pending order can be decided.';
        }

        $locked = Order::lockedDiscount($orderId);

        if (! $locked || $locked->discount_status !== 'pending') {
            return 'This discount was already decided.';
        }

        return $locked;
    }

    protected function money(float $amount): string
    {
        return '₱' . number_format($amount, 2);
    }
}
