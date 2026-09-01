<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Oidc\OidcAuthenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OidcController extends Controller
{
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
                ->withErrors(['email' => $e->getMessage() ?: 'SSO sign-in failed.']);
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
                ->withErrors(['email' => $e->getMessage() ?: 'SSO sign-in failed.']);
        }
    }
}
