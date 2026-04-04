<?php

use App\Http\Controllers\PhonePe\PaymentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PhonePe Payment Gateway — API Routes
|--------------------------------------------------------------------------
|
| All routes are public (no auth middleware) since this is a standalone
| payment service. In a production app, protect /generate-payment-link
| and /transactions behind auth:sanctum or similar.
|
*/

// ── Payment Link Generation ───────────────────────────────────────────────────
// Creates a pending payment record and returns a local shareable link.
Route::post('/generate-payment-link', [PaymentController::class, 'generatePaymentLink'])
    ->name('payment.generate');

// ── Shared Link (redirect to PhonePe) ─────────────────────────────────────────
// When the user visits this URL, they are redirected to PhonePe's checkout page.
Route::get('/pay/{merchantOrderId}', [PaymentController::class, 'processSharedLink'])
    ->name('payment.process')
    ->withoutMiddleware(['auth']);

// ── Redirect Callback (browser redirect from PhonePe after payment) ────────────
// PhonePe redirects the user's browser here after they complete/cancel payment.
// Performs server-to-server status verification — does NOT trust redirect params.
Route::get('/payment/callback/{merchantOrderId}', [PaymentController::class, 'callback'])
    ->name('payment.callback')
    ->withoutMiddleware(['auth']);

// ── Manual Status Check ────────────────────────────────────────────────────────
// Allows the frontend to poll payment status. Idempotent — returns DB result
// if payment is already in a terminal state without re-querying PhonePe.
Route::get('/payment/status/{merchantOrderId}', [PaymentController::class, 'checkPaymentStatus'])
    ->name('payment.status')
    ->withoutMiddleware(['auth']);

// ── PhonePe Server-to-Server Webhook ──────────────────────────────────────────
// PhonePe POSTs payment events here. Validates HMAC signature before processing.
// Must be excluded from CSRF protection (done via bootstrap/app.php or VerifyCsrf).
Route::post('/webhook/phonepe', [PaymentController::class, 'webhook'])
    ->name('payment.webhook')
    ->withoutMiddleware(['auth']);

// ── Transaction History ────────────────────────────────────────────────────────
// Returns paginated list of all payment records.
Route::get('/transactions', [PaymentController::class, 'getAllTransactions'])
    ->name('payment.transactions');
