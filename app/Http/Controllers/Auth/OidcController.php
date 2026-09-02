<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Oidc\OidcAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OidcController extends Controller
{
    private const SSO_FAILURE_MESSAGE = 'SSO sign-in failed. Please try again or contact your administrator.';

    public function redirectTenant(OidcAuthenticator $authenticator)
    {
        return $authenticator->redirectForTenant();
    }

    public function callbackTenant(Request $request, OidcAuthenticator $authenticator): RedirectResponse
    {
        try {
            return $authenticator->handleTenantCallback();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->to('/login')
                ->withErrors(['email' => self::SSO_FAILURE_MESSAGE]);
        }
    }

    public function redirectAdmin(OidcAuthenticator $authenticator)
    {
        return $authenticator->redirectForAdmin();
    }

    public function callbackAdmin(Request $request, OidcAuthenticator $authenticator): RedirectResponse
    {
        try {
            return $authenticator->handleAdminCallback();
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->to('/login')
                ->withErrors(['email' => self::SSO_FAILURE_MESSAGE]);
        }
    }
}
