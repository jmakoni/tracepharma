<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated Prefer EnsureAccountIsUsable:portal — kept as a thin alias for existing route references.
 */
final class EnsurePortalUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        return app(EnsureAccountIsUsable::class)->handle($request, $next, 'portal');
    }
}
