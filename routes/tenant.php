<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Non-Filament tenant HTTP routes go here. The Filament App panel owns `/`
| and authenticated tenant UI — do not register a competing GET `/`.
|
*/

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\ClientPortal\AuthController as ClientPortalAuthController;
use App\Http\Controllers\ClientPortal\ShipmentController as ClientPortalShipmentController;
use App\Http\Controllers\ClientPortal\TraceController as ClientPortalTraceController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\DataExportDownloadController;
use App\Http\Controllers\EpcisSubscriptionDownloadController;
use App\Http\Controllers\Labeling\ClientLabelPrintController;
use App\Http\Controllers\RecallBroadcastAckPortalController;
use App\Http\Controllers\SetCurrentSiteController;
use App\Http\Controllers\SupplierExceptionPortalController;
use App\Http\Controllers\SupplierQuarantineController;
use App\Http\Controllers\Tenant\ImpersonateController;
use App\Http\Controllers\VerificationRequestPortalController;
use App\Http\Middleware\EnsureClientPortalV2Enabled;
use App\Http\Middleware\EnsureManufacturerVerificationPortalEnabled;
use App\Http\Middleware\EnsurePortalUserHasOrganization;
use App\Http\Middleware\EnsurePortalUserIsActive;
use App\Http\Middleware\EnsureTenantIsActive;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
    EnsureTenantIsActive::class,
])->group(function () {
    Route::get('/auth/oidc/redirect', [OidcController::class, 'redirectTenant'])
        ->middleware(['throttle:20,1'])
        ->name('tenant.oidc.redirect');

    Route::get('/auth/oidc/callback', [OidcController::class, 'callbackTenant'])
        ->middleware(['throttle:20,1'])
        ->name('tenant.oidc.callback');

    Route::get('/impersonate/{publicId}/redeem', [ImpersonateController::class, 'show'])
        ->middleware(['throttle:10,1'])
        ->whereUuid('publicId')
        ->name('tenant.impersonate.redeem.show');

    Route::post('/impersonate/{publicId}/redeem', [ImpersonateController::class, 'redeem'])
        ->middleware(['throttle:10,1'])
        ->whereUuid('publicId')
        ->name('tenant.impersonate.redeem');

    Route::post('/current-site/{site}', SetCurrentSiteController::class)
        ->middleware(['auth', 'throttle:60,1'])
        ->whereNumber('site')
        ->name('tenant.current-site.set');

    Route::middleware(['auth', 'throttle:60,1'])->prefix('label-print')->group(function (): void {
        Route::get('/config', [ClientLabelPrintController::class, 'config'])
            ->name('tenant.label-print.config');
        Route::post('/bridge', [ClientLabelPrintController::class, 'setBridge'])
            ->name('tenant.label-print.bridge.set');
        Route::delete('/bridge', [ClientLabelPrintController::class, 'clearBridge'])
            ->name('tenant.label-print.bridge.clear');
        Route::get('/labels/{label}/zpl', [ClientLabelPrintController::class, 'zpl'])
            ->whereNumber('label')
            ->name('tenant.label-print.zpl');
        Route::post('/jobs/{printJob}/start', [ClientLabelPrintController::class, 'startJob'])
            ->whereNumber('printJob')
            ->name('tenant.label-print.job.start');
        Route::post('/jobs/{printJob}/assert', [ClientLabelPrintController::class, 'assertJob'])
            ->whereNumber('printJob')
            ->name('tenant.label-print.job.assert');
        Route::post('/jobs/{printJob}/complete', [ClientLabelPrintController::class, 'completeJob'])
            ->whereNumber('printJob')
            ->name('tenant.label-print.job.complete');
    });

    Route::get('/recall-broadcast-ack/{ackShareUuid}', [RecallBroadcastAckPortalController::class, 'show'])
        ->middleware(['throttle:20,1'])
        ->name('tenant.recall-broadcast-ack.show');

    Route::post('/recall-broadcast-ack/{ackShareUuid}', [RecallBroadcastAckPortalController::class, 'acknowledge'])
        ->middleware(['throttle:20,1'])
        ->name('tenant.recall-broadcast-ack.acknowledge');

    Route::get('/supplier-exceptions/{portalShareUuid}', [SupplierExceptionPortalController::class, 'index'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-exceptions.index');

    Route::get('/customer-portal/{customerPortalUuid}', [CustomerPortalController::class, 'index'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.customer-portal.index');

    Route::get('/customer-portal/{customerPortalUuid}/documents/{document}', [CustomerPortalController::class, 'download'])
        ->middleware(['signed', 'throttle:20,1'])
        ->whereNumber('document')
        ->name('tenant.customer-portal.download');

    Route::prefix('client-portal')
        ->middleware([EnsureClientPortalV2Enabled::class])
        ->name('tenant.client-portal.')
        ->group(function (): void {
            Route::get('/login', [ClientPortalAuthController::class, 'showLogin'])
                ->middleware(['throttle:30,1'])
                ->name('login');
            Route::post('/login', [ClientPortalAuthController::class, 'requestOtp'])
                ->middleware(['throttle:10,1'])
                ->name('login.request');
            Route::get('/otp', [ClientPortalAuthController::class, 'showOtp'])
                ->middleware(['throttle:30,1'])
                ->name('otp');
            Route::post('/otp', [ClientPortalAuthController::class, 'verifyOtp'])
                ->middleware(['throttle:20,1'])
                ->name('otp.verify');
            Route::post('/logout', [ClientPortalAuthController::class, 'logout'])
                ->middleware(['auth:portal', 'throttle:30,1'])
                ->name('logout');

            Route::middleware(['auth:portal', EnsurePortalUserIsActive::class, EnsurePortalUserHasOrganization::class])->group(function (): void {
                Route::get('/', fn () => redirect()->route('tenant.client-portal.shipments.index'))
                    ->name('home');
                Route::get('/pending', [ClientPortalAuthController::class, 'pending'])
                    ->name('pending');
                Route::get('/shipments', [ClientPortalShipmentController::class, 'index'])
                    ->middleware(['throttle:60,1'])
                    ->name('shipments.index');
                Route::get('/shipments/export', [ClientPortalShipmentController::class, 'export'])
                    ->middleware(['throttle:20,1'])
                    ->name('shipments.export');
                Route::get('/shipments/{document}/export', [ClientPortalShipmentController::class, 'exportDocument'])
                    ->middleware(['throttle:20,1'])
                    ->whereNumber('document')
                    ->name('shipments.export-document');
                Route::get('/shipments/{document}', [ClientPortalShipmentController::class, 'show'])
                    ->middleware(['throttle:60,1'])
                    ->whereNumber('document')
                    ->name('shipments.show');
                Route::get('/shipments/{document}/download', [ClientPortalShipmentController::class, 'download'])
                    ->middleware(['throttle:30,1'])
                    ->whereNumber('document')
                    ->name('shipments.download');
                Route::get('/shipments/{document}/track-trace', [ClientPortalShipmentController::class, 'downloadTrackTrace'])
                    ->middleware(['throttle:20,1'])
                    ->whereNumber('document')
                    ->name('shipments.track-trace');
                Route::post('/shipments/{document}/serialized-track-trace', [ClientPortalShipmentController::class, 'queueSerializedTrackTrace'])
                    ->middleware(['throttle:10,1'])
                    ->whereNumber('document')
                    ->name('shipments.serialized-track-trace');
                Route::get('/trace', [ClientPortalTraceController::class, 'index'])
                    ->middleware(['throttle:30,1'])
                    ->name('trace');
            });
        });

    Route::prefix('verification-request')
        ->middleware([EnsureManufacturerVerificationPortalEnabled::class])
        ->name('tenant.verification-request.')
        ->group(function (): void {
            Route::get('/{caseUuid}', [VerificationRequestPortalController::class, 'show'])
                ->middleware(['throttle:30,1'])
                ->name('show');
            Route::post('/{caseUuid}/unlock', [VerificationRequestPortalController::class, 'unlock'])
                ->middleware(['throttle:20,1'])
                ->name('unlock');
            Route::get('/{caseUuid}/respond', [VerificationRequestPortalController::class, 'respondForm'])
                ->middleware(['throttle:30,1'])
                ->name('respond');
            Route::post('/{caseUuid}/respond', [VerificationRequestPortalController::class, 'submit'])
                ->middleware(['throttle:20,1'])
                ->name('submit');
        });

    Route::get('/epcis-subscription/documents/{document}/epcis-2.0', EpcisSubscriptionDownloadController::class)
        ->middleware(['signed', 'throttle:60,1'])
        ->whereNumber('document')
        ->name('tenant.epcis-subscription.download');

    Route::get('/exports/{export}/download', DataExportDownloadController::class)
        ->middleware(['signed', 'throttle:60,1'])
        ->name('tenant.data-export.download');

    Route::get('/supplier-quarantine/{shareUuid}', [SupplierQuarantineController::class, 'show'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.show');

    Route::post('/supplier-quarantine/{shareUuid}/comment', [SupplierQuarantineController::class, 'comment'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.comment');

    Route::post('/supplier-quarantine/{shareUuid}/apply', [SupplierQuarantineController::class, 'apply'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.apply');

    Route::post('/supplier-quarantine/{shareUuid}/upload', [SupplierQuarantineController::class, 'upload'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.upload');
});
