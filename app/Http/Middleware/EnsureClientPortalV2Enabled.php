<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantFeatures;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureClientPortalV2Enabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            TenantFeatures::forTenant(tenant())->supportsClientPortalV2(),
            404,
        );

        return $next($request);
    }
}
