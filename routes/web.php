<?php

use App\Http\Controllers\OrderReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/receipts/{order}', OrderReceiptController::class)
    ->middleware('auth')
    ->name('orders.receipt');
