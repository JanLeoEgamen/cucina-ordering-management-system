<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

abstract class Controller
{
    // Status badges for the table cells. One quiet gray pill for every status; the
    // state is told by the small coloured dot in front of the label, which
    // resources/css/app.css draws from the data-status attribute. So a new status
    // needs one line of CSS, not a new set of colour classes here.
    // (The class names below are only kept by Tailwind because tailwind.config.js
    // scans app/Http/Controllers/**.)
    protected function statusBadge(bool $active): string
    {
        return $this->badge($active ? 'active' : 'inactive', $active ? __('Active') : __('Inactive'));
    }

    // Badge for an order session: scheduled before its window, live inside it,
    // ended once it was closed by hand or its end time went past.
    protected function sessionBadge(string $status): string
    {
        return $this->badge($status);
    }

    // Badge for an order (pending / completed / cancelled). A pending order that staff announced
    // as ready gets a second badge beside "Pending" (not instead of it: it still IS pending, and
    // the Pending filter of the lists keeps finding it).
    protected function orderStatusBadge(string $status, bool $ready = false): string
    {
        return $this->badge($status)
            . ($ready && $status === 'pending' ? ' ' . $this->badge('ready', __('Ready for pick up')) : '');
    }

    // Badge for a payment (pending / verified / rejected).
    protected function paymentStatusBadge(string $status): string
    {
        return $this->badge($status);
    }

    // Validation rule for each element of a posted `dish_ids` list: the dish exists. ONE query for the
    // whole list ('exists:dishes,dish_id' asks once per dish, ~30 queries for a big session). The error
    // is the same one, on the same key (dish_ids.N).
    protected function dishExistsRule(\Illuminate\Http\Request $request): \Closure
    {
        $known = null;

        return function (string $attribute, mixed $value, \Closure $fail) use ($request, &$known) {
            $known ??= \Illuminate\Support\Facades\DB::table('dishes')
                ->whereIn('dish_id', array_map('intval', (array) $request->input('dish_ids')))
                ->pluck('dish_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! in_array((int) $value, $known, true)) {
                $fail('validation.exists')->translate();
            }
        };
    }

    // Badge for an order's discount (pending / approved / rejected): "Discount pending" and so on.
    protected function discountStatusBadge(string $status): string
    {
        return $this->badge($status, __('Discount :status', ['status' => __(ucfirst($status))]));
    }

    // One badge renderer for every status column above.
    // An order feed row as the order popup needs it (resources/js/ui/order-popup.js): the
    // badges are rendered here so the table and cards print markup, the proof travels as a
    // URL, and each action has a URL only while it applies AND the user may take it. Used by
    // the order session page and the Orders page, so both offer the same actions.
    protected function orderRow(object $order): object
    {
        $user = auth()->user();

        $order->status_badge = $this->orderStatusBadge($order->order_status, $order->ready_at !== null);
        $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
        $order->items_count = array_sum(array_column($order->items ?? [], 'quantity'));
        $order->proof_url = Payment::proofUrl($order->proof_of_payment);

        // The session it was placed in (the Orders page lists every session's orders).
        if (isset($order->session_id)) {
            $order->session_label = Carbon::parse($order->session_date)->format('M j, Y')
                . ' · ' . substr((string) $order->session_start, 0, 5) . ' – ' . substr((string) $order->session_end, 0, 5);
            $order->session_url = $user->can('view order-sessions') ? route('order-sessions.show', $order->session_id) : null;
        }

        // The discount. Its ID picture is private: the row carries a guarded URL, never the path.
        $order->discount_status ??= 'none';
        $order->discount_proof_url = ($order->discount_proof ?? null) ? route('orders.discount.proof', $order->order_id) : null;
        unset($order->discount_proof);
        $order->discount_badge = $order->discount_status !== 'none' ? $this->discountStatusBadge($order->discount_status) : null;

        // Approve / Reject the discount: while it waits, on an order that is still open.
        $discountOpen = $order->discount_status === 'pending' && $order->order_status === 'pending';
        $order->discount_approve_url = $discountOpen && $user->can('review discounts')
            ? route('orders.discount.approve', $order->order_id) : null;
        $order->discount_reject_url = $discountOpen && $user->can('review discounts')
            ? route('orders.discount.reject', $order->order_id) : null;

        // The price is not final while the discount waits: nothing is verified, completed or
        // announced until staff decide it (the server refuses those too).
        $priceFinal = $order->discount_status !== 'pending';

        // Verify / Reject sit inside the order's details: only while its payment
        // is pending, and only for someone who may take that decision.
        $pending = $order->payment_state === 'pending';
        $cash = $order->method_name === PaymentMethod::CASH_ON_PICKUP;
        $order->is_cash = $cash;

        // Cash on Pick up is verified by completing the order, so only the other
        // methods are verified or rejected here - from the moment the order exists.
        $order->verify_url = $pending && $priceFinal && ! $cash && $user->can('verify payments')
            ? route('payments.verify', $order->order_id) : null;
        $order->reject_url = $pending && ! $cash && $user->can('reject payments')
            ? route('payments.reject', $order->order_id) : null;

        // Completing needs a settled payment: cash settles on completion, the
        // others must be verified first.
        $settled = $priceFinal && ($cash ? in_array($order->payment_state, ['pending', 'verified'], true) : $order->payment_state === 'verified');
        $order->complete_url = $order->order_status === 'pending' && $settled && $user->can('complete orders')
            ? route('orders.complete', $order->order_id) : null;

        // "Ready for pick up": the same settled-payment rule as completing, and only once (a
        // second announcement would tell the customer twice). Whoever may complete may announce.
        $order->ready_url = $order->order_status === 'pending' && $order->ready_at === null && $settled && $user->can('complete orders')
            ? route('orders.ready', $order->order_id) : null;

        // Cancel a pending order - unless its payment is verified: that decision
        // is undone first (on the Payments page), so money is never lost track of.
        $order->cancel_url = $order->order_status === 'pending' && $order->payment_state !== 'verified' && $user->can('cancel orders')
            ? route('orders.cancel', $order->order_id) : null;

        return $order;
    }

