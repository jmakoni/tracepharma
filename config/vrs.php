<?php

$appEnv = (string) env('APP_ENV', 'production');

// Non-production defaults to FakeVrsClient so Verify Product works locally/in tests
// without a real VRS. Production defaults to null (deferred) unless VRS_DRIVER is set.
$defaultDriver = $appEnv === 'production' ? 'null' : 'fake';

return [
    'driver' => env('VRS_DRIVER', $defaultDriver),

    'http' => [
        // Production HttpVrsClient — set VRS_BASE_URL and VRS_API_KEY when wiring a live VRS.
        'base_url' => env('VRS_BASE_URL', 'https://vrs.example.com'),
        'verify_path' => env('VRS_VERIFY_PATH', '/api/v1/verify'),
        'api_key' => env('VRS_API_KEY'),
        'timeout' => (int) env('VRS_TIMEOUT', 30),
        // Requestor GLN when no tenant GLN is available; tenant site GLN takes precedence.
        'requestor_gln' => env('VRS_REQUESTOR_GLN'),
    ],

    /*
    | Inbound partner verify requests (tenant-as-responder). Header: X-Vrs-Api-Key or Bearer.
    |
    | Per-tenant keys are preferred (TenantSettings::vrsResponderApiKey, encrypted under
    | settings.integrations.vrs_responder_api_key). When a tenant has no dedicated key,
    | VRS_RESPONDER_API_KEY is accepted as a lab-only fallback in non-production;
    | production tenants must configure their own key.
    */
    'responder' => [
        'api_key' => env('VRS_RESPONDER_API_KEY'),
    ],
];
