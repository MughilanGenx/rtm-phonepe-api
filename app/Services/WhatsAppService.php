<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    private $apiKey;

    private $campaignId;

    private $clientUrl;

    public function __construct()
    {
        $this->apiKey = config('services.aisensy.aisensy_apikey');
        $this->campaignId = config('services.aisensy.aisensy_campaign_id');
        $this->clientUrl = 'https://backend.aisensy.com/campaign/t1/api/v2';
    }

    public function sendLinkForPayment($userDetails)
    {
        try {
            $url = $this->clientUrl;
            $data = [
                'apiKey' => $this->apiKey,
                'campaignName' => $this->campaignId,
                'destination' => '91'.$userDetails['mobile'],
                'userName' => $userDetails['name'],
                'templateParams' => [
                    $userDetails['name'],
                    $userDetails['amount'],
                    // $userDetails['link'],
                    $userDetails['order_id'],
                ],
            ];

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($url, $data);

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Error in sending WhatsApp message: ', [
                'Message' => $e->getMessage(),
                'File' => $e->getFile(),
                'Line' => $e->getLine(),
            ]);
            return false;
        }

    }
}
