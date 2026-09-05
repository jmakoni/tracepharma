<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PortalUser;
use App\Services\Portal\ClientPortalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePortalUserHasOrganization
{
    public function __construct(
        private readonly ClientPortalAccess $access,
    ) {}

    /**
     * After auth:portal — users without an active org may only reach pending (and logout).
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('portal');

        if (! $user instanceof PortalUser) {
            return $next($request);
        }

        $hasOrg = $this->access->hasActiveOrganization($user);

        if (! $hasOrg) {
            if ($request->routeIs('tenant.client-portal.pending')) {
                return $next($request);
            }

            return redirect()->route('tenant.client-portal.pending');
        }

        if ($request->routeIs('tenant.client-portal.pending')) {
            return redirect()->route('tenant.client-portal.shipments.index');
        }

        return $next($request);
    }
}
