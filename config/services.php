<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'payment_gateway' => [
        'driver' => env('PAYMENT_GATEWAY_DRIVER', 'midtrans'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'signature_secret_key' => env('MIDTRANS_SIGNATURE_SECRET_KEY'),
        'snap_base_url' => env('MIDTRANS_SNAP_BASE_URL', 'https://app.sandbox.midtrans.com'),
        'api_base_url' => env('MIDTRANS_API_BASE_URL', 'https://api.sandbox.midtrans.com'),
        'expiry_hours' => (int) env('MIDTRANS_EXPIRY_HOURS', 24),
        'connect_timeout' => (int) env('MIDTRANS_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('MIDTRANS_TIMEOUT', 10),
    ],

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

];
