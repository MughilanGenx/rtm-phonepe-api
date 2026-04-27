<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'phonepe' => [
        'client_id'      => env('PHONEPE_CLIENT_ID'), // If empty, the service uses Standard V1 (Merchant) API
        'client_secret'  => env('PHONEPE_CLIENT_SECRET'), // Used as OAuth secret in V2, or Salt Key in V1
        'client_version' => env('PHONEPE_CLIENT_VERSION', '1'), // Used as Salt Index in V1
        'merchant_id'    => env('PHONEPE_MERCHANT_ID'),
        'env'            => env('PHONEPE_ENV', 'sandbox'),
        'webhook_secret' => env('PHONEPE_WEBHOOK_SECRET'), // HMAC secret for V2 webhooks (optional for V1)

        'redirect_url'   => env('PHONEPE_REDIRECT_URL', env('FRONTEND_URL') . '/payment/status'),

        'base_url' => env('PHONEPE_ENV', 'sandbox') === 'production'
            ? 'https://api.phonepe.com/apis/pg' // V2 Production (Service will override for V1)
            : 'https://api-preprod.phonepe.com/apis/pg-sandbox', // Pre-prod (V1 & V2)
    ],

    'aisensy'=> [
        'aisensy_apikey'  => env('AISENSY_API_KEY'),
        'aisensy_campaign_id'  => env('AISENSY_CAMPAIGN_ID'),
        'aisensy_skip_ssl_verify'  => env('AISENSY_SKIP_SSL_VERIFY'),
        'aisensy_login_otp_campaign'  => env('AISENSY_LOGIN_OTP_CAMPAIGN'),
        'aisensy_login_otp_active'  => env('AISENSY_LOGIN_OTP_ACTIVE'),

    ],

];
