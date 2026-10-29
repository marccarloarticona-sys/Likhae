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

    'psgc' => [
        'token' => env('PSGC_API_TOKEN'),
        'version' => env('PSGC_API_VERSION', 'Q2_2024'),
        'verify_ssl' => env('PSGC_VERIFY_SSL', true),
        'local_first' => env('PSGC_LOCAL_FIRST', true),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    // This browser-side key is visible in page source. Restrict it in Geoapify
    // to the production site's HTTP referrers and any local development origin.
    'geoapify' => [
        'key' => env('GEOAPIFY_API_KEY'),
    ],

    /* Server-only Workers AI credentials. Never expose these through Vite. */
    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'ai_token' => env('CLOUDFLARE_AI_API_TOKEN'),
    ],

    /* The key is intentionally server-only. Do not expose this value to Vite. */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // The account-visible Flash Lite alias is verified for generateContent.
        'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),
    ],

];
