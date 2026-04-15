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

    #[OA\Post(
        path: '/api/generate-payment-link',
        summary: 'Generate Payment Link',
        description: 'Create a pending payment record and return a local shareable link.',
        tags: ['Payment'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount', 'name', 'email', 'phone'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', minimum: 1, example: 100),
                    new OA\Property(property: 'merchant_order_id', type: 'string', nullable: true, example: 'ORDER_12345'),
                    new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                    new OA\Property(property: 'description', type: 'string', nullable: true, example: 'Payment for order #12345'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Payment link generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Payment link generated successfully'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'payment_id', type: 'integer', example: 1),
                            new OA\Property(property: 'merchant_order_id', type: 'string', example: 'ORDER_12345'),
                            new OA\Property(property: 'payment_link', type: 'string', example: 'http://localhost/api/pay/ORDER_12345'),
                            new OA\Property(property: 'name', type: 'string', example: 'John Doe'),
                            new OA\Property(property: 'email', type: 'string', example: 'john@example.com'),
                            new OA\Property(property: 'phone', type: 'string', example: '9876543210'),
                            new OA\Property(property: 'amount', type: 'number', example: 100),
                            new OA\Property(property: 'status', type: 'string', example: 'INITIATED'),
                        ])
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Bad Request'),
            new OA\Response(response: 401, description: 'Unauthorized'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    public function generatePaymentLink(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        $validator = Validator::make($request->all(), [
            'amount'            => 'required|numeric|min:1',
            'merchant_order_id' => 'nullable|string|max:255|unique:payments,merchant_order_id',
            'name'              => 'required|string|max:255',
            'email'             => 'nullable|email|max:255',
            'phone'             => 'required|string|max:15',
            'description'       => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $merchantOrderId = $request->merchant_order_id
            ?? 'ORDER_' . strtoupper(substr(uniqid('', true), 0, 10));

        $payment = Payment::create([
            'user_id'           => $user->id,
            'merchant_order_id' => $merchantOrderId,
            'amount'            => $request->amount,
            'name'              => $request->name,
            'email'             => $request->email,
            'phone'             => $request->phone,
            'description'       => $request->description,
            'status'            => PaymentStatus::INITIATED,
        ]);

        Log::info('Payment record created', [
            'payment_id'        => $payment->id,
            'user_id'           => $payment->user_id,
            'merchant_order_id' => $merchantOrderId,
            'amount'            => $payment->amount,
        ]);

        return $this->success('Payment link generated successfully', [
            'payment_id'        => $payment->id,
            'merchant_order_id' => $merchantOrderId,
            'payment_link'      => url('/api/pay/' . $merchantOrderId),
            'name'              => $payment->name,
            'email'             => $payment->email,
            'phone'             => $payment->phone,
            'amount'            => $payment->amount,
            'status'            => $payment->status->value,
        ]);
    }

  
    #[OA\Get(
        path: '/api/pay/{merchantOrderId}',
        summary: 'Process Shared Link',
        description: 'Look up the payment and redirect the user to PhonePe checkout.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 302, 
                description: 'Redirect to PhonePe checkout or frontend status page (if already processed or errored). Redirection URL include query params like orderId, status, and error.'
            ),
            new OA\Response(response: 404, description: 'Payment not found'),
            new OA\Response(response: 400, description: 'Payment link already used'),
            new OA\Response(response: 502, description: 'Payment initiation failed')
        ]
    )]
    public function processSharedLink(string $merchantOrderId)
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $payment = Payment::where('merchant_order_id', $merchantOrderId)->first();

        if (! $payment) {
            Log::error('Payment not found for shared link', ['merchant_order_id' => $merchantOrderId]);
            return redirect()->away(
                $frontendUrl . '/payment/status?' . http_build_query([
                    'orderId' => $merchantOrderId,
                    'status'  => 'ERROR',
                    'error'   => 'Payment not found. Please check your link or contact support.',
                ])
            );
        }

        if ($payment->status !== PaymentStatus::INITIATED) {
            Log::info('Payment already processed, redirecting to status page', [
                'merchant_order_id' => $merchantOrderId,
                'status'            => $payment->status->value,
            ]);

            // Send them straight to the status page — they'll see their real status
            return redirect()->away(
                $frontendUrl . '/payment/status?' . http_build_query([
                    'orderId'       => $merchantOrderId,
                    'status'        => strtoupper($payment->status->value),
                    'amount'        => $payment->amount,
                    'transactionId' => $payment->transaction_id ?? '',
                    'paidAt'        => $payment->paid_at?->toISOString() ?? '',
                    'error'         => 'This payment link has already been used (status: ' . $payment->status->label() . ').',
                ])
            );
        }

        if ($payment->phonepe_link) {
            Log::info('Reusing existing PhonePe link', ['merchant_order_id' => $merchantOrderId]);
            return redirect()->away($payment->phonepe_link);
        }

        try {
            $response = $this->phonePe->initiatePayment([
                'merchant_order_id' => $merchantOrderId,
                'amount'            => $payment->amount,
                'name'              => $payment->name,
                'email'             => $payment->email,
                'phone'             => $payment->phone,
            ]);

            if (empty($response['redirectUrl'])) {
                Log::error('PhonePe did not return a redirect URL', [
                    'merchant_order_id' => $merchantOrderId,
                    'response'          => $response,
                ]);
                return redirect()->away(
                    $frontendUrl . '/payment/status?' . http_build_query([
                        'orderId' => $merchantOrderId,
                        'status'  => 'ERROR',
                        'error'   => 'Payment initiation failed — no redirect URL received. Please try again.',
                    ])
                );
            }

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
            return redirect()->away(
                $frontendUrl . '/payment/status?' . http_build_query([
                    'orderId' => $merchantOrderId,
                    'status'  => 'ERROR',
                    'error'   => 'Payment initiation failed: ' . $e->getMessage(),
                ])
            );
        }
    }


    #[OA\Post(
        path: '/api/payment/callback/{merchantOrderId}',
        summary: 'PhonePe Redirect Callback',
        description: 'Handles PhonePe browser redirect after payment. Performs a server-to-server status verification, updates the DB, then redirects the frontend to /payment/status with query params: orderId, status (COMPLETED|PENDING|DECLINED|CANCELLED|ERROR), amount, transactionId, paidAt. On failure, an extra `error` param is included and status defaults to PENDING.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\MediaType(
                mediaType: 'application/x-www-form-urlencoded',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'transactionId', type: 'string'),
                        new OA\Property(property: 'merchantId', type: 'string'),
                        new OA\Property(property: 'providerReferenceId', type: 'string'),
                        new OA\Property(property: 'code', type: 'string'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 302,
                description: 'Redirect to {FRONTEND_URL}/payment/status with query params: orderId, status (COMPLETED|PENDING|DECLINED|CANCELLED|ERROR), amount, transactionId, paidAt.'
            ),
            new OA\Response(response: 500, description: 'Verification logic error')
        ]
    )]
    public function callback(string $merchantOrderId)
    {
        Log::info('PhonePe redirect callback received', [
            'merchant_order_id' => $merchantOrderId,
        ]);

        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        // Default query params (shown if verification fails)
        $queryParams = [
            'orderId' => $merchantOrderId,
            'status'  => 'PENDING',
        ];

        try {
            // Server-to-server status verification with PhonePe
            $this->verifyAndUpdatePayment($merchantOrderId);

            // Re-fetch the freshly updated payment record
            $payment = \App\Models\Payment::where('merchant_order_id', $merchantOrderId)->first();

            if ($payment) {
                $queryParams = [
                    'orderId'       => $merchantOrderId,
                    'status'        => strtoupper($payment->status->value),
                    'amount'        => $payment->amount,
                    'transactionId' => $payment->transaction_id ?? '',
                    'paidAt'        => $payment->paid_at?->toISOString() ?? '',
                ];

                Log::info('PhonePe callback verified, redirecting frontend', $queryParams);
            }
        } catch (\Exception $e) {
            Log::error('PhonePe redirect verification exception', [
                'merchant_order_id' => $merchantOrderId,
                'error'             => $e->getMessage(),
            ]);

            // Still redirect — frontend will show PENDING and can retry /api/payment/status
            $queryParams['error'] = 'Verification failed. Please check your payment status.';
        }

        // Redirect to frontend with all payment status query params
        return redirect()->away($frontendUrl . '/payment/status?' . http_build_query($queryParams));
    }

    #[OA\Post(
        path: '/api/webhook/phonepe',
        summary: 'PhonePe Webhook',
        description: 'Handle PhonePe server-to-server webhook notification.',
        tags: ['Payment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'response', type: 'string', description: 'Base64 encoded payload containing transaction details like merchantOrderId, amount, and state.')
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


    #[OA\Get(
        path: '/api/payment/status/{merchantOrderId}',
        summary: 'Check Payment Status',
        description: 'Manually check and sync payment status from PhonePe.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'merchantOrderId', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200, 
                description: 'Payment status returned successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Payment verified successfully'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'transaction_id', type: 'string', example: 'T2604091523459403481676'),
                            new OA\Property(property: 'amount', type: 'number', example: 11.00),
                            new OA\Property(property: 'payment_status', type: 'string', example: 'COMPLETED'),
                            new OA\Property(property: 'payment_id', type: 'integer', example: 17),
                            new OA\Property(property: 'merchant_order_id', type: 'string', example: 'ORDER_69D7771BBF'),
                            new OA\Property(property: 'paid_at', type: 'string', format: 'date-time'),
                            new OA\Property(property: 'transaction', type: 'object', properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'status', type: 'string'),
                                new OA\Property(property: 'amount', type: 'string'),
                                new OA\Property(property: 'payment_response', type: 'object', nullable: true),
                            ])
                        ])
                    ]
                )
            ),
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

    #[OA\Get(
        path: '/api/transactions',
        summary: 'List All Transactions',
        description: 'Return a paginated list of all payment records. Supports search, status filtering, date range filtering, and pagination.',
        tags: ['Payment'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by order ID, name, email, phone, or transaction ID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by payment status (e.g., INIT, COMPLETED, PENDING, DECLINED, CANCELLED)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, description: 'Start date (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, description: 'End date (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Items per page (default: 10)', schema: new OA\Schema(type: 'integer', default: 10))
        ],
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
                            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'merchant_order_id', type: 'string'),
                                    new OA\Property(property: 'amount', type: 'string'),
                                    new OA\Property(property: 'status', type: 'string'),
                                    new OA\Property(property: 'user', type: 'object', properties: [
                                        new OA\Property(property: 'name', type: 'string'),
                                        new OA\Property(property: 'email', type: 'string'),
                                    ])
                                ]
                            )),
                            new OA\Property(property: 'first_page_url', type: 'string'),
                            new OA\Property(property: 'last_page', type: 'integer'),
                            new OA\Property(property: 'last_page_url', type: 'string'),
                            new OA\Property(property: 'next_page_url', type: 'string', nullable: true),
                            new OA\Property(property: 'path', type: 'string'),
                            new OA\Property(property: 'per_page', type: 'integer'),
                            new OA\Property(property: 'prev_page_url', type: 'string', nullable: true),
                            new OA\Property(property: 'total', type: 'integer', example: 50)
                        ])
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 500, description: 'Internal Server Error')
        ]
    )]
    public function getAllTransactions(Request $request): JsonResponse
    {
        $query = Payment::query()->with('user:id,name,email');

        // 1. Search by generic terms (name, email, phone, order ID, or transaction ID)
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('merchant_order_id', 'like', "%{$searchTerm}%")
                  ->orWhere('name', 'like', "%{$searchTerm}%")
                  ->orWhere('email', 'like', "%{$searchTerm}%")
                  ->orWhere('phone', 'like', "%{$searchTerm}%")
                  ->orWhere('transaction_id', 'like', "%{$searchTerm}%");
            });
        }

        // 2. Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 3. Filter by Date Range
        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Configure pagination (default to 10 if not provided)
        $perPage = $request->input('per_page', 10);
        
        $payments = $query->latest()->paginate($perPage);

        return $this->success('Transactions fetched successfully', $payments);
    }


    #[OA\Get(
        path: '/api/transactions/{orderId}',
        summary: 'Get Transaction by Order ID',
        description: 'Fetch complete details of a specific payment transaction using its merchant order ID.',
        tags: ['Payment'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'orderId', in: 'path', required: true, description: 'Merchant Order ID', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transaction fetched successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Transaction fetched successfully'),
                        new OA\Property(
                            property: 'data',
                            type: 'array',
                            items: new OA\Items(
                                type: 'object',
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer', example: 17),
                                    new OA\Property(property: 'merchant_order_id', type: 'string', example: 'ORDER_69D7771BBF'),
                                    new OA\Property(property: 'name', type: 'string', example: 'lee'),
                                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'test@gmail.com'),
                                    new OA\Property(property: 'phone', type: 'string', example: '9999233434'),
                                    new OA\Property(property: 'amount', type: 'string', example: '11.00'),
                                    new OA\Property(property: 'status', type: 'string', example: 'completed'),
                                    new OA\Property(
                                        property: 'payment_response',
                                        type: 'object',
                                        description: 'Raw response from PhonePe',
                                        example: [
                                            'state' => 'COMPLETED',
                                            'amount' => 1100,
                                            'orderId' => 'OMO2604091523459403481676',
                                            'currency' => 'INR',
                                            'payableAmount' => 1100
                                        ]
                                    ),
                                    new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', example: '2026-04-09T09:54:54.000000Z'),
                                    new OA\Property(property: 'paid_at', type: 'string', format: 'date-time', example: '2026-04-09T09:54:54.000000Z'),
                                    new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-04-09T09:53:31.000000Z'),
                                    new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-04-09T09:54:54.000000Z'),
                                ]
                            )
                        )
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Transaction not found')
        ]
    )]
    public function getTransactionById(string $orderId): JsonResponse
    {
        $payment = Payment::where('merchant_order_id', $orderId)->with('user:id,name')->first();

        if (! $payment) {
            return $this->error('Transaction not found', 404, 'TRANSACTION_NOT_FOUND');
        }

        $responseData = $payment->only([
            'merchant_order_id',
            'name',
            'email',
            'phone',
            'amount',
            'status',
            'payment_response',
            'last_synced_at',
            'paid_at',
            'created_at',
            'updated_at',
        ]);

        // Add user name to response
        $responseData['user_name'] = $payment->user?->name;

        if (isset($responseData['payment_response']['paymentDetails'])) {
            unset($responseData['payment_response']['paymentDetails']);
        }
        if (isset($responseData['payment_response']['phonepeTPAPTxnDetailsLink'])) {
            unset($responseData['payment_response']['phonepeTPAPTxnDetailsLink']);
        }

        return $this->success('Transaction fetched successfully', [$responseData]);
    }


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

    private function buildPaymentStatusResponse(Payment $payment): JsonResponse
    {
        $responseData = $payment->only([
            'id',
            'merchant_order_id',
            'name',
            'email',
            'phone',
            'amount',
            'status',
            'payment_response',
            'last_synced_at',
            'paid_at',
            'created_at',
            'updated_at',
        ]);

        if (isset($responseData['payment_response']['paymentDetails'])) {
            unset($responseData['payment_response']['paymentDetails']);
        }
        if (isset($responseData['payment_response']['phonepeTPAPTxnDetailsLink'])) {
            unset($responseData['payment_response']['phonepeTPAPTxnDetailsLink']);
        }

        $data = [
            'transaction_id'    => $payment->transaction_id,
            'amount'            => $payment->amount,
            'payment_status'    => strtoupper($payment->status->value),
            'payment_id'        => $payment->id,
            'merchant_order_id' => $payment->merchant_order_id,
            'paid_at'           => $payment->paid_at?->toISOString(),
            'transaction'       => $responseData,
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
