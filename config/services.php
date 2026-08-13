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
        // Stored as either "storage/app/<file>.json", just "<file>.json", or
        // an absolute path (e.g. Render's Secret Files, mounted at
        // "/etc/secrets/<file>.json") — normalize to an absolute path so it
        // resolves correctly regardless of the PHP process's current working
        // directory, not just when launched from the project root. Absolute
        // paths are already resolved and pass through untouched — running
        // them through base_path()/storage/app/ would mangle them.
        'credentials' => ($file = env('FIREBASE_CREDENTIALS_FILE'))
            ? (str_starts_with($file, '/')
                ? $file
                : base_path(str_starts_with($file, 'storage/') ? $file : 'storage/app/'.$file))
            : null,
        'storage_bucket' => env('FIREBASE_STORAGE_BUCKET'),
    ],

    'google_sheets' => [
        'credentials' => ($file = env('GOOGLE_SHEETS_CREDENTIALS_FILE'))
            ? (str_starts_with($file, '/')
                ? $file
                : base_path(str_starts_with($file, 'storage/') ? $file : 'storage/app/'.$file))
            : null,
        'application_name' => env('GOOGLE_SHEETS_APPLICATION_NAME', 'LRF App'),
        'spreadsheet_id' => env('GOOGLE_SPREADSHEET_ID'),
    ],

    'google_vision' => [
        // Reuses the same service account as google_sheets above (its
        // 'credentials' is already normalized to an absolute path) — no
        // separate credentials file needed, as long as that service account
        // also has the Vision API enabled in its GCP project.
        'enabled' => env('GOOGLE_VISION_ENABLED', false),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    // Which LLM the invoice-extraction pipeline (LlmVisionService) calls —
    // 'gemini' or 'anthropic'. Switch providers by changing this one value;
    // both implementations stay in the codebase.
    'vision_provider' => env('VISION_PROVIDER', 'gemini'),
];
