<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantAccess;
use App\Support\Tenancy\TenantKillSwitches;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (tenancy()->initialized) {
            TenantAccess::assertActive();

            if ($request->is('api/v1/*')) {
                TenantKillSwitches::forTenant()->assertNotKilled(TenantKillSwitches::SANCTUM_API);
            }
        }

        return $next($request);
    }
}
