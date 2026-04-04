<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PhonePe\PaymentController;

Route::get('/', function () {
    return view('welcome');
});
Route::get('/pay/{merchantOrderId}', [PaymentController::class, 'processSharedLink'])->name('process.shared.link');