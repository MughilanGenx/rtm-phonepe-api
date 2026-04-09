<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\PhonePe\PaymentController;
use Illuminate\Support\Facades\Route;

Route::post('/generate-payment-link', [PaymentController::class, 'generatePaymentLink'])
    ->name('payment.generate');

Route::get('/pay/{merchantOrderId}', [PaymentController::class, 'processSharedLink'])
    ->name('payment.process')
    ->withoutMiddleware(['auth']);

Route::get('/payment/callback/{merchantOrderId}', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware(['auth']);

Route::get('/payment/status/{merchantOrderId}', [PaymentController::class, 'checkPaymentStatus'])
    ->name('payment.status')
    ->withoutMiddleware(['auth']);

Route::post('/webhook/phonepe', [PaymentController::class, 'webhook'])
    ->name('payment.webhook')
    ->withoutMiddleware(['auth']);

Route::post('/login', [AuthController::class, 'login'])
    ->name('login');

Route::middleware('auth:api')->group(function () {
    Route::get('/transactions', [PaymentController::class, 'getAllTransactions'])
        ->name('payment.transactions');
});