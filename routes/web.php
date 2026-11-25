<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\PreOrderController;
use App\Http\Controllers\PreOrderSessionController;
use App\Http\Controllers\DishController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\DishTypeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSessionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\OrderDiscountController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WelcomeController;
use App\Models\OrderSession;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;

// The public landing page.
Route::get('/', WelcomeController::class)->name('welcome');

// The help and support page: public (visitors who cannot sign in need it too); its text and
// contact details are settings.
Route::get('/support', SupportController::class)->name('support');

// The legal pages: public (the sign in / sign up pages link to them); their text is a setting.
Route::get('/privacy-policy', [LegalPageController::class, 'show'])->defaults('page', 'privacy-policy')->name('privacy-policy');
Route::get('/terms-of-service', [LegalPageController::class, 'show'])->defaults('page', 'terms-of-service')->name('terms-of-service');

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
    // The Profile card's "synced with Google Chat" indicator: one Google lookup, asked after the page loads.
    Route::get('/profile/google-chat/status', [ProfileController::class, 'googleChatStatus'])
        ->middleware('throttle:20,1')->name('profile.google-chat.status');

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
    // Import dishes (with their dish types) from an Excel file: the template to fill in, and the upload.
    Route::get('/dishes/import/template', [DishController::class, 'importTemplate'])->name('dishes.import.template');
    Route::post('/dishes/import', [DishController::class, 'import'])->name('dishes.import');
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
    // Tell every waiting customer of the session that their order is ready for pick up.
    Route::post('/order-sessions/{id}/announce-ready', [OrderSessionController::class, 'announceReady'])
        ->whereNumber('id')->name('order-sessions.announce-ready');
    Route::delete('/order-sessions/{id}', [OrderSessionController::class, 'destroy'])
        ->whereNumber('id')->name('order-sessions.destroy');

    // Pre-order sessions (staff): index serves the page and (on an AJAX request) the feed.
    Route::get('/pre-order-sessions', [PreOrderSessionController::class, 'index'])->name('pre-order-sessions.index');
    Route::post('/pre-order-sessions', [PreOrderSessionController::class, 'store'])->name('pre-order-sessions.store');
    Route::get('/pre-order-sessions/{id}', [PreOrderSessionController::class, 'show'])
        ->whereNumber('id')->name('pre-order-sessions.show');
    Route::get('/pre-order-sessions/{id}/edit', [PreOrderSessionController::class, 'edit'])
        ->whereNumber('id')->name('pre-order-sessions.edit');
    Route::put('/pre-order-sessions/{id}', [PreOrderSessionController::class, 'update'])
        ->whereNumber('id')->name('pre-order-sessions.update');
    Route::post('/pre-order-sessions/{id}/end', [PreOrderSessionController::class, 'end'])
        ->whereNumber('id')->name('pre-order-sessions.end');
    Route::delete('/pre-order-sessions/{id}', [PreOrderSessionController::class, 'destroy'])
        ->whereNumber('id')->name('pre-order-sessions.destroy');

    // Pre-orders (customer): the pre-order menu of the running pre-order session, the orders placed
    // from it (throttled per user like the regular ones) and the customer's own list.
    Route::get('/pre-order-menu', [PreOrderController::class, 'index'])->name('pre-order-menu.index');
    Route::post('/pre-orders', [PreOrderController::class, 'store'])->middleware('throttle:10,1')->name('pre-orders.store');
    Route::get('/my-pre-orders', [PreOrderController::class, 'mine'])->name('my-pre-orders.index');

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
    // Staff announce one order is ready for pick up (stays pending until handed over).
    Route::post('/orders/{id}/ready', [OrderController::class, 'ready'])
        ->whereNumber('id')->name('orders.ready');
    // ...or call it off: pending -> cancelled, and its dishes go back on the menu.
    Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])
        ->whereNumber('id')->name('orders.cancel');
    // Staff decide the discount an order was placed with (review discounts), and look at the ID
    // the customer sent (private file: its customer and order staff only).
    Route::post('/orders/{id}/discount/approve', [OrderDiscountController::class, 'approve'])
        ->whereNumber('id')->name('orders.discount.approve');
    Route::post('/orders/{id}/discount/reject', [OrderDiscountController::class, 'reject'])
        ->whereNumber('id')->name('orders.discount.reject');
    Route::get('/orders/{id}/discount-proof', [OrderDiscountController::class, 'proof'])
        ->whereNumber('id')->name('orders.discount.proof');
    // The customer's own orders: what they placed, and how they paid.
    Route::get('/my-orders', [OrderController::class, 'mine'])->name('my-orders.index');
    // A rejected payment can be fixed by its customer: a new proof, or cash on pickup.
    Route::post('/my-orders/{id}/payment/proof', [OrderController::class, 'resubmitProof'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.payment.proof');
    Route::post('/my-orders/{id}/payment/cash', [OrderController::class, 'switchToCash'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.payment.cash');
    // A rejected discount can be tried again with a new ID (only while discounts are offered on orders).
    Route::post('/my-orders/{id}/discount/proof', [OrderController::class, 'resubmitDiscountProof'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.discount.proof');
    // The customer cancels their own pending order (only while System Configuration allows it).
    Route::post('/my-orders/{id}/cancel', [OrderController::class, 'cancelMine'])
        ->whereNumber('id')->middleware('throttle:10,1')->name('my-orders.cancel');

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

    // Audit trail: read only (no route changes or deletes an entry). Permission: view audit-logs.
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])->name('audit-logs.export');
    Route::get('/audit-logs/{id}', [AuditLogController::class, 'show'])
        ->whereNumber('id')->name('audit-logs.show');

    // Companies: what the "Company" dropdown of register / profile / Users offers.
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{id}/edit', [CompanyController::class, 'edit'])
        ->whereNumber('id')->name('companies.edit');
    Route::put('/companies/{id}', [CompanyController::class, 'update'])
        ->whereNumber('id')->name('companies.update');
    Route::delete('/companies/{id}', [CompanyController::class, 'destroy'])
        ->whereNumber('id')->name('companies.destroy');

    // Discounts: what a customer can pick at checkout (the master switch is in System Configuration).
    Route::get('/discounts', [DiscountController::class, 'index'])->name('discounts.index');
    Route::post('/discounts', [DiscountController::class, 'store'])->name('discounts.store');
    Route::get('/discounts/{id}/edit', [DiscountController::class, 'edit'])
        ->whereNumber('id')->name('discounts.edit');
    Route::put('/discounts/{id}', [DiscountController::class, 'update'])
        ->whereNumber('id')->name('discounts.update');
    Route::delete('/discounts/{id}', [DiscountController::class, 'destroy'])
        ->whereNumber('id')->name('discounts.destroy');

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
