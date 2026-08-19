<?php

namespace App\Support\Auth;

use Jeffgreco13\FilamentBreezy\BreezyCore;

/**
 * Resolve WebAuthn RP ID from the current host (multi-tenant domains).
 */
final class TracepharmaBreezyCore extends BreezyCore
{
    public function passkeyRelyingPartyId(): string
    {
        $host = request()->getHost();

        return filled($host) ? $host : parent::passkeyRelyingPartyId();
    }
}
