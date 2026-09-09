<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| OCI wallet HTTP adapter (ATP verifiable presentations)
|--------------------------------------------------------------------------
|
| TracePharma does not mint or host a wallet. These settings point at an
| external OCI-compatible Generate VP / Verify VP HTTP API. Tenant settings
| override these platform defaults when set.
|
| Live verify ≠ manual AtpVerificationSource::OciPartnerEvidence and ≠ FDA WDD.
*/

return [
    'driver' => env('ATP_OCI_DRIVER', 'http'), // http|fake

    'base_url' => env('ATP_OCI_WALLET_BASE_URL'),
    'api_key' => env('ATP_OCI_WALLET_API_KEY'),
    'verify_path' => env('ATP_OCI_VERIFY_PATH', '/api/v1/verify-vp'),
    'present_path' => env('ATP_OCI_PRESENT_PATH', '/api/v1/generate-vp'),
    'timeout' => (int) env('ATP_OCI_TIMEOUT', 15),
];
