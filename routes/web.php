<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\RegisterOwnerController;
use App\Http\Controllers\OrderReceiptController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/midtrans', MidtransWebhookController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('webhooks.midtrans');

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware(['guest', 'throttle:5,1'])->group(function (): void {
    Route::get('/register', [RegisterOwnerController::class, 'create'])
        ->name('register');

    Route::post('/register', [RegisterOwnerController::class, 'store'])
        ->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
        ->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});

Route::get('/receipts/{order}', OrderReceiptController::class)
    ->middleware('auth')
    ->name('orders.receipt');
