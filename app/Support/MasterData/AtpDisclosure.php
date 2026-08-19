<?php

namespace App\Support\MasterData;

/**
 * The one wording every ATP surface borrows.
 *
 * Readiness is computed from the FDA WDD/3PL report — which registrants self-report and
 * the FDA republishes without adjudicating — plus licences typed in by hand. Neither is
 * an FDA approval, and a facility leaving the report means the FDA stopped listing it,
 * not that a state revoked it. "Ready" is therefore a record check, not a verification,
 * and nothing in the product may say the FDA verified a partner.
 */
final class AtpDisclosure
{
    /**
     * Full caveat for readiness panels and licence tables, where there is room to say
     * what to do about it.
     */
    public const SOURCE = 'Based on the FDA WDD/3PL license listing (self-reported by registrants) and licenses entered here — not FDA approval or proof of licensure. Confirm with the state board before onboarding a new partner.';

    /** Same claim, sized for a blocker line or notification body. */
    public const SHORT = 'Based on self-reported FDA listing data and hand-entered licenses, not FDA verification.';
}
