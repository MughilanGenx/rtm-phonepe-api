<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhonepeServices
{
    private string $clientId;

    private string $clientSecret;

    private string $baseUrl;

    private string $clientVersion;

    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        $this->clientId = config('services.phonepe.client_id');
        $this->clientSecret = config('services.phonepe.client_secret');
        $this->clientVersion = config('services.phonepe.client_version', '1');
        $this->baseUrl = config('services.phonepe.base_url');
    }

    public function getAccessToken(): string
    {

        return Cache::remember('phonepe_access_token', 600, function () {
            $authUrl = config('services.phonepe.env') === 'production'
               ? 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token'
               : 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token';

            $payload = [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'client_version' => $this->clientVersion,
                'grant_type' => 'client_credentials',
            ];

            Log::info('PhonePe Auth Request:', [
                'url' => $authUrl,
                'client_id' => $this->clientId,
                'client_version' => $this->clientVersion,
                'grant_type' => 'client_credentials',
            ]);

            $response = Http::asForm()
                ->withoutVerifying()  // Disable SSL verification for sandbox
                ->post($authUrl, $payload);

            Log::info('PhonePe Auth Response:', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (! $response->successful()) {
                throw new \Exception('PhonePe Auth Failed: '.$response->body());
            }

            return $response->json()['access_token'];

        });
    }

    public function initiatePayment(array $data): array
    {
        $token = $this->getAccessToken();

        $payload = [
            'merchantOrderId' => $data['merchant_order_id'],
            'amount' => (int) ($data['amount'] * 100), // Convert to paise
            'expireAfter' => 1200, // 20 minutes
            'metaInfo' => [
                'udf1' => $data['name'],
                'udf2' => $data['email'],
                'udf3' => $data['phone'],
            ],
            'paymentFlow' => [
                'type' => 'PG_CHECKOUT',
                'message' => 'Payment for Order '.$data['merchant_order_id'],
                'merchantUrls' => [
                    'redirectUrl' => url('/pay/'.$data['merchant_order_id']),
                ],
            ],
        ];

        Log::info('PhonePe Initiate Payment Request:', [
            'url' => $this->baseUrl.'/checkout/v2/pay',
            'payload' => $payload,
        ]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer '.$token,
            'Content-Type' => 'application/json',
        ])
            ->withoutVerifying()  // Disable SSL verification for sandbox
            ->post($this->baseUrl.'/checkout/v2/pay', $payload);

        Log::info('PhonePe Initiate Payment Response:', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (! $response->successful()) {
            throw new \Exception('PhonePe Payment Initiation Failed: '.$response->body());
        }

        $json = $response->json();

        // PhonePe v2 API may return redirectUrl nested inside 'data' — normalize to root level
        if (! isset($json['redirectUrl']) && isset($json['data']['redirectUrl'])) {
            $json['redirectUrl'] = $json['data']['redirectUrl'];
        }

        return $json;
    }

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

    public function extractMerchantOrderId(array $payload): ?string {}

    public function attributesFromStatusResponse(array $statusResponse): array {}

    public function checkStatus(string $merchantOrderId, bool $withDetails = true): array
    {
        $token = $this->getAccessToken();

        $url = $this->baseUrl.'/checkout/v2/order/'.$merchantOrderId.'/status';

        $query = $withDetails ? ['details' => 'true'] : [];

        Log::info('PhonePe Check Status Request:', ['url' => $url, 'query' => $query]);

        $response = Http::withHeaders([
            'Authorization' => 'O-Bearer '.$token,
            'Content-Type' => 'application/json',
        ])
            ->withoutVerifying()  // Disable SSL verification for sandbox
            ->get($url, $query);

        Log::info('PhonePe Check Status Response:', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (! $response->successful()) {
            throw new \Exception('PhonePe Status Check Failed: '.$response->body());
        }

        $body = (string) $response->body();
        if ($response->status() === 204 || trim($body) === '') {
            throw new \Exception(
                'PhonePe returned an empty order response (HTTP '.$response->status().'). '.
                'Check that PHONEPE_ENV matches where the payment was created (sandbox vs production), '.
                'and that the Merchant Order ID is exactly the one used when the payment link was issued.'
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \Exception('PhonePe Status Check: invalid JSON body');
        }

        return $this->normalizeOrderStatusResponse($json);
    }

    public function normalizeOrderStatusResponse(array $json): array
    {
        if (array_key_exists('success', $json) && $json['success'] === false) {
            $msg = $json['message'] ?? $json['code'] ?? 'Unknown error';

            throw new \Exception('PhonePe Order Status: '.$msg);
        }

        if (($json['success'] ?? null) === true && isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }
}
