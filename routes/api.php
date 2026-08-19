<?php

use App\Http\Controllers\Api\V1\DispenseCheckController;
use App\Http\Controllers\Api\V1\EpcisDocumentsController;
use App\Http\Controllers\Api\V1\EpcisInboundController;
use App\Http\Controllers\Api\V1\EpcisOutboundController;
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
});

Route::middleware(['auth:sanctum', 'tenant.active', 'throttle:60,1'])->prefix('v1')->group(function (): void {
    Route::post('dispense-check', DispenseCheckController::class)
        ->middleware('abilities:vrs:dispense-check')
        ->name('api.v1.dispense-check');

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
});
