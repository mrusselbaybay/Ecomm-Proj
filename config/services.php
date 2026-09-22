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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ✅ CORRECT: Single Supabase config with all keys
    'supabase' => [
        'url' => env('VITE_SUPABASE_URL'),
        'anon_key' => env('VITE_SUPABASE_ANON_KEY'),
        'service_role_key' => env('SUPABASE_SERVICE_ROLE_KEY'), // For backend operations
    ],

    'product_moderation' => [
        'enabled' => env('PRODUCT_MODERATION_ENABLED', false),
        'mode' => env('PRODUCT_MODERATION_MODE', 'review_only'),
        'url' => env('OPENAI_MODERATION_URL', 'https://api.openai.com/v1/moderations'),
        'token' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODERATION_MODEL', 'omni-moderation-latest'),
        'connect_timeout' => env('PRODUCT_MODERATION_CONNECT_TIMEOUT', 3),
        'timeout' => env('PRODUCT_MODERATION_TIMEOUT', 15),
    ],

];
