<?php

namespace App\Http\Controllers;

use App\Models\BulkOrder;
use App\Models\BulkOrderItem;
use App\Models\BulkSession;
use App\Models\BulkSessionDish;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

// Bulk orders, the customer side: the bulk menu of the bulk session that is
// running, the orders placed from it, and the customer's own list. Same guards as
// OrderController (a lock per customer, an idempotency key, a price check), but
// there is NO stock to reserve: a bulk order is refused only when the session is
// closed, a dish left it, a price changed, or it is smaller than the minimum.
class BulkOrderController extends Controller implements HasMiddleware
{
    // Most plates of one dish in one order. Far above any real order; it only stops a
    // typo (or a script) from writing an absurd total.
    protected const MAX_LINE_QUANTITY = 10000;

    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view bulk-menu', only: ['index']),
            new Middleware('permission:create bulk-orders', only: ['store']),
            new Middleware('permission:view my bulk-orders', only: ['mine']),
        ];
    }

    // The bulk menu: the dishes of the running bulk session.
    public function index()
    {
        $session = BulkSession::live();

        return view('bulk-menu.index', [
            'session' => $session,
            // A dish that is turned off, or left the session, is not offered.
            'dishes' => $session ? BulkSessionDish::offeredForSession($session->bulk_session_id) : collect(),
            // The fewest / most plates one order may hold (System Configuration + the session).
            'limits' => $session ? $this->limitsFor($session) : ['min' => 0, 'max' => null],
            // The same two ways to pay as the regular checkout: cash on pickup, and
            // bank / e-wallet with a reference and a proof.
            'cash' => PaymentMethod::cashOption(),
            'methods' => PaymentMethod::bankOptions(),
        ]);
    }

    // The orders the signed-in customer placed in bulk sessions, newest first.
    public function mine()
    {
        $orders = BulkOrder::ofUser((int) auth()->id());

        foreach ($orders as $order) {
            $order->status_badge = $this->orderStatusBadge($order->order_status);
            $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
            $order->proof_url = Payment::proofUrl($order->proof_of_payment);
            $order->plates = array_sum(array_column($order->items, 'quantity'));
        }

        return view('bulk-my-orders.index', [
            'orders' => $orders,
        ]);
    }

    // Place a bulk order on the running bulk session. The lines arrive as
    // items[bulk_session_dish_id] = quantity, and the payment method is picked at
    // checkout.
    //
    // Like OrderController::store, this method only guards the door; placeOrder()
    // does the work:
    //  - one order at a time per customer (a lock), so a double tap or a second tab
    //    cannot create two orders;
    //  - the idempotency key the page sends: if the answer got lost on a bad
    //    connection and the customer taps again, they get the same receipt back
    //    instead of a second order and a second charge.
    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);
        $userId = (int) auth()->id();

        // Its own lock and receipt keys: a regular order in progress must not block
        // a bulk one, and a regular receipt must never answer a bulk request.
        $lock = Cache::lock('place-bulk-order:' . $userId, 30);

        if (! $lock->get()) {
            return $this->errorResponse('Your order is already being placed. Please wait a moment.', 409);
        }

        try {
            $key = $validated['idempotency_key'] ?? null;
            $cacheKey = 'bulk-order-receipt:' . $userId . ':' . $key;

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

    // The work of store(): check the bulk menu, then write the order.
    protected function placeOrder(Request $request, array $validated)
    {
        $session = BulkSession::findById($validated['bulk_session_id']);

        if (! $session) {
            return $this->errorResponse('Bulk session not found.', 404);
        }

        if ($session->status !== 'live') {
            return $this->errorResponse('This bulk session is not running.', 422);
        }

        $offered = BulkSessionDish::offeredForSession((int) $session->bulk_session_id)
            ->keyBy('bulk_session_dish_id');

        // The prices the customer saw (and, for a bank or e-wallet order, paid). The
        // order is charged at the price of NOW, so when an admin changed a price in
        // between, refuse and show the new one instead of saving an order whose total
        // differs from the payment. A client that sends no prices is not checked.
        $changed = [];

        foreach ($validated['items'] as $bulkSessionDishId => $quantity) {
            $dish = $offered->get((int) $bulkSessionDishId);
            $expected = $validated['expected_prices'][$bulkSessionDishId] ?? null;

            if ($dish && $expected !== null && round((float) $expected, 2) !== round((float) $dish->price, 2)) {
                $changed[] = $dish->dish_name . ' is now ₱' . number_format((float) $dish->price, 2)
                    . ' (was ₱' . number_format((float) $expected, 2) . ')';
            }
        }

        if ($changed) {
            return $this->reviewResponse($session, 'The price changed: ' . implode(', ', $changed) . '.');
        }

        $lines = [];
        $total = 0.0;
        $plates = 0;

        foreach ($validated['items'] as $bulkSessionDishId => $quantity) {
            $dish = $offered->get((int) $bulkSessionDishId);

            if (! $dish) {
                return $this->reviewResponse($session, 'One of the dishes is no longer on the bulk menu.');
            }

            // Cents, not floats: 3 x 0.10 must be 0.30 and not 0.30000000000000004.
            $subTotal = round((float) $dish->price * $quantity, 2);

            $lines[] = [
                'bulk_session_dish_id' => (int) $dish->bulk_session_dish_id,
                'quantity' => $quantity,
                'unit_price' => (float) $dish->price,
                'sub_total' => $subTotal,
            ];

            $total = round($total + $subTotal, 2);
            $plates += $quantity;
        }

        // The point of a bulk session. Judged on the limits as they are now (the
        // session and System Configuration), so a minimum raised or a maximum set
        // while the customer was checking out is applied.
        $limits = $this->limitsFor($session);

        if ($plates < $limits['min']) {
            return $this->reviewResponse(
                $session,
                'A bulk order needs at least ' . $limits['min'] . ' plates (you have ' . $plates . ').'
            );
        }

        if ($limits['max'] !== null && $plates > $limits['max']) {
            return $this->reviewResponse(
                $session,
                'A bulk order can hold at most ' . $limits['max'] . ' plates (you have ' . $plates . ').'
            );
        }

        // The proof of payment a bank or e-wallet customer uploaded. Cash on
        // pickup sends none, and nothing is stored for it.
        $proof = $request->file('proof_of_payment')?->store('payments', 'public');

        try {
            $orderNumber = '';
            $this->writeOrder($validated, $lines, $total, $session, $proof, $orderNumber);
        } catch (Throwable $exception) {
            // Nothing was written, so the uploaded picture would stay forever.
            $this->discardProof($proof);

            throw $exception;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Order ' . $orderNumber . ' placed successfully.',
            'order_number' => $orderNumber,
            'total_amount' => $total,
            'plates' => $plates,
        ]);
    }

    // Write the order and its lines in one transaction ($orderNumber receives its
    // number). It is all or nothing; the payment is a part of the order row.
    protected function writeOrder(array $validated, array $lines, float $total, object $session, ?string $proof, string &$orderNumber): int
    {
        $orderId = 0;

        // Order numbers are "last one + 1", so two orders placed in the same instant
        // can pick the same number and the unique index refuses the second. That is
        // not an error for the customer: try again with the next number.
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($validated, $lines, $total, $session, $proof, &$orderNumber, &$orderId) {
                    // Read inside the transaction, as close to the insert as possible.
                    $orderNumber = BulkOrder::nextOrderNumber();

                    $orderId = BulkOrder::createOrder([
                        'bulk_session_id' => (int) $session->bulk_session_id,
                        'user_id' => auth()->id(),
                        'order_number' => $orderNumber,
                        'order_date' => now(),
                        'total_amount' => $total,
                        'order_status' => 'pending',
                        'payment_method_id' => (int) $validated['payment_method_id'],
                        'payment_status' => 'pending',
                        'reference_number' => $validated['reference_number'] ?? null,
                        'proof_of_payment' => $proof,
                        'is_active' => true,
                    ]);

                    BulkOrderItem::createItems($orderId, $lines);
                }, 3);

                return $orderId;
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt >= 5) {
                    throw $exception;
                }
            }
        }
    }

    // A 422 for "review your cart" (a price changed, a dish left, below the minimum)
    // that carries the prices and the minimum as they are right now, so the page can
    // apply them on the spot instead of retrying into the same refusal.
    protected function reviewResponse(object $session, string $message)
    {
        $limits = $this->limitsFor($session);

        return response()->json([
            'status' => 'error',
            'message' => $message . ' Please review your cart.',
            'prices' => BulkSessionDish::pricesOfSession((int) $session->bulk_session_id),
            'min_quantity' => $limits['min'],
            'max_quantity' => $limits['max'],
        ], 422);
    }

    // The plates one order of this session may hold. The minimum is the larger of the
    // session's own and the system's (a session may ask for more, never for less); the
    // maximum is the system's, or null for no limit. A session minimum above the
    // maximum (the maximum was lowered afterwards) is brought down to it, so the
    // session never becomes impossible to order from.
    protected function limitsFor(object $session): array
    {
        $limits = Setting::bulkLimits();
        $min = max((int) $session->min_quantity, $limits['min']);

        if ($limits['max'] !== null) {
            $min = min($min, $limits['max']);
        }

        return ['min' => $min, 'max' => $limits['max']];
    }

    // Remove an uploaded proof when its order was not written.
    protected function discardProof(?string $path): void
    {
        // Fixture / absolute URLs are not files of ours.
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    // Rules and normalisation for placing a bulk order.
    protected function validateOrder(Request $request): array
    {
        $items = [];

        foreach ((array) $request->input('items', []) as $bulkSessionDishId => $quantity) {
            if (is_numeric($bulkSessionDishId) && (int) $quantity > 0) {
                $items[(int) $bulkSessionDishId] = (int) $quantity;
            }
        }

        $request->merge([
            'bulk_session_id' => (int) $request->input('bulk_session_id'),
            'items' => $items,
        ]);

        // Cash on pickup is paid at the counter, so it sends no proof. Any other
        // method (bank, e-wallet, ...) is paid first and must prove it.
        $method = PaymentMethod::activeOptions()
            ->firstWhere('method_id', (int) $request->input('payment_method_id'));
        $proofRule = $method && PaymentMethod::isCashOnPickup($method) ? 'nullable' : 'required';

        return $request->validate([
            'bulk_session_id' => ['required', 'integer'],
            // Only a method that is active and not deleted right now.
            'payment_method_id' => ['required', 'integer', Rule::in(PaymentMethod::activeOptions()->pluck('method_id')->map(fn ($id) => (int) $id)->all())],
            // Sent by the page once per checkout; see store().
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            // expected_prices[bulk_session_dish_id]: the unit price the cart showed.
            'expected_prices' => ['nullable', 'array'],
            'expected_prices.*' => ['numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'proof_of_payment' => [$proofRule, 'file', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:2048'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['integer', 'min:1', 'max:' . self::MAX_LINE_QUANTITY],
        ], [
            'items.required' => 'Add at least one dish to the order.',
            'items.min' => 'Add at least one dish to the order.',
            'items.*.max' => 'That is too many plates of one dish for a single order.',
            'payment_method_id.required' => 'Choose a payment method.',
            'payment_method_id.in' => 'That payment method is not available any more. Choose another one.',
            'proof_of_payment.required' => 'Upload the proof of payment for this payment method.',
            'proof_of_payment.image' => 'The proof of payment has to be an image.',
            'proof_of_payment.max' => 'The proof of payment may not be larger than 2 MB.',
        ]);
    }
}
