<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\SessionDish;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// The ordering side: the menu of the session that is running, and the orders
// placed from it. The order session module owns the sessions themselves.
class OrderController extends Controller implements HasMiddleware
{
    // Each method only runs with the matching permission.
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view menu', only: ['index']),
            new Middleware('permission:create orders', only: ['store']),
            new Middleware('permission:view my orders', only: ['mine']),
        ];
    }

    // The orders the signed-in customer placed, newest first.
    public function mine()
    {
        $orders = Order::ofUser((int) auth()->id());

        // The badges are rendered here, so the view prints markup instead of
        // building it, and the proof travels as a URL the card can link to.
        foreach ($orders as $order) {
            $order->status_badge = $this->orderStatusBadge($order->order_status);
            $order->payment_badge = $this->paymentStatusBadge($order->payment_status);
            $order->proof_url = Payment::proofUrl($order->proof_of_payment);
        }

        return view('my-orders.index', [
            'orders' => $orders,
        ]);
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

    // Place an order on the running session. The lines arrive as
    // items[session_dish_id] = quantity, and the payment method is picked at
    // checkout. Stock is taken in the same request, so the menu cannot oversell.
    public function store(Request $request)
    {
        $validated = $this->validateOrder($request);

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

        $lines = [];
        $total = 0.0;

        foreach ($validated['items'] as $sessionDishId => $quantity) {
            $dish = $offered->get((int) $sessionDishId);

            if (! $dish) {
                return $this->errorResponse('One of the dishes is no longer on the menu.', 422);
            }

            if ($quantity > $dish->available_stock) {
                return $this->errorResponse('Only ' . $dish->available_stock . ' left of ' . $dish->dish_name . '.', 422);
            }

            $subTotal = (float) $dish->price * $quantity;

            $lines[] = [
                'session_dish_id' => (int) $dish->session_dish_id,
                'quantity' => $quantity,
                'unit_price' => (float) $dish->price,
                'sub_total' => $subTotal,
            ];

            $total += $subTotal;
        }

        $orderNumber = Order::nextOrderNumber();

        // The proof of payment a bank or e-wallet customer uploaded. Cash on
        // pickup sends none, and nothing is stored for it.
        $proof = $request->file('proof_of_payment')?->store('payments', 'public');

        try {
            // One transaction: the order, its lines, its payment and the stock
            // it takes all land together, or none of them do.
            DB::transaction(function () use ($validated, $lines, $total, $session, $orderNumber, $proof) {
                foreach ($lines as $line) {
                    // The stock is re-checked inside the statement, so two orders
                    // cannot take the last plate at the same time.
                    if (! SessionDish::reserve($line['session_dish_id'], $line['quantity'])) {
                        throw new RuntimeException('A dish ran out while you were checking out. Please review your cart.');
                    }
                }

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
            });
        } catch (RuntimeException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        }

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
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,method_id'],
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
            'proof_of_payment.required' => 'Upload the proof of payment for this payment method.',
            'proof_of_payment.image' => 'The proof of payment has to be an image.',
            'proof_of_payment.max' => 'The proof of payment may not be larger than 2 MB.',
        ]);
    }
}
