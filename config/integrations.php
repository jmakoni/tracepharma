<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WMS ship-confirm bridge
    |--------------------------------------------------------------------------
    |
    | Warehouse systems POST confirmed pick scans to
    | POST /api/webhooks/wms/{tenantId} with header X-Wms-Api-Key.
    |
    | Per-tenant keys are preferred (TenantSettings::wmsBridgeApiKey, encrypted
    | under settings.integrations.wms_bridge_api_key). When a tenant has no
    | dedicated key, WMS_BRIDGE_API_KEY is accepted as a lab-only fallback for
    | that tenant's webhook — production tenants should configure their own key.
    |
    */
    'wms' => [
        'api_key' => env('WMS_BRIDGE_API_KEY'),
        // Max confirmed scan URIs per receive-confirm POST; larger sessions chunk.
        'receive_confirm_max_scans' => max(1, (int) env('WMS_RECEIVE_CONFIRM_MAX_SCANS', 5000)),
    ],
];
