<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Concerns\HasAccountSecurity;
use App\Support\Auth\AccountSecuritySession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAccountIsUsable
{
    public function handle(Request $request, Closure $next, string $guard = 'web'): Response
    {
        $user = Auth::guard($guard)->user();

        if ($user === null) {
            return $next($request);
        }

        $usesSecurity = in_array(HasAccountSecurity::class, class_uses_recursive($user), true);

        if (! $usesSecurity) {
            return $next($request);
        }

        $sessionOk = $guard === 'sanctum' || AccountSecuritySession::matches($user);

        if (! $user->isUsable() || ! $sessionOk) {
            $authGuard = Auth::guard($guard);
            if (method_exists($authGuard, 'logout')) {
                $authGuard->logout();
            } else {
                // Sanctum RequestGuard has no logout(); drop the resolved principal.
                Auth::forgetGuards();
            }

            AccountSecuritySession::clear();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = method_exists($user, 'authenticationFailureMessage')
                ? $user->authenticationFailureMessage()
                : 'This account is no longer available.';

            abort(403, $message);
        }

        return $next($request);
    }
}
