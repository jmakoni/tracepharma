<?php

use App\Http\Controllers\Api\V1\DispenseCheckController;
use App\Http\Controllers\Api\V1\EpcisCaptureController;
use App\Http\Controllers\Api\V1\EpcisDocumentsController;
use App\Http\Controllers\Api\V1\EpcisEventsQueryController;
use App\Http\Controllers\Api\V1\EpcisGs1SubscriptionsController;
use App\Http\Controllers\Api\V1\EpcisInboundController;
use App\Http\Controllers\Api\V1\EpcisOutboundController;
use App\Http\Controllers\Api\V1\GuardianLotCloseController;
use App\Http\Controllers\Api\V1\WmsShipConfirmController;
use App\Http\Controllers\Webhooks\As2InboundWebhookController;
use App\Http\Controllers\Webhooks\As2MdnWebhookController;
use App\Http\Controllers\Webhooks\EpcisHubInboundWebhookController;
use App\Http\Controllers\Webhooks\EpcisInboundWebhookController;
use App\Http\Controllers\Webhooks\VrsResponderWebhookController;
use App\Http\Controllers\Webhooks\WmsShipConfirmWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:webhooks')->group(function (): void {
    Route::post('webhooks/epcis/hub/{provider}', [EpcisHubInboundWebhookController::class, 'handle'])
        ->name('webhooks.epcis.hub');

    Route::post('webhooks/epcis/{tenantId}/{connectionId}', [EpcisInboundWebhookController::class, 'handle'])
        ->name('webhooks.epcis');

    Route::post('webhooks/vrs/{tenantId}', [VrsResponderWebhookController::class, 'handle'])
        ->name('webhooks.vrs.responder');

    Route::post('webhooks/wms/{tenantId}', [WmsShipConfirmWebhookController::class, 'handle'])
        ->name('webhooks.wms.ship-confirm');

    Route::post('webhooks/as2/mdn/{tenantId}/{connectionId}', [As2MdnWebhookController::class, 'handle'])
        ->name('webhooks.as2.mdn');

    Route::post('webhooks/as2/{tenantId}/{connectionId}', [As2InboundWebhookController::class, 'handle'])
        ->name('webhooks.as2.inbound');
});

Route::middleware(['auth:sanctum', 'tenant.active', 'throttle:60,1'])->prefix('v1')->group(function (): void {
    Route::post('dispense-check', DispenseCheckController::class)
        ->middleware('abilities:vrs:dispense-check')
        ->name('api.v1.dispense-check');

    Route::post('wms/ship-confirm', WmsShipConfirmController::class)
        ->middleware('abilities:wms:ship-confirm')
        ->name('api.v1.wms.ship-confirm');

    Route::post('epcis/inbound', EpcisInboundController::class)
        ->middleware('abilities:epcis:upload')
        ->name('api.v1.epcis.inbound');

    Route::post('epcis/outbound', [EpcisOutboundController::class, 'store'])
        ->middleware('abilities:epcis:transmit')
        ->name('api.v1.epcis.outbound.store');

    Route::get('epcis/outbound/{document}', [EpcisOutboundController::class, 'show'])
        ->middleware('ability:epcis:view,epcis:transmit')
        ->name('api.v1.epcis.outbound.show');

    Route::get('epcis/documents', [EpcisDocumentsController::class, 'index'])
        ->middleware('abilities:epcis:view')
        ->name('api.v1.epcis.documents');

    Route::get('epcis/documents/{document}', [EpcisDocumentsController::class, 'show'])
        ->middleware('abilities:epcis:view')
        ->name('api.v1.epcis.documents.show');

    Route::get('epcis/documents/{document}/epcis-2.0', [EpcisDocumentsController::class, 'epcis20'])
        ->middleware('abilities:epcis:view')
        ->name('api.v1.epcis.documents.epcis20');

    Route::post('epcis/capture', [EpcisCaptureController::class, 'store'])
        ->middleware('abilities:epcis:upload')
        ->name('api.v1.epcis.capture.store');

    Route::get('epcis/capture/{captureId}', [EpcisCaptureController::class, 'show'])
        ->middleware('abilities:epcis:view')
        ->whereNumber('captureId')
        ->name('api.v1.epcis.capture.show');

    Route::get('epcis/events', [EpcisEventsQueryController::class, 'index'])
        ->middleware('abilities:epcis:view')
        ->name('api.v1.epcis.events.index');

    Route::get('epcis/events/{eventID}', [EpcisEventsQueryController::class, 'show'])
        ->middleware('abilities:epcis:view')
        ->where('eventID', '.*')
        ->name('api.v1.epcis.events.show');

    Route::get('epcis/subscriptions', [EpcisGs1SubscriptionsController::class, 'index'])
        ->middleware('abilities:epcis:subscriptions')
        ->name('api.v1.epcis.subscriptions.index');

    Route::post('epcis/subscriptions', [EpcisGs1SubscriptionsController::class, 'store'])
        ->middleware('abilities:epcis:subscriptions')
        ->name('api.v1.epcis.subscriptions.store');

    Route::delete('epcis/subscriptions/{subscriptionID}', [EpcisGs1SubscriptionsController::class, 'destroy'])
        ->middleware('abilities:epcis:subscriptions')
        ->name('api.v1.epcis.subscriptions.destroy');
});

// Guardian (Systech) lot-close inbound: L3 API key auth (not Sanctum). Tenant
// is resolved from the request host by InitializeTenancyForTenantHosts (api
// middleware prepend), same as the webhook group above.
Route::middleware('throttle:webhooks')->prefix('v1')->group(function (): void {
    Route::post('l3/guardian/lot-close', GuardianLotCloseController::class)
        ->name('api.v1.l3.guardian.lot-close');
});
