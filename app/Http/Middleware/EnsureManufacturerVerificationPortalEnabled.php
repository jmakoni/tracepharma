<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureManufacturerVerificationPortalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        // Settings + supportsVrsResponder() (composed inside portal helper).
        if (! TenantFeatures::forTenant(tenant())->supportsManufacturerVerificationPortal()) {
            abort(404);
        }

        return $next($request);
    }
}
