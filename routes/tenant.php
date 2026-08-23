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

use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\Labeling\ClientLabelPrintController;
use App\Http\Controllers\RecallBroadcastAckPortalController;
use App\Http\Controllers\SetCurrentSiteController;
use App\Http\Controllers\SupplierExceptionPortalController;
use App\Http\Controllers\SupplierQuarantineController;
use App\Http\Controllers\Tenant\ImpersonateController;
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
    Route::get('/impersonate/{token}', ImpersonateController::class)
        ->middleware(['throttle:10,1'])
        ->name('tenant.impersonate');

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

    Route::get('/supplier-quarantine/{shareUuid}', [SupplierQuarantineController::class, 'show'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.show');

    Route::post('/supplier-quarantine/{shareUuid}/comment', [SupplierQuarantineController::class, 'comment'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.comment');

    Route::post('/supplier-quarantine/{shareUuid}/upload', [SupplierQuarantineController::class, 'upload'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('tenant.supplier-quarantine.upload');
});
