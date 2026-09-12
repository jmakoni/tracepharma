<?php

declare(strict_types=1);

namespace App\Http\Controllers\ClientPortal;

use App\Http\Controllers\Controller;
use App\Models\PortalUser;
use App\Services\Portal\ClientPortalAccess;
use App\Services\Portal\PortalOtpService;
use App\Support\Auth\AccountSecuritySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function showLogin(Request $request): View|RedirectResponse
    {
        if ($request->user('portal') instanceof PortalUser) {
            return $this->postLoginRedirect($request->user('portal'));
        }

        return view('client-portal.login');
    }

    public function requestOtp(Request $request, PortalOtpService $otp): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $otp->issue($validated['email']);

        $request->session()->put('portal_otp_email', strtolower(trim($validated['email'])));

        return redirect()
            ->route('tenant.client-portal.otp')
            ->with('status', 'We sent a one-time login code to your email.');
    }

    public function showOtp(Request $request): View|RedirectResponse
    {
        if ($request->user('portal') instanceof PortalUser) {
            return $this->postLoginRedirect($request->user('portal'));
        }

        $email = $request->session()->get('portal_otp_email');

        if (! is_string($email) || $email === '') {
            return redirect()->route('tenant.client-portal.login');
        }

        return view('client-portal.otp', ['email' => $email]);
    }

    public function verifyOtp(Request $request, PortalOtpService $otp, ClientPortalAccess $access): RedirectResponse
    {
        $email = $request->session()->get('portal_otp_email');

        if (! is_string($email) || $email === '') {
            return redirect()->route('tenant.client-portal.login');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $otp->verify($email, $validated['code']);

        Auth::guard('portal')->login($user);
        $request->session()->forget('portal_otp_email');
        $request->session()->regenerate();
        AccountSecuritySession::bind($user);

        return $this->postLoginRedirect($user, $access);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('tenant.client-portal.login');
    }

    public function pending(Request $request): View
    {
        return view('client-portal.pending', [
            'user' => $request->user('portal'),
        ]);
    }

    private function postLoginRedirect(PortalUser $user, ?ClientPortalAccess $access = null): RedirectResponse
    {
        $access ??= app(ClientPortalAccess::class);

        if (! $access->hasActiveOrganization($user)) {
            return redirect()->route('tenant.client-portal.pending');
        }

        return redirect()->route('tenant.client-portal.shipments.index');
    }
}
