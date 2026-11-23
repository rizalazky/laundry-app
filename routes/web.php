<?php

use App\Http\Controllers\Auth\RegisterOwnerController;
use App\Http\Controllers\OrderReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['guest', 'throttle:5,1'])->group(function (): void {
    Route::get('/register', [RegisterOwnerController::class, 'create'])
        ->name('register');

    Route::post('/register', [RegisterOwnerController::class, 'store'])
        ->name('register.store');
});

Route::get('/receipts/{order}', OrderReceiptController::class)
    ->middleware('auth')
    ->name('orders.receipt');
