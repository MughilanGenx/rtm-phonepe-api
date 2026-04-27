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
        $this->clientId      = trim(config('services.phonepe.client_id') ?? '');
        $this->clientSecret  = config('services.phonepe.client_secret');
        $this->clientVersion = config('services.phonepe.client_version', '1');
        $this->baseUrl       = config('services.phonepe.base_url');
        $this->webhookSecret = config('services.phonepe.webhook_secret'); 
        $this->merchantId    = config('services.phonepe.merchant_id');

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
        return ! empty($this->clientId);
    }

    /**
     * Retrieve a cached OAuth access token from PhonePe (V2 Enterprise only).
     *
     * @throws \Exception if authentication fails
     */
    public function getAccessToken(): string
    {
        if (! $this->isV2()) {
            throw new \Exception('OAuth token is not required for PhonePe V1 (Standard) API.');
        }

        return Cache::remember('phonepe_access_token', 600, function () {
            $authUrl = config('services.phonepe.env') === 'production'
                ? 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token'
                : 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';

            $response = Http::asForm()
                ->post($authUrl, [
                    'client_id'      => $this->clientId,
                    'client_secret'  => $this->clientSecret,
                    'client_version' => $this->clientVersion,
                    'grant_type'     => 'client_credentials',
                ]);

            Log::info('PhonePe auth response', ['http_status' => $response->status()]);

            if (! $response->successful()) {
                throw new \Exception('PhonePe authentication failed: ' . $response->body());
            }

            return $response->json()['access_token'];
        });
    }

    /**
     * Initiate a PhonePe checkout payment.
     * Supports both V1 (Standard) and V2 (Enterprise) based on configuration.
     */
    public function initiatePayment(array $data): array
    {
        if (! $this->isV2()) {
            return $this->initiatePaymentV1($data);
        }

        $token = $this->getAccessToken();

        $payload = [
            'merchantOrderId' => $data['merchant_order_id'],
            'amount'          => (int) ($data['amount'] * 100), // Paise
            'expireAfter'     => 1200, // 20 minutes
            'metaInfo'        => [
                'udf1' => $data['name'],
                'udf2' => $data['phone'],
            ],
            'paymentFlow' => [
                'type'    => 'PG_CHECKOUT',
                'message' => 'Payment for order ' . $data['merchant_order_id'],
                'merchantUrls' => [
                    'redirectUrl' => config('services.phonepe.redirect_url') . '?orderId=' . $data['merchant_order_id'],
                ],
            ],
        ];

        Log::info('PhonePe V2 initiate payment', [
            'merchant_order_id' => $data['merchant_order_id'],
            'amount_paise'      => $payload['amount'],
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])
            ->withoutVerifying()
            ->post($this->baseUrl . '/checkout/v2/pay', $payload);

        Log::info('PhonePe initiate response', [
            'merchant_order_id' => $data['merchant_order_id'],
            'http_status'       => $response->status(),
        ]);

        if (! $response->successful()) {
            throw new \Exception('PhonePe payment initiation failed: ' . $response->body());
        }

        $json = $response->json();

        // Normalize: PhonePe v2 may nest redirectUrl inside data
        if (! isset($json['redirectUrl']) && isset($json['data']['redirectUrl'])) {
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
            'merchantId'            => $this->merchantId,
            'merchantTransactionId' => $merchantTransactionId,
            'merchantUserId'        => 'USER_' . uniqid(),
            'amount'                => (int) ($data['amount'] * 100),
            'redirectUrl'           => config('services.phonepe.redirect_url') . '?orderId=' . $merchantTransactionId,
            'redirectMode'          => 'REDIRECT',
            'callbackUrl'           => url('/api/webhook/phonepe'),
            'mobileNumber'          => $data['phone'] ?? null,
            'paymentInstrument'     => [
                'type' => 'PAY_PAGE',
            ],
        ];

        $base64Payload = base64_encode(json_encode($payload));
        $xVerify = $this->calculateV1Header($base64Payload, '/pg/v1/pay');

        Log::info('PhonePe V1 initiate payment', [
            'merchant_order_id' => $merchantTransactionId,
            'amount_paise'      => $payload['amount'],
        ]);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-VERIFY'     => $xVerify,
        ])
        ->withoutVerifying()
        ->post($this->baseUrl . '/pg/v1/pay', [
            'request' => $base64Payload,
        ]);

        if (! $response->successful()) {
            throw new \Exception('PhonePe V1 payment initiation failed: ' . $response->body());
        }

        $json = $response->json();
        
        return [
            'success'     => $json['success'] ?? false,
            'redirectUrl' => $json['data']['instrumentResponse']['redirectInfo']['url'] ?? null,
            'data'        => $json['data'] ?? [],
        ];
    }

    /**
     * Query PhonePe for the current order status.
     */
    public function checkStatus(string $merchantOrderId, bool $withDetails = true): array
    {
        if (! $this->isV2()) {
            return $this->checkStatusV1($merchantOrderId);
        }

        $token = $this->getAccessToken();
        $url   = $this->baseUrl . '/checkout/v2/order/' . $merchantOrderId . '/status';
        $query = $withDetails ? ['details' => 'true'] : [];

        Log::info('PhonePe V2 status check', ['merchant_order_id' => $merchantOrderId]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])
            ->withoutVerifying()
            ->get($url, $query);

        Log::info('PhonePe status response', [
            'merchant_order_id' => $merchantOrderId,
            'http_status'       => $response->status(),
        ]);

        if (! $response->successful()) {
            throw new \Exception('PhonePe status check failed: ' . $response->body());
        }

        $body = (string) $response->body();
        if ($response->status() === 204 || trim($body) === '') {
            throw new \Exception('PhonePe returned an empty response for order ' . $merchantOrderId);
        }

        $json = $response->json();
        if (! is_array($json)) {
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
        $xVerify = $this->calculateV1Header('', $endpoint); 

        Log::info('PhonePe V1 status check', ['merchant_order_id' => $merchantTransactionId]);

        $response = Http::withHeaders([
            'Content-Type'  => 'application/json',
            'X-VERIFY'      => $xVerify,
            'X-MERCHANT-ID' => $this->merchantId,
        ])
        ->withoutVerifying()
        ->get($this->baseUrl . $endpoint);

        if (! $response->successful()) {
            throw new \Exception('PhonePe V1 status check failed: ' . $response->body());
        }

        $json = $response->json();
        return $this->normalizeOrderStatusResponse($json);
    }

    /**
     * Normalize PhonePe order status response.
     */
    public function normalizeOrderStatusResponse(array $json): array
    {
        $code = $json['code'] ?? null;
        $isPaymentFailure = in_array($code, ['PAYMENT_ERROR', 'PAYMENT_DECLINED', 'TIMED_OUT']);

        if (array_key_exists('success', $json) && $json['success'] === false && ! $isPaymentFailure) {
            $msg = $json['message'] ?? $json['code'] ?? 'Unknown PhonePe error';
            throw new \Exception('PhonePe order status error: ' . $msg);
        }

        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /**
     * Decode a PhonePe base64-encoded `response` field.
     */
    public function decodeResponsePayload(array $payload): array
    {
        if (! isset($payload['response']) || ! is_string($payload['response'])) {
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
     */
    public function extractMerchantOrderId(array $payload): ?string
    {
        return $payload['merchantOrderId']
            ?? $payload['data']['merchantOrderId']
            ?? $payload['merchantTransactionId'] 
            ?? $payload['data']['merchantTransactionId'] 
            ?? $payload['transactionId']
            ?? $payload['data']['transactionId']
            ?? null;
    }

    /**
     * Extract and map PhonePe status response fields to Payment model attributes.
     */
    public function attributesFromStatusResponse(array $statusResponse): array
    {
        $data = isset($statusResponse['data']) && is_array($statusResponse['data'])
            ? $statusResponse['data']
            : $statusResponse;

        $phonepeStatus = $data['state'] ?? $data['status'] ?? $data['code'] ?? null;
        $appStatus     = $this->mapPhonepeStatusToAppStatus($phonepeStatus);

        $paymentMode = null;
        $nestedTransactionId = null;
        $paymentDetails = $data['paymentDetails'] ?? [];
        if (! empty($paymentDetails) && is_array($paymentDetails)) {
            $paymentMode = $paymentDetails[0]['paymentMode'] ?? null;
            $nestedTransactionId = $paymentDetails[0]['transactionId'] ?? null;
        }

        $attrs = [
            'status'           => $appStatus,
            'transaction_id'   => $nestedTransactionId ?? $data['transactionId'] ?? $data['transactionID'] ?? null,
            'phonepe_order_id' => $data['orderId'] ?? $data['orderID'] ?? null,
            'payment_response' => $statusResponse,
            'payment_mode'     => $paymentMode,
            'last_synced_at'   => now(),
        ];

        if ($appStatus === PaymentStatus::COMPLETED->value) {
            $attrs['paid_at'] = now();
        }

        return $attrs;
    }

    /**
     * Validate PhonePe webhook signature.
     */
    public function validateWebhookSignature(Request $request): bool
    {
        $receivedSignature = $request->header('X-Verify');
        if (empty($receivedSignature)) {
            return false;
        }

        if ($this->isV2()) {
            if (empty($this->webhookSecret)) return true;
            $expectedSignature = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);
            return hash_equals($expectedSignature, $receivedSignature);
        } else {
            $base64Response = $request->input('response');
            if (empty($base64Response)) return false;
            $expectedSignature = hash('sha256', $base64Response . $this->clientSecret) . '###' . $this->clientVersion;
            return hash_equals($expectedSignature, $receivedSignature);
        }
    }

    /**
     * Calculate X-VERIFY header for V1 API.
     */
    private function calculateV1Header(string $payload, string $endpoint): string
    {
        return hash('sha256', $payload . $endpoint . $this->clientSecret) . '###' . $this->clientVersion;
    }

    /**
     * Map PhonePe status strings to application PaymentStatus enum values.
     */
    private function mapPhonepeStatusToAppStatus(?string $phonepeStatus): string
    {
        if (! $phonepeStatus) return PaymentStatus::FAILED->value;

        return match (strtoupper($phonepeStatus)) {
            'COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS' => PaymentStatus::COMPLETED->value,
            'PENDING'                                 => PaymentStatus::PENDING->value,
            'FAILED', 'PAYMENT_ERROR', 'PAYMENT_DECLINED', 'BAD_REQUEST', 'TIMED_OUT' => PaymentStatus::FAILED->value,
            'INITIATED'                               => PaymentStatus::INITIATED->value,
            default                                   => PaymentStatus::FAILED->value,
        };
    }
}
