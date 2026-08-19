<?php

namespace App\Support\Labeling;

use App\Enums\ClientPrintBridge;
use App\Support\TenantSettings;
use Illuminate\Support\Facades\Session;

/**
 * Resolve the active label-print bridge: session (user) override, then tenant default.
 */
final class ResolveClientPrintBridge
{
    public const SESSION_KEY = 'client_print_bridge';

    public function handle(?ClientPrintBridge $override = null): ClientPrintBridge
    {
        if ($override !== null) {
            return $override;
        }

        $fromSession = ClientPrintBridge::tryFromMixed(Session::get(self::SESSION_KEY));
        if ($fromSession !== null) {
            return $fromSession;
        }

        return TenantSettings::forTenant(tenant())->clientPrintBridge();
    }

    public function setSessionOverride(?ClientPrintBridge $bridge): void
    {
        if ($bridge === null) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        Session::put(self::SESSION_KEY, $bridge->value);
    }
}
