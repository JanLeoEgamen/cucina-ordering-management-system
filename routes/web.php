<?php

use App\Http\Controllers\BulkOrderController;
use App\Http\Controllers\BulkSessionController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\DishTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSessionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WelcomeController;
use App\Models\OrderSession;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;

// The public landing page.
Route::get('/', WelcomeController::class)->name('welcome');

Route::get('/dashboard', function () {
    return view('dashboard', [
        // The session that is running right now, and - for whoever checks the
        // money - how many payments are waiting for a decision.
        'live' => OrderSession::live(),
        'pendingPayments' => auth()->user()->can('view payments') ? Payment::pendingCount() : null,
    ]);
})->middleware(['auth', 'profile.complete', 'verified'])->name('dashboard');

// `profile.complete`: a user who signed up with Google is held on /complete-profile first.
Route::middleware(['auth', 'profile.complete'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Dish Types: index serves the page and (on an AJAX request) the table feed.
    Route::get('/dish-types', [DishTypeController::class, 'index'])->name('dish-types.index');
    Route::post('/dish-types', [DishTypeController::class, 'store'])->name('dish-types.store');
    Route::get('/dish-types/{id}/edit', [DishTypeController::class, 'edit'])
        ->whereNumber('id')->name('dish-types.edit');
    Route::put('/dish-types/{id}', [DishTypeController::class, 'update'])
        ->whereNumber('id')->name('dish-types.update');
    Route::delete('/dish-types/{id}', [DishTypeController::class, 'destroy'])
        ->whereNumber('id')->name('dish-types.destroy');

    // Dishes
    Route::get('/dishes', [DishController::class, 'index'])->name('dishes.index');
    Route::post('/dishes', [DishController::class, 'store'])->name('dishes.store');
    Route::get('/dishes/{id}/edit', [DishController::class, 'edit'])
        ->whereNumber('id')->name('dishes.edit');
    Route::put('/dishes/{id}', [DishController::class, 'update'])
        ->whereNumber('id')->name('dishes.update');
    Route::delete('/dishes/{id}', [DishController::class, 'destroy'])
        ->whereNumber('id')->name('dishes.destroy');

    // Payment methods: what the checkout offers to pay with.
    Route::get('/payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
    Route::post('/payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
    Route::get('/payment-methods/{id}/edit', [PaymentMethodController::class, 'edit'])
        ->whereNumber('id')->name('payment-methods.edit');
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update'])
        ->whereNumber('id')->name('payment-methods.update');
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy'])
        ->whereNumber('id')->name('payment-methods.destroy');

    // Payments: every payment of every order. {id} is the order id.
    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments/{id}/verify', [PaymentController::class, 'verify'])
        ->whereNumber('id')->name('payments.verify');
    Route::post('/payments/{id}/reject', [PaymentController::class, 'reject'])
        ->whereNumber('id')->name('payments.reject');
    Route::post('/payments/{id}/undo', [PaymentController::class, 'undo'])
        ->whereNumber('id')->name('payments.undo');
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy'])
        ->whereNumber('id')->name('payments.destroy');

    // Order sessions: index serves the page and (on an AJAX request) the feed.
    Route::get('/order-sessions', [OrderSessionController::class, 'index'])->name('order-sessions.index');
    Route::post('/order-sessions', [OrderSessionController::class, 'store'])->name('order-sessions.store');
    // One session on its own page: facts, stock and its orders (table / cards).
    Route::get('/order-sessions/{id}', [OrderSessionController::class, 'show'])
        ->whereNumber('id')->name('order-sessions.show');
    Route::get('/order-sessions/{id}/edit', [OrderSessionController::class, 'edit'])
        ->whereNumber('id')->name('order-sessions.edit');
    Route::put('/order-sessions/{id}', [OrderSessionController::class, 'update'])
        ->whereNumber('id')->name('order-sessions.update');
    // Ending is a state change of its own: the creator closes the session by hand.
    Route::post('/order-sessions/{id}/end', [OrderSessionController::class, 'end'])
        ->whereNumber('id')->name('order-sessions.end');
    Route::delete('/order-sessions/{id}', [OrderSessionController::class, 'destroy'])
        ->whereNumber('id')->name('order-sessions.destroy');

    // Bulk sessions (staff): index serves the page and (on an AJAX request) the feed.
    Route::get('/bulk-sessions', [BulkSessionController::class, 'index'])->name('bulk-sessions.index');
    Route::post('/bulk-sessions', [BulkSessionController::class, 'store'])->name('bulk-sessions.store');
    Route::get('/bulk-sessions/{id}', [BulkSessionController::class, 'show'])
        ->whereNumber('id')->name('bulk-sessions.show');
    Route::get('/bulk-sessions/{id}/edit', [BulkSessionController::class, 'edit'])
        ->whereNumber('id')->name('bulk-sessions.edit');
    Route::put('/bulk-sessions/{id}', [BulkSessionController::class, 'update'])
        ->whereNumber('id')->name('bulk-sessions.update');
    Route::post('/bulk-sessions/{id}/end', [BulkSessionController::class, 'end'])
        ->whereNumber('id')->name('bulk-sessions.end');
    Route::delete('/bulk-sessions/{id}', [BulkSessionController::class, 'destroy'])
        ->whereNumber('id')->name('bulk-sessions.destroy');

    // Bulk orders (customer): the bulk menu of the running bulk session, the orders placed
    // from it (throttled per user like the regular ones) and the customer's own list.
    Route::get('/bulk-menu', [BulkOrderController::class, 'index'])->name('bulk-menu.index');
    Route::post('/bulk-orders', [BulkOrderController::class, 'store'])->middleware('throttle:10,1')->name('bulk-orders.store');
    Route::get('/bulk-my-orders', [BulkOrderController::class, 'mine'])->name('bulk-my-orders.index');

    // The menu: the dishes of the running session, and the orders placed from it.
    Route::get('/menu', [OrderController::class, 'index'])->name('menu.index');
    // Live stock for the menu cards (polled every few seconds; AJAX only).
    Route::get('/menu/stock', [OrderController::class, 'stock'])->name('menu.stock');
    // Throttled per user: a stuck key or a script cannot hammer the stock rows.
    Route::post('/orders', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('orders.store');
    // Every order of every session (page, and its table feed on an AJAX request).
    Route::get('/orders', [OrderController::class, 'all'])->name('orders.index');
    // Staff close an order once it is handed over: pending -> completed.
    Route::post('/orders/{id}/complete', [OrderController::class, 'complete'])
        ->whereNumber('id')->name('orders.complete');
    // ...or call it off: pending -> cancelled, and its dishes go back on the menu.
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])
        ->whereNumber('id')->name('orders.cancel');
    // The customer's own orders: what they placed, and how they paid.
    Route::get('/my-orders', [OrderController::class, 'mine'])->name('my-orders.index');
    // A rejected payment can be fixed by its customer: a new proof, or cash on pickup.
    Route::post('/my-orders/{id}/payment/proof', [OrderController::class, 'resubmitProof'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.payment.proof');
    Route::post('/my-orders/{id}/payment/cash', [OrderController::class, 'switchToCash'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.payment.cash');

    // The signed-in user's own notifications. No permission (like the profile): every
    // query is scoped to the user. The feed is what the bell polls.
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])
        ->whereNumber('id')->name('notifications.open');

    // System configuration (the default order session window, ...): view settings / edit settings.
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

    // Roles: permissions (view roles, create roles, ...) are checked in the controller.
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('/roles/{id}/edit', [RoleController::class, 'edit'])
        ->whereNumber('id')->name('roles.edit');
    Route::put('/roles/{id}', [RoleController::class, 'update'])
        ->whereNumber('id')->name('roles.update');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])
        ->whereNumber('id')->name('roles.destroy');

    // Users
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{id}/edit', [UserController::class, 'edit'])
        ->whereNumber('id')->name('users.edit');
    Route::put('/users/{id}', [UserController::class, 'update'])
        ->whereNumber('id')->name('users.update');
    Route::delete('/users/{id}', [UserController::class, 'destroy'])
        ->whereNumber('id')->name('users.destroy');
});

require __DIR__.'/auth.php';
