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

    'zeptomail' => [
        'mail_key' => env('ZEPTOMAIL_MAIL_KEY'),
        'endpoint' => env('ZEPTO_MAIL_ENDPOINT', 'https://api.zeptomail.com'),
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

    'nominatim' => [
        'url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'TracePharma/1.0 (asset-tracking; https://tracepharma.io)'),
    ],

    'places' => [
        // RapidAPI Local Business Data: https://local-business-data.p.rapidapi.com/search
        'base_url' => env('PLACES_API_BASE_URL', 'https://local-business-data.p.rapidapi.com/search'),
        'api_key' => env('PLACES_API_KEY'),
        'host' => env('PLACES_API_HOST', 'local-business-data.p.rapidapi.com'),
        'enabled' => filled(env('PLACES_API_KEY')),
        'region' => env('PLACES_API_REGION', 'us'),
        'language' => env('PLACES_API_LANGUAGE', 'en'),
        'limit' => (int) env('PLACES_API_LIMIT', 20),
        'zoom' => (int) env('PLACES_API_ZOOM', 13),
        'rate_per_minute' => (int) env('PLACES_API_RATE_PER_MINUTE', 30),
    ],

    /*
    | OIDC / Socialite drivers are configured at runtime from TenantSettings /
    | PlatformSettings by App\Services\Auth\Oidc\OidcSocialiteFactory.
    */
    'azure' => [
        'client_id' => env('AZURE_CLIENT_ID'),
        'client_secret' => env('AZURE_CLIENT_SECRET'),
        'redirect' => env('AZURE_REDIRECT_URI'),
        'tenant' => env('AZURE_TENANT_ID', 'common'),
    ],

    'okta' => [
        'client_id' => env('OKTA_CLIENT_ID'),
        'client_secret' => env('OKTA_CLIENT_SECRET'),
        'redirect' => env('OKTA_REDIRECT_URI'),
        'base_url' => env('OKTA_BASE_URL'),
    ],

    'generic-oidc' => [
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        'redirect' => env('OIDC_REDIRECT_URI'),
        'issuer' => env('OIDC_ISSUER'),
    ],
];
