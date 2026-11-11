<?php

use App\Http\Controllers\DishController;
use App\Http\Controllers\DishTypeController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderSessionController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
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

    // The menu: the dishes of the running session, and the orders placed from it.
    Route::get('/menu', [OrderController::class, 'index'])->name('menu.index');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    // The customer's own orders: what they placed, and how they paid.
    Route::get('/my-orders', [OrderController::class, 'mine'])->name('my-orders.index');

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
