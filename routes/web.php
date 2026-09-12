<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

/*
| Central hosts only. Tenant UI is the Filament App panel (path `/`).
| Admin UI is domain-bound to ADMIN_DOMAIN — do not redirect that host to itself.
| Marketing owns `/` on CENTRAL_DOMAIN / MARKETING_DOMAIN.
*/
$adminDomain = config('tracepharma.admin_domain');
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

foreach (array_filter(config('tenancy.central_domains', [])) as $domain) {
    if ($domain === $adminDomain || in_array($domain, $marketingHosts, true)) {
        continue;
    }

    Route::domain($domain)->group(function () use ($adminDomain) {
        Route::redirect('/', 'https://'.$adminDomain);
    });
}
