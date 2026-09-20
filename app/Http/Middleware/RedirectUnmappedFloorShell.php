<?php

namespace App\Http\Middleware;

use App\Support\Floor\FloorRouteMap;
use App\Support\Floor\FloorShell;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone/tablet floor shell: unmapped desktop URLs go to /floor instead of rendering
 * resource lists with soft interstitial only.
 */
final class RedirectUnmappedFloorShell
{
    public const FLASH_KEY = 'tp_floor_desktop_only';

    public function handle(Request $request, Closure $next): Response
    {
        if (! auth()->check() || ! FloorShell::active($request)) {
            return $next($request);
        }

        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        if ($request->header('X-Livewire') !== null
            || $request->is('livewire/*')
            || $request->is('filament/*')
        ) {
            return $next($request);
        }

        $path = FloorRouteMap::normalizePath($request->path());

        if ($path === '/' || FloorRouteMap::isFloorPath($path)) {
            return $next($request);
        }

        if ($this->isAuthOrAccountPath($path)) {
            return $next($request);
        }

        if (FloorRouteMap::twinForPath($path) !== null) {
            return $next($request);
        }

        session()->flash(self::FLASH_KEY, true);

        return redirect()->to(FloorRouteMap::launcherUrl());
    }

    private function isAuthOrAccountPath(string $path): bool
    {
        return in_array($path, [
            '/login',
            '/logout',
            '/password-reset',
            '/password-reset/request',
            '/password-reset/reset',
            '/email-verification',
            '/email-verification/prompt',
            '/email-verification/verify',
            '/onboarding-wizard',
            '/legal-acceptance',
            '/force-password-change',
        ], true)
            || str_starts_with($path, '/password-reset/')
            || str_starts_with($path, '/email-verification/')
            || str_starts_with($path, '/user/')
            || str_starts_with($path, '/two-factor-');
    }
}
