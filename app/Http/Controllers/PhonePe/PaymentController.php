<?php

namespace App\Http\Controllers\PhonePe;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PhonepeServices;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Payment', description: 'PhonePe Payment API Endpoints')]
class PaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private PhonepeServices $phonePe) {}

    // =========================================================================
    // 1. Generate Payment Link
    // =========================================================================

    /**
     * Create a pending payment record and return a local shareable link.
     *
     * POST /api/generate-payment-link
     *
     * Body:
     *   amount          (required, numeric, min:1)
     *   merchant_order_id (optional — auto-generated if omitted)
     *   customer_name   (required)
     *   customer_email  (required, email)
     *   customer_phone  (required)
     */
    #[OA\Post(
        path: '/api/generate-payment-link',
        summary: 'Generate Payment Link',
        description: 'Create a pending payment record and return a local shareable link.',
        tags: ['Payment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'customer_name', 'customer_email', 'customer_phone'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', minimum: 1, example: 100),
                    new OA\Property(property: 'merchant_order_id', type: 'string', nullable: true, example: 'ORDER_12345'),
                    new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'customer_email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'customer_phone', type: 'string', example: '9876543210'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Payment link generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Payment link generated successfully'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'payment_id', type: 'integer', example: 1),
                            new OA\Property(property: 'merchant_order_id', type: 'string', example: 'ORDER_12345'),
                            new OA\Property(property: 'payment_link', type: 'string', example: 'http://localhost/api/pay/ORDER_12345'),
                            new OA\Property(property: 'customer_name', type: 'string', example: 'John Doe'),
                            new OA\Property(property: 'customer_email', type: 'string', example: 'john@example.com'),
                            new OA\Property(property: 'customer_phone', type: 'string', example: '9876543210'),
                            new OA\Property(property: 'amount', type: 'number', example: 100),
                            new OA\Property(property: 'status', type: 'string', example: 'INITIATED'),
                        ])
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function generatePaymentLink(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'amount'            => 'required|numeric|min:1',
            'merchant_order_id' => 'nullable|string|max:255|unique:payments,merchant_order_id',
            'customer_name'     => 'required|string|max:255',
            'customer_email'    => 'required|email|max:255',
            'customer_phone'    => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $merchantOrderId = $request->merchant_order_id
            ?? 'ORDER_' . strtoupper(substr(uniqid('', true), 0, 10));

        $payment = Payment::create([
            'merchant_order_id' => $merchantOrderId,
            'amount'            => $request->amount,
            'customer_name'     => $request->customer_name,
            'customer_email'    => $request->customer_email,
            'customer_phone'    => $request->customer_phone,
            'status'            => PaymentStatus::INITIATED,
        ]);

        Log::info('Payment record created', [
            'payment_id'        => $payment->id,
            'merchant_order_id' => $merchantOrderId,
            'amount'            => $payment->amount,
        ]);

        return $this->success('Payment link generated successfully', [
            'payment_id'        => $payment->id,
            'merchant_order_id' => $merchantOrderId,
            'payment_link'      => url('/api/pay/' . $merchantOrderId),
            'customer_name'     => $payment->customer_name,
            'customer_email'    => $payment->customer_email,
            'customer_phone'    => $payment->customer_phone,
            'amount'            => $payment->amount,
            'status'            => $payment->status->value,
        ]);
    }

    // =========================================================================
    // 2. Process Shared Link (redirect user to PhonePe)
    // =========================================================================

    /**
     * Look up the payment and redirect the user to PhonePe's checkout.
     *
     * GET /api/pay/{merchantOrderId}
     */
    #[OA\Get(
        path: '/api/pay/{merchantOrderId}',
        summary: 'Process Shared Link',
        description: 'Look up the payment and redirect the user to PhonePe checkout.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 302, description: 'Redirect to PhonePe checkout'),
            new OA\Response(response: 404, description: 'Payment not found'),
            new OA\Response(response: 400, description: 'Payment link already used'),
            new OA\Response(response: 502, description: 'Payment initiation failed')
        ]
    )]
    public function processSharedLink(string $merchantOrderId)
    {
        $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

        if (! $payment) {
            Log::error('Payment not found for shared link', ['merchant_order_id' => $merchantOrderId]);
            return $this->error('Payment not found', 404);
        }

        // Only INITIATED payments can be started; others are terminal or in progress
        if ($payment->status !== PaymentStatus::INITIATED) {
            Log::info('Payment already processed, not re-initiating', [
                'merchant_order_id' => $merchantOrderId,
                'status'            => $payment->status->value,
            ]);
            return $this->error(
                'This payment link has already been used (status: ' . $payment->status->label() . ')',
                400
            );
        }

        // Re-use existing PhonePe link if already generated (idempotent)
        if ($payment->phonepe_link) {
            Log::info('Reusing existing PhonePe link', ['merchant_order_id' => $merchantOrderId]);
            return redirect()->away($payment->phonepe_link);
        }

        try {
            $response = $this->phonePe->initiatePayment([
                'merchant_order_id' => $merchantOrderId,
                'amount'            => $payment->amount,
                'name'              => $payment->customer_name,
                'email'             => $payment->customer_email,
                'phone'             => $payment->customer_phone,
            ]);

            if (empty($response['redirectUrl'])) {
                Log::error('PhonePe did not return a redirect URL', [
                    'merchant_order_id' => $merchantOrderId,
                    'response'          => $response,
                ]);
                return $this->error('Payment initiation failed — no redirect URL received.', 502);
            }

            // Persist the PhonePe link so we can reuse it without hitting the API again
            $payment->update(['phonepe_link' => $response['redirectUrl']]);

            Log::info('Redirecting to PhonePe checkout', [
                'merchant_order_id' => $merchantOrderId,
                'redirect_url'      => $response['redirectUrl'],
            ]);

            return redirect()->away($response['redirectUrl']);

        } catch (\Exception $e) {
            Log::error('Payment initiation exception', [
                'merchant_order_id' => $merchantOrderId,
                'error'             => $e->getMessage(),
            ]);
            return $this->error('Payment initiation failed. Please try again.', 500);
        }
    }

    // =========================================================================
    // 3. Redirect Callback (PhonePe redirect after payment)
    // =========================================================================

    /**
     * Handle PhonePe's browser redirect after the user completes (or abandons) payment.
     * PhonePe sends the merchantOrderId via URL param — do NOT trust this status alone;
     * always verify via server-to-server status check.
     *
     * GET /api/payment/callback/{merchantOrderId}
     */
    #[OA\Get(
        path: '/api/payment/callback/{merchantOrderId}',
        summary: 'PhonePe Redirect Callback',
        description: 'Handle PhonePe browser redirect after the user completes or abandons payment.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment verified successfully'),
            new OA\Response(response: 202, description: 'Payment is still being processed'),
            new OA\Response(response: 400, description: 'Payment declined, cancelled, or error'),
            new OA\Response(response: 404, description: 'Payment not found')
        ]
    )]
    public function callback(string $merchantOrderId): JsonResponse
    {
        Log::info('PhonePe redirect callback received', [
            'merchant_order_id' => $merchantOrderId,
        ]);

        return $this->verifyAndUpdatePayment($merchantOrderId);
    }

    // =========================================================================
    // 4. Webhook (server-to-server from PhonePe)
    // =========================================================================

    /**
     * Handle PhonePe server-to-server webhook notification.
     * Validates HMAC signature before processing.
     *
     * POST /api/webhook/phonepe
     */
    #[OA\Post(
        path: '/api/webhook/phonepe',
        summary: 'PhonePe Webhook',
        description: 'Handle PhonePe server-to-server webhook notification.',
        tags: ['Payment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'response', type: 'string', description: 'Base64 encoded payload')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Webhook processed successfully'),
            new OA\Response(response: 400, description: 'Invalid webhook payload - order ID missing'),
            new OA\Response(response: 401, description: 'Invalid webhook signature'),
            new OA\Response(response: 500, description: 'Webhook processing failed')
        ]
    )]
    public function webhook(Request $request): JsonResponse
    {
        Log::info('PhonePe webhook received', [
            'method'  => $request->method(),
            'headers' => $request->headers->all(),
        ]);

        // Step 1: Validate webhook signature
        if (! $this->phonePe->validateWebhookSignature($request)) {
            Log::warning('PhonePe webhook signature validation failed');
            return $this->error('Invalid webhook signature', 401, 'SIGNATURE_INVALID');
        }

        try {
            // Step 2: Decode base64-encoded payload if needed
            $payload = $this->phonePe->decodeResponsePayload($request->all());

            // Step 3: Extract merchant order ID
            $merchantOrderId = $this->phonePe->extractMerchantOrderId($payload)
                ?? $request->input('merchantOrderId')
                ?? $request->input('transactionId');

            if (! $merchantOrderId) {
                Log::error('PhonePe webhook: merchant order ID not found', ['payload' => $payload]);
                return $this->error('Invalid webhook payload — order ID missing', 400);
            }

            // Step 4: Verify and update
            return $this->verifyAndUpdatePayment($merchantOrderId);

        } catch (\Exception $e) {
            Log::error('PhonePe webhook processing exception', [
                'error'   => $e->getMessage(),
                'payload' => $request->all(),
            ]);

            return $this->error('Webhook processing failed: ' . $e->getMessage(), 500, 'WEBHOOK_ERROR');
        }
    }

    // =========================================================================
    // 5. Manual Status Check
    // =========================================================================

    /**
     * Manually check and sync payment status from PhonePe.
     *
     * GET /api/payment/status/{merchantOrderId}
     */
    #[OA\Get(
        path: '/api/payment/status/{merchantOrderId}',
        summary: 'Check Payment Status',
        description: 'Manually check and sync payment status from PhonePe.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Payment status returned successfully'),
            new OA\Response(response: 202, description: 'Payment is being processed'),
            new OA\Response(response: 400, description: 'Payment declined or errored'),
            new OA\Response(response: 404, description: 'Payment not found'),
            new OA\Response(response: 500, description: 'Status check error')
        ]
    )]
    public function checkPaymentStatus(string $merchantOrderId): JsonResponse
    {
        try {
            Log::info('Manual payment status check', ['merchant_order_id' => $merchantOrderId]);

            $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

            if (! $payment) {
                return $this->error('Payment not found', 404, 'PAYMENT_NOT_FOUND');
            }

            // Idempotency: if already in a terminal state, return cached DB result
            if ($payment->status->isTerminal()) {
                Log::info('Payment already in terminal state, returning DB result', [
                    'merchant_order_id' => $merchantOrderId,
                    'status'            => $payment->status->value,
                ]);

                return $this->buildPaymentStatusResponse($payment);
            }

            // Otherwise live-check from PhonePe
            return $this->verifyAndUpdatePayment($merchantOrderId);

        } catch (\Exception $e) {
            Log::error('Payment status check failed', [
                'merchant_order_id' => $merchantOrderId,
                'error'             => $e->getMessage(),
            ]);

            return $this->error(
                'Failed to check payment status: ' . $e->getMessage(),
                500,
                'STATUS_CHECK_ERROR'
            );
        }
    }

    // =========================================================================
    // 6. List All Transactions
    // =========================================================================

    /**
     * Return a paginated list of all payment records.
     *
     * GET /api/transactions
     */
    #[OA\Get(
        path: '/api/transactions',
        summary: 'List All Transactions',
        description: 'Return a paginated list of all payment records.',
        tags: ['Payment'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transactions fetched successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Transactions fetched successfully'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'current_page', type: 'integer', example: 1),
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                            new OA\Property(property: 'total', type: 'integer', example: 50)
                        ])
                    ]
                )
            )
        ]
    )]
    public function getAllTransactions(): JsonResponse
    {
        $payments = Payment::latest()->paginate(20);

        return $this->success('Transactions fetched successfully', $payments);
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Core payment verification logic shared by callback, webhook, and status check.
     * Performs server-to-server status verification with PhonePe and updates DB atomically.
     */
    private function verifyAndUpdatePayment(string $merchantOrderId): JsonResponse
    {
        // Fetch payment record
        $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

        if (! $payment) {
            Log::error('Payment record not found', ['merchant_order_id' => $merchantOrderId]);
            return $this->error('Order not found: ' . $merchantOrderId, 404, 'PAYMENT_NOT_FOUND');
        }

        // Idempotency guard: skip re-verification if already terminal
        if ($payment->status->isTerminal()) {
            Log::info('Skipping re-verification — payment already in terminal state', [
                'merchant_order_id' => $merchantOrderId,
                'status'            => $payment->status->value,
            ]);
            return $this->buildPaymentStatusResponse($payment);
        }

        // Server-to-server status check with PhonePe
        $statusResponse = $this->phonePe->checkStatus($merchantOrderId);

        // Extract and map attributes
        $attrs = $this->phonePe->attributesFromStatusResponse($statusResponse);
        unset($attrs['merchant_order_id']); // Never override the order ID

        // Atomic update
        $payment->update($attrs);
        $payment->refresh();

        Log::info('Payment status updated', [
            'payment_id'        => $payment->id,
            'merchant_order_id' => $merchantOrderId,
            'new_status'        => $payment->status->value,
            'transaction_id'    => $payment->transaction_id,
        ]);

        return $this->buildPaymentStatusResponse($payment);
    }

    /**
     * Build a standardised JSON response based on the current payment status.
     */
    private function buildPaymentStatusResponse(Payment $payment): JsonResponse
    {
        $data = [
            'transaction_id'    => $payment->transaction_id,
            'amount'            => $payment->amount,
            'payment_status'    => strtoupper($payment->status->value),
            'payment_id'        => $payment->id,
            'merchant_order_id' => $payment->merchant_order_id,
            'paid_at'           => $payment->paid_at?->toISOString(),
        ];

        return match ($payment->status) {
            PaymentStatus::COMPLETED => $this->success('Payment verified successfully', $data),
            PaymentStatus::PENDING   => $this->pending($data, 'Payment is still being processed. Please check back shortly.', 202),
            PaymentStatus::DECLINED  => $this->error('Payment was declined. Please try a different payment method.', 400, 'PAYMENT_DECLINED', $data),
            PaymentStatus::CANCELLED => $this->error('Payment was cancelled.', 400, 'PAYMENT_CANCELLED', $data),
            PaymentStatus::ERROR     => $this->error('An error occurred while processing payment. Please try again.', 400, 'PAYMENT_ERROR', $data),
            default                  => $this->pending($data, 'Payment is being initiated.', 202),
        };
    }
}
