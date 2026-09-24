<?php

use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\InitializeTenancyForTenantHosts;
use App\Support\Floor\FloorLayout;
use App\Support\Floor\FloorShell;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require base_path('routes/marketing.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run before StartSession so Livewire CSRF/session and Filament
        // panel logins both use the tenant connection (not central).
        $middleware->web(prepend: [
            InitializeTenancyForTenantHosts::class,
        ]);

        // Sanctum tokens live in the tenant DB — resolve tenancy from the host
        // before auth:sanctum on /api/* routes.
        $middleware->api(prepend: [
            InitializeTenancyForTenantHosts::class,
        ]);

        // Set by first-paint JS / Alpine layout toggle — must stay readable by PHP.
        $middleware->encryptCookies(except: [
            FloorShell::VIEWPORT_COOKIE,
            FloorLayout::COOKIE,
        ]);

        $middleware->redirectGuestsTo(function (Request $request): ?string {
            if ($request->is('client-portal') || $request->is('client-portal/*')) {
                return route('tenant.client-portal.login');
            }

            return null;
        });

        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
            'tenant.active' => EnsureTenantIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
