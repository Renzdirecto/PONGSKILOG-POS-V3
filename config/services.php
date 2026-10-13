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

    /*
    | Web Push (PWA Phase 1). One VAPID key pair per environment, generated with `php artisan pwa:vapid-keys`. The
    | subject is a contact the push services can reach (a `mailto:` address or an https URL). The private key stays
    | in the server environment: never committed, sent to a browser, logged or audited. Push is disabled until all
    | three are set.
    */
    'webpush' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
    | Shippy, the landing page chat (v1.3.0). Gemini is called only from the server; the key stays in the environment:
    | never committed, sent to a browser, logged or shown in Website → Shippy. Without a key, or past a limit below,
    | Shippy answers from its own rules (App\Support\ShippyKnowledge) and no other model or key is ever tried.
    | The two prices are the model's paid list price per million tokens and only feed the Owner's cost estimate:
    | change them together with the model.
    */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 8),
        'requests_per_minute' => (int) env('SHIPPY_REQUESTS_PER_MINUTE', 10),
        'requests_per_day' => (int) env('SHIPPY_REQUESTS_PER_DAY', 300),
        'input_usd_per_million' => (float) env('GEMINI_INPUT_USD_PER_MILLION', 0.25),
        'output_usd_per_million' => (float) env('GEMINI_OUTPUT_USD_PER_MILLION', 1.50),
    ],

];
