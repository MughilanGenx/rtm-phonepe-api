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
        'client_id'      => env('PHONEPE_CLIENT_ID'),
        'client_secret'  => env('PHONEPE_CLIENT_SECRET'),
        'client_version' => env('PHONEPE_CLIENT_VERSION', '1'),
        'merchant_id'    => env('PHONEPE_MERCHANT_ID'),
        'env'            => env('PHONEPE_ENV', 'sandbox'),
        'webhook_secret' => env('PHONEPE_WEBHOOK_SECRET'), // Used for HMAC signature validation

        'base_url' => env('PHONEPE_ENV', 'sandbox') === 'production'
            ? 'https://api.phonepe.com/apis/pg'
            : 'https://api-preprod.phonepe.com/apis/pg-sandbox',
    ],

];
