<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\PhonePe\PaymentController;
use App\Http\Controllers\User\UserManagementController;
use Illuminate\Support\Facades\Route;



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

Route::post('/register', [AuthController::class, 'registerNewUser'])
    ->name('register');

Route::middleware('auth:api')->group(function () {
    Route::post('/generate-payment-link', [PaymentController::class, 'generatePaymentLink'])
        ->name('payment.generate');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::get('/transactions', [PaymentController::class, 'getAllTransactions'])
        ->name('payment.transactions');

    Route::prefix('user')->group(function () {
        Route::get('/roles', [UserManagementController::class, 'index'])
            ->name('user.roles');

        Route::get('/profile', [UserManagementController::class, 'profileManagement'])
            ->name('profile');

        Route::post('/profile', [UserManagementController::class, 'updateProfileManagement'])
            ->name('profile.update');

        Route::post('/profile/change-password', [UserManagementController::class, 'changePasswordManagement'])
            ->name('profile.change-password');

        Route::post('/profile/upload-profile-image', [UserManagementController::class, 'uploadUserProfile'])
            ->name('profile.upload-profile-image');
    });
});

Route::get('/transactions/{orderId}', [PaymentController::class, 'getTransactionById'])
    ->name('payment.transaction.show');
