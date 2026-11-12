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

    // Badge for an order (pending / completed / cancelled).
    protected function orderStatusBadge(string $status): string
    {
        return $this->badge($status);
    }

    // Badge for a payment (pending / verified / rejected).
    protected function paymentStatusBadge(string $status): string
    {
        return $this->badge($status);
    }

    // One badge renderer for every status column above.
    // An order feed row as the order popup needs it (resources/js/ui/order-popup.js): the
    // badges are rendered here so the table and cards print markup, the proof travels as a
    // URL, and each action has a URL only while it applies AND the user may take it. Used by
    // the order session page and the Orders page, so both offer the same actions.
    protected function orderRow(object $order): object
    {
        $user = auth()->user();

        $order->status_badge = $this->orderStatusBadge($order->order_status);
        $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
        $order->items_count = array_sum(array_column($order->items ?? [], 'quantity'));
        $order->proof_url = Payment::proofUrl($order->proof_of_payment);

        // The session it was placed in (the Orders page lists every session's orders).
        if (isset($order->session_id)) {
            $order->session_label = Carbon::parse($order->session_date)->format('M j, Y')
                . ' · ' . substr((string) $order->session_start, 0, 5) . ' – ' . substr((string) $order->session_end, 0, 5);
            $order->session_url = $user->can('view order-sessions') ? route('order-sessions.show', $order->session_id) : null;
        }

        // Verify / Reject sit inside the order's details: only while its payment
        // is pending, and only for someone who may take that decision.
        $pending = $order->payment_state === 'pending';
        $cash = $order->method_name === PaymentMethod::CASH_ON_PICKUP;
        $order->is_cash = $cash;

        // Cash on Pick up is verified by completing the order, so only the other
        // methods are verified or rejected here - from the moment the order exists.
        $order->verify_url = $pending && ! $cash && $user->can('verify payments')
            ? route('payments.verify', $order->order_id) : null;
        $order->reject_url = $pending && ! $cash && $user->can('reject payments')
            ? route('payments.reject', $order->order_id) : null;

        // Completing needs a settled payment: cash settles on completion, the
        // others must be verified first.
        $settled = $cash ? in_array($order->payment_state, ['pending', 'verified'], true) : $order->payment_state === 'verified';
        $order->complete_url = $order->order_status === 'pending' && $settled && $user->can('complete orders')
            ? route('orders.complete', $order->order_id) : null;

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

    // Failure response for the AJAX forms: {status, message}, with the HTTP code.
    protected function errorResponse(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status' => 'failed',
            'message' => $message,
        ], $status);
    }
}
