<?php

namespace App\Http\Controllers;

use App\Exceptions\StockRanOutException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use App\Support\OrderNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;
use Yajra\DataTables\Facades\DataTables;

// The ordering side: the menu of the session that is running, and the orders
// placed from it. The order session module owns the sessions themselves.
class OrderController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view menu', only: ['index', 'stock']),
            // Fixing the payment of your own order is part of ordering, so it
            // needs no permission of its own (and the Customer role already has it).
            new Middleware('permission:create orders', only: ['store', 'resubmitProof', 'switchToCash']),
            new Middleware('permission:view my orders', only: ['mine']),
            new Middleware('permission:view orders', only: ['all']),
            new Middleware('permission:complete orders', only: ['complete']),
            new Middleware('permission:cancel orders', only: ['cancel']),
        ];
    }

    // Every order of every session, for whoever runs the kitchen: the page, and its table
    // feed on the AJAX request. Each row is decorated like the session page's (orderRow),
    // so the order popup offers the same Complete / Cancel / Verify / Reject here.
    public function all(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::collection(collect(Order::getAll())->map(fn (object $order) => $this->orderRow($order)))
                ->skipPaging()
                ->rawColumns(['status_badge', 'payment_badge'])
                ->toJson();
        }

        return view('orders.index');
    }

    // The orders the signed-in customer placed, newest first.
    public function mine()
    {
        $orders = Order::ofUser((int) auth()->id());

        // The badges are rendered here, so the view prints markup instead of
        // building it, and the proof travels as a URL the card can link to.
        $canPay = auth()->user()->can('create orders');
        $cash = PaymentMethod::cashOption();

        foreach ($orders as $order) {
            $order->status_badge = $this->orderStatusBadge($order->order_status);
            $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
            $order->proof_url = Payment::proofUrl($order->proof_of_payment);

            // A rejected payment on an order still waiting can be fixed by its
            // customer: a new proof (bank / e-wallet), or cash on pickup. Each
            // URL is set only while it applies and the user may use it.
            $fixable = $canPay && $order->order_status === 'pending' && $order->payment_state === 'rejected';
            $isCash = $order->method_name === PaymentMethod::CASH_ON_PICKUP;

            $order->reupload_url = $fixable && ! $isCash ? route('my-orders.payment.proof', $order->order_id) : null;
            $order->cash_url = $fixable && $cash && ! $isCash ? route('my-orders.payment.cash', $order->order_id) : null;
        }

        return view('my-orders.index', [
            'orders' => $orders,
        ]);
    }

    // Complete an order: it was handed over. How the payment settles depends on
    // the method:
    //  - Cash on Pick up is paid at the counter, so completing the order is what
    //    verifies the payment (and stamps whoever completed it).
    //  - Anything else (bank, e-wallet) was paid first and must already be
    //    verified: the payment can be checked as soon as the order exists.
    public function complete($id)
    {
        $orderId = (int) $id;
        $status = Order::statusOf($orderId);

        if ($status === null) {
            return $this->errorResponse('Order not found.', 404);
        }

        if ($status !== 'pending') {
            return $this->errorResponse('Only a pending order can be completed.', 422);
        }

        $payment = Payment::ofOrder($orderId);

        if (! $payment) {
            return $this->errorResponse('This order has no payment to settle.', 422);
        }

        $cash = PaymentMethod::isCashOnPickup($payment);

        if ($payment->payment_status === 'rejected') {
            return $this->errorResponse('The payment of this order was rejected.', 422);
        }

        if (! $cash && $payment->payment_status !== 'verified') {
            return $this->errorResponse('Verify the payment before completing this order.', 422);
        }

        $refused = DB::transaction(function () use ($orderId) {
            // The checks above ran without a lock. Take the order's row lock and
            // judge again, so a Cancel (or a second Complete) that got in first
            // is seen instead of overwritten.
            if (Order::lockedStatus($orderId) !== 'pending') {
                return 'Only a pending order can be completed.';
            }

            $payment = Payment::ofOrder($orderId);

            if (! $payment || $payment->payment_status === 'rejected') {
                return 'The payment of this order was rejected.';
            }

            $cash = PaymentMethod::isCashOnPickup($payment);

            if (! $cash && $payment->payment_status !== 'verified') {
                return 'Verify the payment before completing this order.';
            }

            // Cash is verified by the completion itself.
            if ($cash && $payment->payment_status === 'pending') {
                Payment::setStatus($orderId, 'verified', auth()->id());
                Order::updatePaymentStatus($orderId, 'verified');
            }

            Order::updateStatus($orderId, 'completed');

            return null;
        });

        if ($refused) {
            return $this->errorResponse($refused, 422);
        }

        OrderNotifier::completed($orderId);

        return $this->successResponse('Order completed successfully.');
    }

    // Cancel a pending order. What it reserved goes back on the menu, and a
    // payment that was still waiting is rejected with the reason, so it can no
    // longer be verified. A payment that was already verified means money was
    // received: that decision has to be undone first, on purpose.
    public function cancel($id)
    {
        $orderId = (int) $id;
        $status = Order::statusOf($orderId);

        if ($status === null) {
            return $this->errorResponse('Order not found.', 404);
        }

        if ($status !== 'pending') {
            return $this->errorResponse('Only a pending order can be cancelled.', 422);
        }

        $payment = Payment::ofOrder($orderId);

        if ($payment && $payment->payment_status === 'verified') {
            return $this->errorResponse('Undo the payment verification before cancelling this order.', 422);
        }

        $refused = DB::transaction(function () use ($orderId) {
            // Lock the order and judge again: two people pressing Cancel at the
            // same moment must not both give the stock back (that would put
            // plates on the menu that were never cooked).
            if (Order::lockedStatus($orderId) !== 'pending') {
                return 'Only a pending order can be cancelled.';
            }

            $payment = Payment::ofOrder($orderId);

            if ($payment && $payment->payment_status === 'verified') {
                return 'Undo the payment verification before cancelling this order.';
            }

            // The stock goes back through the one place that moves it.
            foreach (OrderItem::ofOrder($orderId) as $line) {
                SessionDish::release($line['session_dish_id'], $line['quantity']);
            }

            if ($payment && $payment->payment_status === 'pending') {
                Payment::setStatus($orderId, 'rejected', auth()->id(), 'The order was cancelled.');
                Order::updatePaymentStatus($orderId, 'rejected');
            }

            Order::updateStatus($orderId, 'cancelled');

            return null;
        });

        if ($refused) {
            return $this->errorResponse($refused, 422);
        }

        OrderNotifier::cancelled($orderId);

        return $this->successResponse('Order cancelled successfully.');
    }

    // A customer fixes a payment staff rejected, by sending a new proof (and
    // optionally a new reference number) for the same method. The order keeps its
    // place and its reserved plates; the payment goes back to pending so staff
    // check it again.
    public function resubmitProof(Request $request, $id)
    {
        $orderId = (int) $id;

        if (! $this->isMyOrder($orderId)) {
            return $this->errorResponse('Order not found.', 404);
        }

        $validated = $request->validate([
            'proof_of_payment' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'reference_number' => ['nullable', 'string', 'max:255'],
        ], [
            'proof_of_payment.required' => 'Upload the new proof of payment.',
            'proof_of_payment.image' => 'The proof of payment has to be an image.',
            'proof_of_payment.max' => 'The proof of payment may not be larger than 2 MB.',
        ]);

        $payment = Payment::ofOrder($orderId);

        if ($payment && PaymentMethod::isCashOnPickup($payment)) {
            return $this->errorResponse('Cash on Pick up is paid at the counter, so there is no proof to send.', 422);
        }

        $proof = $request->file('proof_of_payment')->store('payments', 'public');

        // Leaving the reference box out keeps the old one.
        $reference = $request->has('reference_number') ? ($validated['reference_number'] ?? null) : ($payment->reference_number ?? null);

        $old = null;
        $refused = $this->reopenPayment($orderId, (int) ($payment->payment_method_id ?? 0), $reference, $proof, $old);

        if ($refused) {
            $this->discardProof($proof);

            return $this->errorResponse($refused, 422);
        }

        // The replaced picture belongs to no payment any more.
        $this->discardProof($old);

        OrderNotifier::paymentResent($orderId, cash: false);

        return $this->successResponse('Your new proof was sent. Staff will check your payment again.');
    }

    // The other way out of a rejected payment: pay with cash when picking the
    // order up. Cash on Pick up has no proof; it is verified when the order is
    // completed.
    public function switchToCash($id)
    {
        $orderId = (int) $id;

        if (! $this->isMyOrder($orderId)) {
            return $this->errorResponse('Order not found.', 404);
        }

        $cash = PaymentMethod::cashOption();

        if (! $cash) {
            return $this->errorResponse('Cash on Pick up is not available right now.', 422);
        }

        $payment = Payment::ofOrder($orderId);

        if ($payment && PaymentMethod::isCashOnPickup($payment)) {
            return $this->errorResponse('This order is already paid with Cash on Pick up.', 422);
        }

        $old = null;
        $refused = $this->reopenPayment($orderId, (int) $cash->method_id, null, null, $old);

        if ($refused) {
            return $this->errorResponse($refused, 422);
        }

        // The rejected screenshot is not needed for a cash order.
        $this->discardProof($old);

        OrderNotifier::paymentResent($orderId, cash: true);

        return $this->successResponse('Your order will be paid with cash on pickup.');
    }

    // Is this order the signed-in customer's own? Anyone else's order answers
    // "not found", so ids cannot be probed.
    protected function isMyOrder(int $orderId): bool
    {
        $order = Order::findById($orderId);

        return $order !== null && (int) $order->user_id === (int) auth()->id();
    }

    // Put a rejected payment back to pending with the new method / reference /
    // proof, or return why it cannot be. The order row is locked and everything
    // is judged again inside, so a Cancel or a staff Undo that lands at the same
    // moment is seen, and a double tap changes things once. $oldProof receives the
    // picture that was replaced.
    protected function reopenPayment(int $orderId, int $methodId, ?string $reference, ?string $proof, ?string &$oldProof): ?string
    {
        return DB::transaction(function () use ($orderId, $methodId, $reference, $proof, &$oldProof) {
            if (Order::lockedStatus($orderId) !== 'pending') {
                return 'Only an order that is still pending can have its payment sent again.';
            }

            $payment = Payment::ofOrder($orderId);

            if (! $payment || $payment->payment_status !== 'rejected') {
                return 'Only a rejected payment can be sent again.';
            }

            $oldProof = $payment->proof_of_payment;

            Payment::resubmit($orderId, $methodId, $reference, $proof);
            Order::updatePaymentStatus($orderId, 'pending');

            return null;
        });
    }

    // The menu: the dishes the running session offers, and what is left of each.
    public function index()
    {
        $session = OrderSession::live();

        return view('menu.index', [
            'session' => $session,
            // A dish that is turned off, or left the session, is not offered.
            // One with no stock left stays, so the menu shows it as sold out.
            'dishes' => $session ? SessionDish::offeredForSession($session->session_id) : collect(),
            // Checkout offers two ways to pay: cash on pickup (one method, paid
            // when the order is collected) and bank / e-wallet (the list below).
            'cash' => PaymentMethod::cashOption(),
            'methods' => PaymentMethod::bankOptions(),
        ]);
    }

    // The live stock of the running session, polled by the menu every few
    // seconds so the cards follow what other customers order (and what staff
    // cancel). Only numbers travel; `live` turns false once the session closes.
    public function stock(Request $request)
    {
        $sessionId = (int) $request->query('session_id');
        $session = $sessionId > 0 ? OrderSession::findById($sessionId) : null;
        $live = $session !== null && $session->status === 'live';

        return response()
            ->json([
                'status' => 'success',
                'live' => $live,
                'stock' => $live ? SessionDish::stockOfSession($sessionId) : [],
                // Prices too: an admin may change one while customers browse.
                'prices' => $live ? SessionDish::pricesOfSession($sessionId) : [],
            ])
            // A cached answer would show yesterday's plates.
            ->header('Cache-Control', 'no-store');
    }

    // Place an order on the running session. The lines arrive as
    // items[session_dish_id] = quantity, and the payment method is picked at
    // checkout. Stock is taken in the same request, so the menu cannot oversell.
    //
    // Many customers press Place Order at the same moment, so this method only
    // guards the door; placeOrder() does the work:
    //  - one order at a time per customer (a lock), so a double tap or a second
    //    tab cannot create two orders;
    //  - the idempotency key the menu sends: if the answer got lost on a bad
    //    connection and the customer taps again, they get the same receipt back
    //    instead of a second order and a second charge.
    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $userId = (int) auth()->id();

        $lock = Cache::lock('place-order:' . $userId, 30);

        if (! $lock->get()) {
            return $this->errorResponse('Your order is already being placed. Please wait a moment.', 409);
        }

        try {
            $key = $validated['idempotency_key'] ?? null;
            $cacheKey = 'order-receipt:' . $userId . ':' . $key;

            if ($key && ($receipt = Cache::get($cacheKey))) {
                return response()->json($receipt);
            }

            $response = $this->placeOrder($request, $validated);

            if ($key && $response->getStatusCode() === 200) {
                Cache::put($cacheKey, $response->getData(true), now()->addMinutes(10));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    // The work of store(): check the menu, then reserve stock and write the
    // order in one transaction.
    protected function placeOrder(Request $request, array $validated)
    {
        $session = OrderSession::findById($validated['session_id']);

        if (! $session) {
            return $this->errorResponse('Order session not found.', 404);
        }

        if ($session->status !== 'live') {
            return $this->errorResponse('This order session is not running.', 422);
        }

        // What the menu offered when the page was loaded. The stock is only a
        // heads up here: the reservation below is what actually holds it.
        $offered = SessionDish::offeredForSession($validated['session_id'])
            ->keyBy('session_dish_id');

        // The prices the customer saw (and, for a bank or e-wallet order, paid).
        // The order is charged at the price of NOW, so when an admin changed a
        // price in between, refuse and show the new one instead of saving an
        // order whose total differs from the payment. Checked before the stock so
        // the customer reviews the cart once. A client that sends no prices is not
        // checked.
        $changed = [];

        foreach ($validated['items'] as $sessionDishId => $quantity) {
            $dish = $offered->get((int) $sessionDishId);
            $expected = $validated['expected_prices'][$sessionDishId] ?? null;

            if ($dish && $expected !== null && round((float) $expected, 2) !== round((float) $dish->price, 2)) {
                $changed[] = $dish->dish_name . ' is now ₱' . number_format((float) $dish->price, 2)
                    . ' (was ₱' . number_format((float) $expected, 2) . ')';
            }
        }

        if ($changed) {
            return $this->stockShortResponse($session, 'The price changed: ' . implode(', ', $changed) . '.');
        }

        $lines = [];
        $total = 0.0;

        foreach ($validated['items'] as $sessionDishId => $quantity) {
            $dish = $offered->get((int) $sessionDishId);

            if (! $dish) {
                return $this->errorResponse('One of the dishes is no longer on the menu.', 422);
            }

            if ($dish->available_stock < 1) {
                return $this->stockShortResponse($session, $dish->dish_name . ' is sold out.');
            }

            if ($quantity > $dish->available_stock) {
                return $this->stockShortResponse($session, 'Only ' . $dish->available_stock . ' left of ' . $dish->dish_name . '.');
            }

            // Cents, not floats: 3 x 0.10 must be 0.30 and not 0.30000000000000004.
            $subTotal = round((float) $dish->price * $quantity, 2);

            $lines[] = [
                'session_dish_id' => (int) $dish->session_dish_id,
                'name' => $dish->dish_name,
                'quantity' => $quantity,
                'unit_price' => (float) $dish->price,
                'sub_total' => $subTotal,
            ];

            $total = round($total + $subTotal, 2);
        }

        // Always reserve in the same order (lowest id first). Two customers who
        // order the same dishes in a different order would otherwise lock the
        // rows crosswise and MySQL kills one of them with a deadlock.
        usort($lines, fn (array $a, array $b) => $a['session_dish_id'] <=> $b['session_dish_id']);

        // The proof of payment a bank or e-wallet customer uploaded. Cash on
        // pickup sends none, and nothing is stored for it.
        $proof = $request->file('proof_of_payment')?->store('payments', 'public');

        try {
            $orderNumber = '';
            $orderId = $this->writeOrder($validated, $lines, $total, $session, $proof, $orderNumber);
        } catch (StockRanOutException $exception) {
            $this->discardProof($proof);

            return $this->stockShortResponse($session, $exception->getMessage());
        } catch (Throwable $exception) {
            // Nothing was written, so the uploaded picture would stay forever.
            $this->discardProof($proof);

            throw $exception;
        }

        // Tell the customer (and the watchers). A queued job does the work and it can
        // never fail the order: see OrderNotifier.
        OrderNotifier::placed($orderId);
        OrderNotifier::soldOut(array_column($lines, 'session_dish_id'), (int) $session->session_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Order ' . $orderNumber . ' placed successfully.',
            'order_number' => $orderNumber,
            'total_amount' => $total,
            // The menu moves its stock numbers on with this, so the cards show
            // what is left after the order.
            'stock' => SessionDish::stockOfSession((int) $session->session_id),
        ]);
    }

    // Reserve the stock and write the order, its lines and its payment in one
    // transaction, and return the new order's id ($orderNumber receives its
    // number). It is all or nothing.
    protected function writeOrder(array $validated, array $lines, float $total, object $session, ?string $proof, string &$orderNumber): int
    {
        $orderId = 0;

        // Order numbers are "last one + 1", so two orders placed in the same
        // instant can pick the same number and the unique index refuses the
        // second. That is not an error for the customer: try again with the next
        // number (the whole transaction restarts, so the stock is taken once).
        for ($attempt = 1; ; $attempt++) {
            try {
                // The second argument retries the transaction when MySQL picks it
                // as the victim of a deadlock.
                DB::transaction(function () use ($validated, $lines, $total, $session, $proof, &$orderNumber, &$orderId) {
                    foreach ($lines as $line) {
                        // The stock is re-checked inside the statement, so two orders
                        // cannot take the last plate at the same time.
                        if (! SessionDish::reserve($line['session_dish_id'], $line['quantity'])) {
                            throw new StockRanOutException($line['name'] . ' ran out while you were checking out.');
                        }
                    }

                    // Read inside the transaction, as close to the insert as possible.
                    $orderNumber = Order::nextOrderNumber();

                    $orderId = Order::createOrder([
                        'session_id' => (int) $session->session_id,
                        'user_id' => auth()->id(),
                        'order_number' => $orderNumber,
                        'order_date' => now(),
                        'total_amount' => $total,
                        'order_status' => 'pending',
                        'payment_status' => 'pending',
                        'is_active' => true,
                    ]);

                    OrderItem::createItems($orderId, $lines);

                    // The payment row is written with the order, so it is always
                    // there to be verified. Cash on pickup sends nothing else; a
                    // bank or e-wallet pays carries the reference and the proof the
                    // customer uploaded.
                    Payment::createForOrder(
                        $orderId,
                        (int) $validated['payment_method_id'],
                        $total,
                        $validated['reference_number'] ?? null,
                        $proof
                    );
                }, 3);

                return $orderId;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 5) {
                    throw $exception;
                }
            }
        }
    }

    // A 422 for "not enough of a dish" (or "the price changed") that also carries
    // the stock and the prices as they are right now. The menu applies them on
    // the spot, so the customer sees the real numbers and fixes the cart instead
    // of retrying into the same refusal.
    protected function stockShortResponse(object $session, string $message)
    {
        return response()->json([
            'status' => 'error',
            'message' => $message . ' Please review your cart.',
            'stock' => SessionDish::stockOfSession((int) $session->session_id),
            'prices' => SessionDish::pricesOfSession((int) $session->session_id),
        ], 422);
    }

    // Remove an uploaded proof when its order was not written.
    protected function discardProof(?string $path): void
    {
        // Fixture / absolute URLs are not files of ours.
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    // Rules and normalisation for placing an order.
    protected function validateOrder(Request $request): array
    {
        $items = [];

        foreach ((array) $request->input('items', []) as $sessionDishId => $quantity) {
            if (is_numeric($sessionDishId) && (int) $quantity > 0) {
                $items[(int) $sessionDishId] = (int) $quantity;
            }
        }

        $request->merge([
            'session_id' => (int) $request->input('session_id'),
            'items' => $items,
        ]);

        // Cash on pickup is paid at the counter, so it sends no proof. Any other
        // method (bank, e-wallet, ...) is paid first and must prove it.
        $method = PaymentMethod::activeOptions()
            ->firstWhere('method_id', (int) $request->input('payment_method_id'));
        $proofRule = $method && PaymentMethod::isCashOnPickup($method) ? 'nullable' : 'required';

        return $request->validate([
            'session_id' => ['required', 'integer'],
            // Only a method that is active and not deleted right now: the old
            // `exists` rule also accepted a method an admin had just turned off.
            'payment_method_id' => ['required', 'integer', Rule::in(PaymentMethod::activeOptions()->pluck('method_id')->map(fn ($id) => (int) $id)->all())],
            // Sent by the menu once per checkout; see store().
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            // expected_prices[session_dish_id]: the unit price the cart showed.
            'expected_prices' => ['nullable', 'array'],
            'expected_prices.*' => ['numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            // The screenshot a bank or e-wallet customer sends as proof. It is
            // required for every method but cash on pickup; the payment stays
            // pending until someone verifies it.
            'proof_of_payment' => [$proofRule, 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'min:1'],
        ], [
            'items.required' => 'Add at least one dish to the cart.',
            'items.min' => 'Add at least one dish to the cart.',
            'payment_method_id.required' => 'Choose a payment method.',
            'payment_method_id.in' => 'That payment method is not available any more. Choose another one.',
            'proof_of_payment.required' => 'Upload the proof of payment for this payment method.',
            'proof_of_payment.image' => 'The proof of payment has to be an image.',
            'proof_of_payment.max' => 'The proof of payment may not be larger than 2 MB.',
        ]);
    }
}
