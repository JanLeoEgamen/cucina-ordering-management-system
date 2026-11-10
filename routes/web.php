<?php

use App\Http\Controllers\DishController;
use App\Http\Controllers\DishTypeController;
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
