<?php

use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Handheld\AssetLinksController;
use App\Http\Controllers\Handheld\ChooserController;
use App\Http\Controllers\Handheld\WebAppManifestController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/.well-known/assetlinks.json', AssetLinksController::class)
        ->name('handheld.assetlinks');
    Route::get('/manifest.webmanifest', WebAppManifestController::class)
        ->name('handheld.manifest');
    Route::get('/handheld', [ChooserController::class, 'show'])
        ->name('handheld.chooser');
    Route::post('/handheld', [ChooserController::class, 'open'])
        ->middleware('throttle:30,1')
        ->name('handheld.chooser.open');
});

/*
| Central hosts only. Tenant UI is the Filament App panel (path `/`).
| Admin UI is domain-bound to ADMIN_DOMAIN — do not redirect that host to itself.
| Marketing owns `/` on CENTRAL_DOMAIN / MARKETING_DOMAIN.
*/
$adminDomain = config('tracepharma.admin_domain');
$chooserDomain = config('tracepharma.floor_chooser_domain');
$marketingHosts = array_values(array_unique(array_filter([
    config('tracepharma.marketing_domain'),
    config('tracepharma.central_domain'),
])));

if (is_string($adminDomain) && $adminDomain !== '') {
    Route::domain($adminDomain)->middleware('web')->group(function () {
        Route::get('/auth/oidc/redirect', [OidcController::class, 'redirectAdmin'])
            ->middleware(['throttle:20,1'])
            ->name('admin.oidc.redirect');

        Route::get('/auth/oidc/callback', [OidcController::class, 'callbackAdmin'])
            ->middleware(['throttle:20,1'])
            ->name('admin.oidc.callback');
    });
}

if (is_string($chooserDomain) && $chooserDomain !== '' && $chooserDomain !== $adminDomain) {
    Route::domain($chooserDomain)->middleware('web')->group(function () {
        Route::redirect('/', '/handheld');
    });
}

foreach (array_filter(config('tenancy.central_domains', [])) as $domain) {
    if ($domain === $adminDomain || in_array($domain, $marketingHosts, true) || $domain === $chooserDomain) {
        continue;
    }

    Route::domain($domain)->group(function () use ($adminDomain) {
        Route::redirect('/', 'https://'.$adminDomain);
    });
}
