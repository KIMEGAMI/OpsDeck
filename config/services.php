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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('MAIL_PASSWORD'),
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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => [
            'https://www.googleapis.com/auth/drive.metadata.readonly',
            'https://www.googleapis.com/auth/spreadsheets.readonly',
        ],
        'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_endpoint' => 'https://oauth2.googleapis.com/token',
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'prices' => [
            'premium' => env('STRIPE_PRICE_PREMIUM', env('STRIPE_PRICE_ID')),
            'storage_addon_gib' => env('STRIPE_PRICE_STORAGE_ADDON_GIB'),
        ],
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'signature_tolerance_seconds' => (int) env('STRIPE_SIGNATURE_TOLERANCE_SECONDS', 300),
        'connect_timeout_seconds' => max(1, (int) env('STRIPE_CONNECT_TIMEOUT_SECONDS', 3)),
        'timeout_seconds' => max(1, (int) env('STRIPE_TIMEOUT_SECONDS', 10)),
        'webhook_lock_seconds' => max(
            max(1, (int) env('STRIPE_CONNECT_TIMEOUT_SECONDS', 3)) + max(1, (int) env('STRIPE_TIMEOUT_SECONDS', 10)),
            max(1, (int) env('STRIPE_WEBHOOK_LOCK_SECONDS', 30)),
        ),
        'webhook_lock_wait_seconds' => max(1, (int) env('STRIPE_WEBHOOK_LOCK_WAIT_SECONDS', 15)),
        'webhook_job_tries' => max(1, (int) env('STRIPE_WEBHOOK_JOB_TRIES', 5)),
        'webhook_job_timeout_seconds' => max(1, (int) env('STRIPE_WEBHOOK_JOB_TIMEOUT_SECONDS', 30)),
        'webhook_job_unique_seconds' => max(1, (int) env('STRIPE_WEBHOOK_JOB_UNIQUE_SECONDS', 900)),
        'webhook_job_backoff_seconds' => array_values(array_filter(array_map(
            static fn (string $seconds): int => max(1, (int) trim($seconds)),
            explode(',', (string) env('STRIPE_WEBHOOK_JOB_BACKOFF_SECONDS', '5,15,60')),
        ))),
    ],

];
