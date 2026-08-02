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

    'firebase' => [
        'credentials' => storage_path('app/lrfsystem-98c20-firebase-adminsdk-fbsvc-7e61616c35.json'),
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
    ],

    'google_sheets' => [
        'credentials' => env('GOOGLE_SHEETS_CREDENTIALS_FILE'),
        'application_name' => env('GOOGLE_SHEETS_APPLICATION_NAME', 'LRF App'),
        'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID'),
    ],

    'google_vision' => [
        'enabled' => env('GOOGLE_VISION_ENABLED', false),
        'credentials' => env('GOOGLE_SHEETS_CREDENTIALS_FILE', storage_path('app/google-sheets.json')),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
    ],
];