    protected function badge(string $status, ?string $label = null): string
    {
        return '<span data-status="' . e($status) . '" class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold leading-5 text-gray-700 dark:bg-gray-700 dark:text-gray-200">'
            . e($label ?? __(ucfirst($status)))
            . '</span>';
    }
    // Success response for the AJAX forms: {status, message}.
    protected function successResponse(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'message' => $message,
        ]);
    }

    // What the order popup shows for a customer's OWN order (My Orders / My Pre-Orders): one
    // JSON object on the card, so opening it costs no request. Deliberately NOT the staff row
    // (orderRow): no customer name, no verifier and no action URLs, so the popup hides those
    // rows and offers no Complete / Cancel / Verify / Reject, whatever permissions the viewer
    // holds as staff. `$order` needs status_badge, payment_badge and proof_url set first.
    protected function customerOrderPopup(object $order, string $sessionLabel): array
    {
        return [
            'order_number' => $order->order_number,
            'order_date' => $order->order_date,
            'session_label' => $sessionLabel,
            'method_name' => $order->method_name,
            'reference_number' => $order->reference_number,
            'total_amount' => (float) $order->total_amount,
            // The discount, in words the customer reads. No ID path, no reviewer: staff fields never
            // reach this popup (it shows a row only when its key is here).
            'discount_name' => ($order->discount_status ?? 'none') !== 'none' ? $order->discount_name : null,
            'discount_percent' => ($order->discount_status ?? 'none') !== 'none' ? (float) $order->discount_percent : null,
            'discount_status' => $order->discount_status ?? 'none',
            'discount_badge' => ($order->discount_status ?? 'none') !== 'none' ? $this->discountStatusBadge($order->discount_status) : null,
            'discount_amount' => (float) ($order->discount_amount ?? 0),
            'discount_reject_reason' => ($order->discount_status ?? 'none') === 'rejected' ? $order->discount_reject_reason : null,
            'subtotal_amount' => ($order->discount_status ?? 'none') !== 'none' ? (float) ($order->subtotal_amount ?? $order->total_amount) : null,
            'status_badge' => $order->status_badge,
            'payment_badge' => $order->payment_badge,
            'proof_url' => $order->proof_url,
            'items' => array_map(fn (array $item) => [
                'dish_name' => $item['dish_name'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'sub_total' => (float) $item['sub_total'],
            ], $order->items),
        ];
    }

    // Failure response for the AJAX forms: {status, message}, with the HTTP code.
    protected function errorResponse(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
        ], $status);
    }
}
