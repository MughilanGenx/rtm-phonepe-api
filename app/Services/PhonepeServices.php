<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonepeServices
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private string $clientVersion;
    private ?string $webhookSecret;

    public function __construct()
    {
        $this->clientId      = config('services.phonepe.client_id');
        $this->clientSecret  = config('services.phonepe.client_secret');
        $this->clientVersion = config('services.phonepe.client_version', '1');
        $this->baseUrl       = config('services.phonepe.base_url');
        $this->webhookSecret = config('services.phonepe.webhook_secret'); 
    }

    /**
     * Retrieve a cached OAuth access token from PhonePe.
     *
     * @throws \Exception if authentication fails
     */
    public function getAccessToken(): string
    {
        return Cache::remember('phonepe_access_token', 600, function () {
            $authUrl = config('services.phonepe.env') === 'production'
                ? 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token'
                : 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';

            $response = Http::asForm()
                ->withoutVerifying() // Disable SSL for sandbox only
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
     *
     * @param  array{merchant_order_id: string, amount: float, name: string, email: string, phone: string}  $data
     * @return array The PhonePe API response (normalized to root level)
     * @throws \Exception if the API call fails
     */
    public function initiatePayment(array $data): array
    {
        $token = $this->getAccessToken();

        $payload = [
            'merchantOrderId' => $data['merchant_order_id'],
            'amount'          => (int) ($data['amount'] * 100), // Paise
            'expireAfter'     => 1200, // 20 minutes
            'metaInfo'        => [
                'udf1' => $data['name'],
                'udf2' => $data['email'],
                'udf3' => $data['phone'],
            ],
            'paymentFlow' => [
                'type'    => 'PG_CHECKOUT',
                'message' => 'Payment for order ' . $data['merchant_order_id'],
                'merchantUrls' => [
                    // PhonePe will GET this URL when the user completes/cancels payment
                    'redirectUrl' => config('services.phonepe.redirect_url') . '?orderId=' . $data['merchant_order_id'],
                ],
            ],
        ];

        Log::info('PhonePe initiate payment', [
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
     * Query PhonePe for the current order status.
     *
     * @throws \Exception if the API call fails or returns empty body
     */
    public function checkStatus(string $merchantOrderId, bool $withDetails = true): array
    {
        $token = $this->getAccessToken();
        $url   = $this->baseUrl . '/checkout/v2/order/' . $merchantOrderId . '/status';
        $query = $withDetails ? ['details' => 'true'] : [];

        Log::info('PhonePe status check', ['merchant_order_id' => $merchantOrderId]);

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
            throw new \Exception(
                'PhonePe returned an empty response for order ' . $merchantOrderId . '. ' .
                'Ensure PHONEPE_ENV matches the environment where the payment was created.'
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \Exception('PhonePe status check: invalid JSON body');
        }

        return $this->normalizeOrderStatusResponse($json);
    }

    /**
     * Normalize PhonePe order status response — unwrap `data` wrapper if present.
     *
     * @throws \Exception if PhonePe signals a failure
     */
    public function normalizeOrderStatusResponse(array $json): array
    {
        if (array_key_exists('success', $json) && $json['success'] === false) {
            $msg = $json['message'] ?? $json['code'] ?? 'Unknown PhonePe error';
            throw new \Exception('PhonePe order status error: ' . $msg);
        }

        if (($json['success'] ?? null) === true && isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    /**
     * Decode a PhonePe base64-encoded `response` field if present.
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
     * PhonePe may return it in different locations depending on the event type.
     */
    public function extractMerchantOrderId(array $payload): ?string
    {
        return $payload['merchantOrderId']
            ?? $payload['data']['merchantOrderId']
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
        $appStatus     = $this->mapPhonepeStatusToAppStatus($phonepeStatus);

        Log::info('PhonePe status mapped', [
            'phonepe_status' => $phonepeStatus,
            'app_status'     => $appStatus,
        ]);

        $attrs = [
            'status'           => $appStatus,
            'transaction_id'   => $data['transactionId'] ?? $data['transactionID'] ?? null,
            'phonepe_order_id' => $data['orderId'] ?? $data['orderID'] ?? null,
            'payment_response' => $statusResponse,
            'last_synced_at'   => now(),
        ];

        // Set paid_at only when payment is completed for the first time
        if ($appStatus === PaymentStatus::COMPLETED->value) {
            $attrs['paid_at'] = now();
        }

        return $attrs;
    }

    /**
     * Validate PhonePe webhook HMAC-SHA256 signature.
     *
     * PhonePe sends: X-Verify header = SHA256(rawBody + webhookSecret)
     * Returns true if signature matches or if no webhook secret is configured (skip validation).
     */
    public function validateWebhookSignature(Request $request): bool
    {
        if (empty($this->webhookSecret)) {
            Log::warning('PhonePe webhook secret not configured — skipping signature validation');
            return true;
        }

        $receivedSignature = $request->header('X-Verify');

        if (empty($receivedSignature)) {
            Log::warning('PhonePe webhook: X-Verify header missing');
            return false;
        }

        $rawBody           = $request->getContent();
        $expectedSignature = hash_hmac('sha256', $rawBody, $this->webhookSecret);

        $valid = hash_equals($expectedSignature, $receivedSignature);

        if (! $valid) {
            Log::warning('PhonePe webhook signature mismatch', [
                'received' => $receivedSignature,
                'expected' => $expectedSignature,
            ]);
        }

        return $valid;
    }

    /**
     * Map PhonePe status strings to application PaymentStatus enum values.
     */
    private function mapPhonepeStatusToAppStatus(?string $phonepeStatus): string
    {
        if (! $phonepeStatus) {
            return PaymentStatus::ERROR->value;
        }

        Log::error($phonepeStatus);

        return match (strtoupper($phonepeStatus)) {
            'SUCCESS', 'COMPLETED', 'PAYMENT_SUCCESS' => PaymentStatus::COMPLETED->value,
            'PENDING', 'PAYMENT_PENDING'               => PaymentStatus::PENDING->value,
            'FAILED', 'PAYMENT_FAILED'                 => PaymentStatus::ERROR->value,
            'DECLINED', 'PAYMENT_DECLINED'             => PaymentStatus::DECLINED->value,
            'CANCELLED', 'PAYMENT_CANCELLED'           => PaymentStatus::CANCELLED->value,
            'INITIATED'                                 => PaymentStatus::INITIATED->value,
            default                                     => PaymentStatus::ERROR->value,
        };
    }
}
