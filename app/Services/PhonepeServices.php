<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonepeServices
{
    private ?string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private string $clientVersion;
    private ?string $webhookSecret;
    private ?string $merchantId;

    public function __construct()
    {
        $this->clientId = trim(config('services.phonepe.client_id') ?? '');
        $this->clientSecret = config('services.phonepe.client_secret');
        $this->clientVersion = config('services.phonepe.client_version', '1');
        $this->baseUrl = config('services.phonepe.base_url');
        $this->webhookSecret = config('services.phonepe.webhook_secret');
        $this->merchantId = config('services.phonepe.merchant_id');

        // Adjust base URL for V1 production if clientId is missing (Standard PG integration)
        if (empty($this->clientId) && config('services.phonepe.env') === 'production') {
            $this->baseUrl = 'https://api.phonepe.com/apis/hermes';
        }
    }

    /**
     * Check if using V2 (Enterprise) or V1 (Standard) API.
     */
    private function isV2(): bool
    {
        return !empty($this->clientId);
    }

    /**
     * Retrieve a cached OAuth access token from PhonePe (V2 Enterprise only).
     *
     * @throws \Exception if authentication fails
     */
    public function getAccessToken(): string
    {
        if (!$this->isV2()) {
            throw new \Exception('OAuth token is not required for PhonePe V1 (Standard) API.');
        }

        return Cache::remember('phonepe_access_token', 600, function () {
            $authUrl = config('services.phonepe.env') === 'production'
                ? 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token'
                : 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';

            $response = Http::asForm()
                // ->withoutVerifying()
                ->post($authUrl, [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'client_version' => $this->clientVersion,
                    'grant_type' => 'client_credentials',
                ]);

            Log::info('PhonePe auth response', ['http_status' => $response->status()]);

            if (!$response->successful()) {
                throw new \Exception('PhonePe authentication failed: ' . $response->body());
            }

            return $response->json()['access_token'];
        });
    }

    /**
     * Initiate a PhonePe checkout payment.
     * Supports both V1 (Standard) and V2 (Enterprise) based on configuration.
     *
     * @param  array{merchant_order_id: string, amount: float, name: string, email: string, phone: string}  $data
     * @return array The PhonePe API response (normalized to root level)
     * @throws \Exception if the API call fails
     */
    public function initiatePayment(array $data): array
    {
        if (!$this->isV2()) {
            return $this->initiatePaymentV1($data);
        }

        $token = $this->getAccessToken();

        $payload = [
            'merchantOrderId' => $data['merchant_order_id'],
            'amount' => (int) ($data['amount'] * 100), // Paise
            'expireAfter' => 1200, // 20 minutes
            'metaInfo' => [
                'udf1' => $data['name'],
                // 'udf2' => $data['email'],
                'udf2' => $data['phone'],
            ],
            'paymentFlow' => [
                'type' => 'PG_CHECKOUT',
                'message' => 'Payment for order ' . $data['merchant_order_id'],
                'merchantUrls' => [
                    'redirectUrl' => config('services.phonepe.redirect_url') . '?orderId=' . $data['merchant_order_id'],
                ],
            ],
        ];

        Log::info('PhonePe V2 initiate payment', [
            'merchant_order_id' => $data['merchant_order_id'],
            'amount_paise' => $payload['amount'],
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])
            ->withoutVerifying()
            ->post($this->baseUrl . '/checkout/v2/pay', $payload);

        Log::info('PhonePe initiate response', [
            'merchant_order_id' => $data['merchant_order_id'],
            'http_status' => $response->status(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('PhonePe payment initiation failed: ' . $response->body());
        }

        $json = $response->json();

        // Normalize: PhonePe v2 may nest redirectUrl inside data
        if (!isset($json['redirectUrl']) && isset($json['data']['redirectUrl'])) {
            $json['redirectUrl'] = $json['data']['redirectUrl'];
        }

        return $json;
    }

    /**
     * Initiate V1 (Standard PG) Payment.
     */
    private function initiatePaymentV1(array $data): array
    {
        $merchantTransactionId = $data['merchant_order_id'];

        $payload = [
            'merchantId' => $this->merchantId,
            'merchantTransactionId' => $merchantTransactionId,
            'merchantUserId' => 'USER_' . uniqid(),
            'amount' => (int) ($data['amount'] * 100),
            'redirectUrl' => config('services.phonepe.redirect_url') . '?orderId=' . $merchantTransactionId,
            'redirectMode' => 'REDIRECT',
            'callbackUrl' => url('/api/webhook/phonepe'),
            'mobileNumber' => $data['phone'] ?? null,
            'paymentInstrument' => [
                'type' => 'PAY_PAGE',
            ],
        ];

        $base64Payload = base64_encode(json_encode($payload));
        $xVerify = $this->calculateV1Header($base64Payload, '/pg/v1/pay');

        Log::info('PhonePe V1 initiate payment', [
            'merchant_order_id' => $merchantTransactionId,
            'amount_paise' => $payload['amount'],
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY' => $xVerify,
        ])
            ->withoutVerifying()
            ->post($this->baseUrl . '/pg/v1/pay', [
                'request' => $base64Payload,
            ]);

        if (!$response->successful()) {
            throw new \Exception('PhonePe V1 payment initiation failed: ' . $response->body());
        }

        $json = $response->json();

        // Normalize for the controller (which expects 'redirectUrl')
        return [
            'success' => $json['success'] ?? false,
            'redirectUrl' => $json['data']['instrumentResponse']['redirectInfo']['url'] ?? null,
            'data' => $json['data'] ?? [],
        ];
    }

    /**
     * Query PhonePe for the current order status.
     *
     * @throws \Exception if the API call fails or returns empty body
     */
    public function checkStatus(string $merchantOrderId, bool $withDetails = true): array
    {
        if (!$this->isV2()) {
            return $this->checkStatusV1($merchantOrderId);
        }

        $token = $this->getAccessToken();
        $url = $this->baseUrl . '/checkout/v2/order/' . $merchantOrderId . '/status';
        $query = $withDetails ? ['details' => 'true'] : [];

        Log::info('PhonePe V2 status check', ['merchant_order_id' => $merchantOrderId]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])
            ->withoutVerifying()
            ->get($url, $query);

        Log::info('PhonePe status response', [
            'merchant_order_id' => $merchantOrderId,
            'http_status' => $response->status(),
        ]);

        if (!$response->successful()) {
            throw new \Exception('PhonePe status check failed: ' . $response->body());
        }

        $body = (string) $response->body();
        if ($response->status() === 204 || trim($body) === '') {
            throw new \Exception(
                'PhonePe returned an empty response for order ' . $merchantOrderId . '. ' .
                'Ensure PHONEPE_ENV matches the environment where the payment was created.'
            );
        }

        $json = $response->json();
        if (!is_array($json)) {
            throw new \Exception('PhonePe status check: invalid JSON body');
        }

        return $this->normalizeOrderStatusResponse($json);
    }

    /**
     * Check V1 (Standard PG) Payment Status.
     */
    private function checkStatusV1(string $merchantTransactionId): array
    {
        $endpoint = "/pg/v1/status/{$this->merchantId}/{$merchantTransactionId}";
        $xVerify = $this->calculateV1Header('', $endpoint); // Payload is empty for GET status

        Log::info('PhonePe V1 status check', ['merchant_order_id' => $merchantTransactionId]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY' => $xVerify,
            'X-MERCHANT-ID' => $this->merchantId,
        ])
            ->withoutVerifying()
            ->get($this->baseUrl . $endpoint);

        if (!$response->successful()) {
            throw new \Exception('PhonePe V1 status check failed: ' . $response->body());
        }

        $json = $response->json();
        return $this->normalizeOrderStatusResponse($json);
    }

    /**
     * Normalize PhonePe order status response — unwrap `data` wrapper if present.
     */
    public function normalizeOrderStatusResponse(array $json): array
    {
        // For V1, PAYMENT_ERROR is a terminal state that should be processed, not thrown as an exception
        $code = $json['code'] ?? null;
        $isPaymentFailure = in_array($code, ['PAYMENT_ERROR', 'PAYMENT_DECLINED', 'TIMED_OUT']);

        if (array_key_exists('success', $json) && $json['success'] === false && !$isPaymentFailure) {
            $msg = $json['message'] ?? $json['code'] ?? 'Unknown PhonePe error';
            throw new \Exception('PhonePe order status error: ' . $msg);
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /**
     * Decode a PhonePe base64-encoded `response` field if present.
     */
    public function decodeResponsePayload(array $payload): array
    {
        if (!isset($payload['response']) || !is_string($payload['response'])) {
            return $payload;
        }

        $decodedJson = base64_decode($payload['response'], true);
        if ($decodedJson === false) {
            return $payload;
        }

        $decodedArray = json_decode($decodedJson, true);

        return is_array($decodedArray) ? $decodedArray : $payload;
    }

    /**
     * Extract merchantOrderId from PhonePe webhook/callback payload.
     * PhonePe may return it in different locations depending on the event type.
     */
    public function extractMerchantOrderId(array $payload): ?string
    {
        return $payload['merchantOrderId']
            ?? $payload['data']['merchantOrderId']
            ?? $payload['merchantTransactionId'] // V1
            ?? $payload['data']['merchantTransactionId'] // V1
            ?? $payload['transactionId']
            ?? $payload['data']['transactionId']
            ?? null;
    }

    /**
     * Extract and map PhonePe status response fields to Payment model attributes.
     */
    public function attributesFromStatusResponse(array $statusResponse): array
    {
        // Unwrap data wrapper if present
        $data = isset($statusResponse['data']) && is_array($statusResponse['data'])
            ? $statusResponse['data']
            : $statusResponse;

        // PhonePe uses 'state' for order status, fallback to 'status' or 'code'
        $phonepeStatus = $data['state'] ?? $data['status'] ?? $data['code'] ?? null;
        $appStatus = $this->mapPhonepeStatusToAppStatus($phonepeStatus);

        Log::info('PhonePe status mapped', [
            'phonepe_status' => $phonepeStatus,
            'app_status' => $appStatus,
        ]);

        // Extract paymentMode and transactionId from first paymentDetails entry
        $paymentMode = null;
        $nestedTransactionId = null;
        $paymentDetails = $data['paymentDetails'] ?? [];
        if (!empty($paymentDetails) && is_array($paymentDetails)) {
            $paymentMode = $paymentDetails[0]['paymentMode'] ?? null;
            $nestedTransactionId = $paymentDetails[0]['transactionId'] ?? null;
        }

        $attrs = [
            'status' => $appStatus,
            'transaction_id' => $nestedTransactionId ?? $data['transactionId'] ?? $data['transactionID'] ?? null,
            'phonepe_order_id' => $data['orderId'] ?? $data['orderID'] ?? null,
            'payment_response' => $statusResponse,
            'payment_mode' => $paymentMode,
            'last_synced_at' => now(),
        ];

        // Set paid_at only when payment is completed for the first time
        if ($appStatus === PaymentStatus::COMPLETED->value) {
            $attrs['paid_at'] = now();
        }

        return $attrs;
    }

    /**
     * Validate PhonePe webhook signature.
     *
     * V1 sends: X-Verify header = SHA256(base64Response + saltKey) + "###" + saltIndex
     * V2 sends: X-Verify header = HMAC-SHA256(rawBody, webhookSecret)
     */
    public function validateWebhookSignature(Request $request): bool
    {
        $receivedSignature = $request->header('X-Verify');

        if (empty($receivedSignature)) {
            Log::warning('PhonePe webhook: X-Verify header missing');
            return false;
        }

        if ($this->isV2()) {
            if (empty($this->webhookSecret)) {
                Log::warning('PhonePe V2 webhook secret not configured — skipping signature validation');
                return true;
            }
            $rawBody = $request->getContent();
            $expectedSignature = hash_hmac('sha256', $rawBody, $this->webhookSecret);
            return hash_equals($expectedSignature, $receivedSignature);
        } else {
            // V1 Validation using Salt Key
            // PhonePe V1 sends signature on the 'response' field (base64 encoded payload)
            $base64Response = $request->input('response');

            if (empty($base64Response)) {
                Log::warning('PhonePe V1 webhook: response field missing');
                return false;
            }

            $saltKey = $this->clientSecret;
            $saltIndex = $this->clientVersion;
            $expectedSignature = hash('sha256', $base64Response . $saltKey) . '###' . $saltIndex;

            return hash_equals($expectedSignature, $receivedSignature);
        }
    }

    /**
     * Calculate X-VERIFY header for V1 API.
     * Formula: SHA256(payload + endpoint + saltKey) + "###" + saltIndex
     */
    private function calculateV1Header(string $payload, string $endpoint): string
    {
        $saltKey = $this->clientSecret;
        $saltIndex = $this->clientVersion;

        return hash('sha256', $payload . $endpoint . $saltKey) . '###' . $saltIndex;
    }

    /**
     * Map PhonePe status strings to application PaymentStatus enum values.
     */
    private function mapPhonepeStatusToAppStatus(?string $phonepeStatus): string
    {
        if (!$phonepeStatus) {
            return PaymentStatus::FAILED->value;
        }

        return match (strtoupper($phonepeStatus)) {
            'COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS' => PaymentStatus::COMPLETED->value,
            'PENDING' => PaymentStatus::PENDING->value,
            'FAILED', 'PAYMENT_ERROR', 'PAYMENT_DECLINED', 'BAD_REQUEST', 'TIMED_OUT' => PaymentStatus::FAILED->value,
            'INITIATED' => PaymentStatus::INITIATED->value,
            default => PaymentStatus::FAILED->value,
        };
    }
}
