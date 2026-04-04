<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PhonePe\PaymentController;

Route::post('/generate-payment-link', [PaymentController::class, 'generatePaymentLink']);
