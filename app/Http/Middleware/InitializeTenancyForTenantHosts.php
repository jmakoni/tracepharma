<?php

namespace App\Http\Middleware;

use App\Support\EpcisHub\EpcisHubPlatformConfig;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boot domain tenancy for tenant hosts so Livewire/auth hit the tenant DB.
 * Must be prepended to the web group (before StartSession) so CSRF sessions
 * match Filament panel middleware. Skips central domains (admin / platform)
 * and EPCIS hub hosts (stage/prod edges route centrally by receiver GLN).
 */
final class InitializeTenancyForTenantHosts
{
    public function __construct(
        private InitializeTenancyByDomain $initializeTenancyByDomain,
        private EpcisHubPlatformConfig $epcisHubPlatformConfig,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $central = config('tenancy.central_domains', []);

        if (in_array($host, $central, true)) {
            return $next($request);
        }

        if ($this->epcisHubPlatformConfig->environmentForHost($host) !== null) {
            return $next($request);
        }

        return $this->initializeTenancyByDomain->handle($request, $next);
    }
}
