<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PortalUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePortalUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();

        if ($user instanceof PortalUser && ! $user->is_active) {
            Auth::guard('portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'This portal account is no longer active.');
        }

        return $next($request);
    }
}
