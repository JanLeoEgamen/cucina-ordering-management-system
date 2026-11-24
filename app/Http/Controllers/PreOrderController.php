<?php

namespace App\Http\Controllers;

use App\Exceptions\AlaCarteRefusedException;
use App\Exceptions\DiscountRefusedException;
use App\Models\PreOrder;
use App\Models\PreOrderItem;
use App\Models\PreOrderSession;
use App\Models\PreOrderSessionDish;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Setting;
use App\Support\AlaCarte;
use App\Support\OrderDiscount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

// Pre-orders, the customer side: the pre-order menu of the pre-order session that is
// running, the orders placed from it, and the customer's own list. Same guards as
// OrderController (a lock per customer, an idempotency key, a price check), but
// there is NO stock to reserve: a pre-order is refused only when the session is
// closed, a dish left it, a price changed, or it is smaller than the minimum.
class PreOrderController extends Controller implements HasMiddleware
{
    // Most plates of one dish in one order. Far above any real order; it only stops a
    // typo (or a script) from writing an absurd total.
    protected const MAX_LINE_QUANTITY = 10000;

    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view pre-order-menu', only: ['index']),
            new Middleware('permission:create pre-orders', only: ['store']),
            new Middleware('permission:view my pre-orders', only: ['mine']),
        ];
    }

    // The pre-order menu: the dishes of the running pre-order session.
    public function index()
    {
        $session = PreOrderSession::live();
        $dishes = $session ? PreOrderSessionDish::offeredForSession($session->pre_order_session_id) : collect();

        return view('pre-order-menu.index', [
            'session' => $session,
            // A dish that is turned off, or left the session, is not offered.
            'dishes' => $dishes,
            // Whether a plate can be ordered without rice, the deduction, and which dishes (see AlaCarte).
            'alaCarte' => AlaCarte::state('pre_orders', $dishes, 'pre_order_session_dish_id'),
            // The fewest / most plates one order may hold (System Configuration + the session).
            'limits' => $session ? $this->limitsFor($session) : ['min' => 0, 'max' => null],
            // The same two ways to pay as the regular checkout: cash on pickup, and
            // bank / e-wallet with a reference and a proof.
            'cash' => PaymentMethod::cashOption(),
            'methods' => PaymentMethod::bankOptions(),
            // Empty while discounts are off for pre-orders: the checkout then draws no picker.
            'discounts' => OrderDiscount::offered('pre_orders'),
        ]);
    }

    // The orders the signed-in customer placed in pre-order sessions, newest first.
    public function mine(Request $request)
    {
        $userId = (int) auth()->id();

        // "?order=ID" goes to the page that holds that order, at its card (the way a
        // notification links to one; an id that is not the customer's is ignored).
        if ($request->filled('order')) {
            $orderId = (int) $request->query('order');
            $page = PreOrder::pageNumberOf($userId, $orderId);

            if ($page !== null) {
                return redirect(route('my-pre-orders.index', $page > 1 ? ['page' => $page] : []) . '#pre-order-' . $orderId);
            }
        }

        $orders = PreOrder::pageOfUser($userId);

        // A page past the end (an old link, orders deleted): the last one.
        if ($orders->currentPage() > $orders->lastPage()) {
            return redirect($orders->url($orders->lastPage()));
        }

        foreach ($orders as $order) {
            $order->status_badge = $this->orderStatusBadge($order->order_status);
            $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
            $order->proof_url = Payment::proofUrl($order->proof_of_payment);
            $order->plates = array_sum(array_column($order->items, 'quantity'));

            // What a click on the card opens (the order popup).
            $order->popup = $this->customerOrderPopup($order, ($order->session_title ?: __('Pre-order session'))
                . ' · ' . Carbon::parse($order->session_date)->format('M j, Y')
                . ' · ' . substr((string) $order->start_time, 0, 5) . ' – ' . substr((string) $order->end_time, 0, 5));
        }

        return view('my-pre-orders.index', [
            'orders' => $orders,
        ]);
    }

    // Place a pre-order on the running pre-order session. The lines arrive as
    // items[pre_order_session_dish_id] = quantity, and the payment method is picked at
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
        // a pre-order one, and a regular receipt must never answer a pre-order request.
        $lock = Cache::lock('place-pre-order:' . $userId, 30);

        if (! $lock->get()) {
            return $this->errorResponse('Your order is already being placed. Please wait a moment.', 409);
        }

        try {
            $key = $validated['idempotency_key'] ?? null;
            $cacheKey = 'pre-order-receipt:' . $userId . ':' . $key;

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

    // The work of store(): check the pre-order menu, then write the order.
    protected function placeOrder(Request $request, array $validated)
    {
        $session = PreOrderSession::findById($validated['pre_order_session_id']);

        if (! $session) {
            return $this->errorResponse('Pre-order session not found.', 404);
        }

        if ($session->status !== 'live') {
            return $this->errorResponse('This pre-order session is not running.', 422);
        }

        $offered = PreOrderSessionDish::offeredForSession((int) $session->pre_order_session_id)
            ->keyBy('pre_order_session_dish_id');

        // The prices the customer saw (and, for a bank or e-wallet order, paid). The
        // order is charged at the price of NOW, so when an admin changed a price in
        // between, refuse and show the new one instead of saving an order whose total
        // differs from the payment. A client that sends no prices is not checked.
        $changed = [];

        foreach ($validated['items'] as $preOrderSessionDishId => $quantity) {
            $dish = $offered->get((int) $preOrderSessionDishId);
            $expected = $validated['expected_prices'][$preOrderSessionDishId] ?? null;

            if ($dish && $expected !== null && round((float) $expected, 2) !== round((float) $dish->price, 2)) {
                $changed[] = $dish->dish_name . ' is now ₱' . number_format((float) $dish->price, 2)
                    . ' (was ₱' . number_format((float) $expected, 2) . ')';
            }
        }

        if ($changed) {
            return $this->reviewResponse($session, 'The price changed: ' . implode(', ', $changed) . '.');
        }

        // The plates the customer wants WITHOUT rice (ala carte), judged from the settings and the dish
        // flags found now; the server prices them itself (see AlaCarte).
        try {
            $withoutRice = AlaCarte::judge('pre_orders', $validated, $offered);
        } catch (AlaCarteRefusedException $refused) {
            return $this->reviewResponse($session, $refused->getMessage());
        }

        $rice = Setting::ricePrice();

        $lines = [];
        $total = 0.0;
        $plates = 0;

        foreach ($validated['items'] as $preOrderSessionDishId => $quantity) {
            $dish = $offered->get((int) $preOrderSessionDishId);

            if (! $dish) {
                return $this->reviewResponse($session, 'One of the dishes is no longer on the pre-order menu.');
            }

            // One line per kind of plate: with rice at the dish's price, without at the price less the
            // rice. Cents, not floats: 3 x 0.10 must be 0.30 and not 0.30000000000000004.
            foreach (AlaCarte::lines((float) $dish->price, (int) $quantity, $withoutRice[(int) $preOrderSessionDishId] ?? 0, $rice) as $split) {
                $lines[] = ['pre_order_session_dish_id' => (int) $dish->pre_order_session_dish_id] + $split;

                $total = round($total + $split['sub_total'], 2);
            }

            $plates += $quantity;
        }

        // The point of a pre-order session. Judged on the limits as they are now (the
        // session and System Configuration), so a minimum raised or a maximum set
        // while the customer was checking out is applied.
        $limits = $this->limitsFor($session);

        if ($plates < $limits['min']) {
            return $this->reviewResponse(
                $session,
                'A pre-order needs at least ' . $limits['min'] . ' plates (you have ' . $plates . ').'
            );
        }

        if ($limits['max'] !== null && $plates > $limits['max']) {
            return $this->reviewResponse(
                $session,
                'A pre-order can hold at most ' . $limits['max'] . ' plates (you have ' . $plates . ').'
            );
        }

        // The discount the customer picked (System Configuration decides whether any is offered
        // for pre-orders). Same rules as a regular order: the server does the math from the percent it
        // finds now, and refuses a pick that went away or whose percent moved.
        $subtotal = $total;

        try {
            $resolved = OrderDiscount::resolve('pre_orders', $validated, $request, $subtotal);
        } catch (DiscountRefusedException $refused) {
            return $this->reviewResponse($session, $refused->getMessage());
        }

        $discountFields = $resolved['fields'];
        $total = $resolved['total'];

        // The proof of payment a bank or e-wallet customer uploaded. Cash on
        // pickup sends none, and nothing is stored for it.
        $proof = $request->file('proof_of_payment')?->store('payments', 'public');

        // The ID for the discount goes to the PRIVATE disk, never the public one.
        $discountProof = OrderDiscount::storeProof($request, $resolved['discount'], 'pre-order-discount-proofs');

        if ($discountProof) {
            $discountFields['discount_proof'] = $discountProof;
        }

        try {
            $orderNumber = '';
            $this->writeOrder($validated, $lines, $total, $session, $proof, $orderNumber, $discountFields);
        } catch (Throwable $exception) {
            // Nothing was written, so the uploaded pictures would stay forever.
            $this->discardProof($proof);
            OrderDiscount::discardProof($discountProof);

            throw $exception;
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Order ' . $orderNumber . ' placed successfully.',
            'order_number' => $orderNumber,
            'total_amount' => $total,
            'plates' => $plates,
            // What the receipt shows when a discount was used.
            'subtotal_amount' => $subtotal,
            'discount_name' => $discountFields['discount_name'] ?? null,
            'discount_amount' => $discountFields['discount_amount'] ?? 0,
            'discount_status' => $discountFields['discount_status'] ?? 'none',
        ]);
    }

    // Write the order and its lines in one transaction ($orderNumber receives its
    // number). It is all or nothing; the payment is a part of the order row.
    protected function writeOrder(array $validated, array $lines, float $total, object $session, ?string $proof, string &$orderNumber, array $discountFields = []): int
    {
        $orderId = 0;

        // Order numbers are "last one + 1", so two orders placed in the same instant
        // can pick the same number and the unique index refuses the second. That is
        // not an error for the customer: try again with the next number.
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function () use ($validated, $lines, $total, $session, $proof, &$orderNumber, &$orderId, $discountFields) {
                    // Read inside the transaction, as close to the insert as possible.
                    $orderNumber = PreOrder::nextOrderNumber();

                    $orderId = PreOrder::createOrder($discountFields + [
                        'pre_order_session_id' => (int) $session->pre_order_session_id,
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

                    PreOrderItem::createItems($orderId, $lines);
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
            'prices' => PreOrderSessionDish::pricesOfSession((int) $session->pre_order_session_id),
            'min_quantity' => $limits['min'],
            'max_quantity' => $limits['max'],
            // The discounts on offer now: the page drops the one that went away and takes the new percent.
            'discounts' => OrderDiscount::offered('pre_orders'),
            // Ala carte as it is now: the page takes the new rice price and drops "no rice" from a dish
            // that can no longer go without it.
            'ala_carte' => $this->alaCarteState((int) $session->pre_order_session_id),
        ], 422);
    }

    // AlaCarte::state() for the session's menu; the menu is only read while the choice is on.
    protected function alaCarteState(int $preOrderSessionId): array
    {
        $settings = AlaCarte::settings('pre_orders');

        if (! $settings['enabled']) {
            return $settings + ['dishes' => []];
        }

        return AlaCarte::state('pre_orders', PreOrderSessionDish::offeredForSession($preOrderSessionId), 'pre_order_session_dish_id');
    }

    // The plates one order of this session may hold. The minimum is the larger of the
    // session's own and the system's (a session may ask for more, never for less); the
    // maximum is the system's, or null for no limit. A session minimum above the
    // maximum (the maximum was lowered afterwards) is brought down to it, so the
    // session never becomes impossible to order from.
    protected function limitsFor(object $session): array
    {
        return PreOrderSession::limitsFor($session);
    }

    // Remove an uploaded proof when its order was not written.
    protected function discardProof(?string $path): void
    {
        // Fixture / absolute URLs are not files of ours.
        if ($path && ! str_starts_with($path, 'http')) {
            Storage::disk('public')->delete($path);
        }
    }

    // Rules and normalisation for placing a pre-order.
    protected function validateOrder(Request $request): array
    {
        $items = [];

        foreach ((array) $request->input('items', []) as $preOrderSessionDishId => $quantity) {
            if (is_numeric($preOrderSessionDishId) && (int) $quantity > 0) {
                $items[(int) $preOrderSessionDishId] = (int) $quantity;
            }
        }

        $request->merge([
            'pre_order_session_id' => (int) $request->input('pre_order_session_id'),
            'items' => $items,
            'ala_carte' => AlaCarte::clean($request->input('ala_carte')),
        ]);

        // Cash on pickup is paid at the counter, so it sends no proof. Any other
        // method (bank, e-wallet, ...) is paid first and must prove it.
        $method = PaymentMethod::activeOptions()
            ->firstWhere('method_id', (int) $request->input('payment_method_id'));
        $proofRule = $method && PaymentMethod::isCashOnPickup($method) ? 'nullable' : 'required';

        return $request->validate([
            'pre_order_session_id' => ['required', 'integer'],
            // Only a method that is active and not deleted right now.
            'payment_method_id' => ['required', 'integer', Rule::in(PaymentMethod::activeOptions()->pluck('method_id')->map(fn ($id) => (int) $id)->all())],
            // Sent by the page once per checkout; see store().
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            // expected_prices[pre_order_session_dish_id]: the unit price the cart showed.
            'expected_prices' => ['nullable', 'array'],
            'expected_prices.*' => ['numeric', 'min:0'],
            // The discount the customer picked, the percent the cart showed, and the ID (judged in placeOrder()).
            ...OrderDiscount::rules(),
            // How many plates are WITHOUT rice, and the rice price the cart showed (judged in placeOrder()).
            ...AlaCarte::rules(),
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
        ] + OrderDiscount::messages());
    }
}
